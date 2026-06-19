<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Extension\Content\ExtensionContentSchemaImpact;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Database\DatabaseReadyState;
use App\Entity\Extension;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class ExtensionPhpLoader implements EventSubscriberInterface
{
    /**
     * @var array<string, true>
     */
    private array $loadedExtensions = [];
    private ExtensionDependentDeactivator $dependentDeactivator;

    public function __construct(
        private readonly ActiveExtensionProviderInterface $extensionProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly WorkflowResultMessageReporterInterface $messageReporter,
        private readonly ?ExtensionAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private readonly string $environment = 'test',
        private readonly ?ExtensionRuntimeContributionRegistry $runtimeContributions = null,
        private readonly PathGuard $pathGuard = new PathGuard(),
        private readonly ?DatabaseReadyState $databaseReadyState = null,
        private readonly ?ExtensionContentSchemaImpact $contentSchemaImpact = null,
        ?ExtensionDependentDeactivator $dependentDeactivator = null,
    ) {
        $this->dependentDeactivator = $dependentDeactivator ?? new ExtensionDependentDeactivator($entityManager);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->loadActiveExtensions();
    }

    /**
     * @return WorkflowResult<array{loaded: list<string>, skipped: list<string>}>
     */
    public function loadActiveExtensions(): WorkflowResult
    {
        return $this->report($this->doLoadActiveExtensions());
    }

    /**
     * @return WorkflowResult<array{loaded: list<string>, skipped: list<string>}>
     */
    private function doLoadActiveExtensions(): WorkflowResult
    {
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return WorkflowResult::success(['loaded' => [], 'skipped' => []], ['database_ready' => false]);
        }

        try {
            $extensions = $this->extensionProvider->extensions();
        } catch (Throwable $error) {
            return WorkflowResult::failed([$this->exceptionIssue($error, ['stage' => 'active_extension_lookup'])]);
        }

        $loaded = [];
        $skipped = [];
        $issues = [];
        $messages = [];
        $dependentChanges = [];
        $assetRebuildNeeded = false;

        foreach ($extensions as $extension) {
            if (ExtensionStatus::Active !== $extension->status()) {
                $skipped[] = $extension->extensionName();
                continue;
            }

            if (isset($this->loadedExtensions[$extension->extensionName()])) {
                $skipped[] = $extension->extensionName();
                continue;
            }

            $loaderPath = $this->loaderPath($extension);

            if (null === $loaderPath || !is_file($loaderPath)) {
                $skipped[] = $extension->extensionName();
                continue;
            }

            try {
                $result = $this->includeLoader($loaderPath, $extension);

                if (is_callable($result)) {
                    $result = $result($extension);
                }

                $this->runtimeContributions?->add($extension, $result);

                $this->loadedExtensions[$extension->extensionName()] = true;
                $loaded[] = $extension->extensionName();
            } catch (Throwable $error) {
                $issue = $this->phpLoadIssue($extension, $loaderPath, $error);
                $issues[] = $issue;
                $messages[] = Message::exception(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ['%extension%' => $extension->extensionName()],
                    $issue->context(),
                );
                $fault = $this->markFaulty($extension, $loaderPath, $error);
                array_push($messages, ...$fault['messages']);
                array_push($dependentChanges, ...$fault['dependent_changes']);
                $assetRebuildNeeded = $assetRebuildNeeded || $fault['changed'] || [] !== $fault['dependent_changes'];
            }
        }

        $assetRebuild = $assetRebuildNeeded
            ? $this->assetRebuildDispatcher?->dispatch($this->environment, 'extension_php_loader_faulty')
            : null;

        $value = ['loaded' => $loaded, 'skipped' => $skipped];
        $context = ['loaded' => $loaded, 'skipped' => $skipped, 'failed' => array_map(
            static fn (Message $issue): array => $issue->context(),
            $issues,
        ), 'deactivated_dependents' => $dependentChanges, 'asset_rebuild' => $assetRebuild?->toArray()];

        if ([] !== $issues) {
            return WorkflowResult::failed($issues, $context, $messages);
        }

        return WorkflowResult::success($value, $context, $messages);
    }

    private function report(WorkflowResult $result): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'extension.php_load',
            'environment' => $this->environment,
        ]);
    }

    private function loaderPath(Extension $extension): ?string
    {
        try {
            return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($extension->path().'/extension.php');
        } catch (Throwable) {
            return null;
        }
    }

    private function includeLoader(string $loaderPath, Extension $extension): mixed
    {
        return (static function (string $loaderPath, Extension $extension): mixed {
            return require $loaderPath;
        })($loaderPath, $extension);
    }

    /**
     * @return array{changed: bool, dependent_changes: list<array{extension: string, action: string, status: string, dependency: string, reason: string}>, messages: list<Message>}
     */
    private function markFaulty(Extension $extension, string $loaderPath, Throwable $error): array
    {
        $changed = $extension->markFaulty($extension->path(), $extension->manifestVersion(), [
            ...$extension->metadata(),
            'registry_state' => 'faulty',
            'runtime_loader' => [
                'failed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'loader' => $this->projectRelativePath($loaderPath),
                'exception' => $error::class,
                'message' => $error->getMessage(),
                ...$this->messageExceptionContext($error),
            ],
        ]);
        $dependentChanges = [];
        $messages = [];

        if ($changed) {
            if (null !== $this->contentSchemaImpact) {
                array_push($messages, ...$this->contentSchemaImpact->archivePublicContentForExtensions([$extension])->messages());
            }

            $deactivation = $this->dependentDeactivator->deactivateActiveDependents($extension, 'extension_php_loader_fault');
            $dependentChanges = $deactivation['changes'];
            array_push($messages, ...$deactivation['messages']);
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable) {
        }

        return ['changed' => $changed, 'dependent_changes' => $dependentChanges, 'messages' => $messages];
    }

    private function phpLoadIssue(Extension $extension, string $loaderPath, Throwable $error): Message
    {
        return Message::create(
            ExtensionMessageCode::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
            ExtensionMessageKey::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
            ['%extension%' => $extension->extensionName()],
            [
                'extension' => $extension->extensionName(),
                'path' => $extension->path(),
                'loader' => $this->projectRelativePath($loaderPath),
                'exception' => $error::class,
                'message' => $error->getMessage(),
                ...$this->messageExceptionContext($error),
            ],
            MessageLevel::Exception,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function exceptionIssue(Throwable $error, array $context): Message
    {
        return Message::create(
            OperationMessageCode::OPERATION_EXCEPTION,
            OperationMessageKey::OPERATION_EXCEPTION,
            context: [
                ...$context,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ],
            level: MessageLevel::Exception,
        );
    }

    private function projectRelativePath(string $path): string
    {
        $projectDir = rtrim($this->projectDir, '/').'/';

        return str_starts_with($path, $projectDir) ? substr($path, strlen($projectDir)) : $path;
    }

    /**
     * @return array{previous_message?: array{code: string, key: string, parameters: array<string, mixed>, context: array<string, mixed>}}
     */
    private function messageExceptionContext(Throwable $error): array
    {
        if (!$error instanceof MessageException) {
            return [];
        }

        return [
            'previous_message' => [
                'code' => $error->code(),
                'key' => $error->messageKey(),
                'parameters' => $error->parameters(),
                'context' => $error->context(),
            ],
        ];
    }
}
