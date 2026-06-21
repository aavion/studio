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
    private ExtensionClassAutoloader $classAutoloader;

    public function __construct(
        private readonly ActiveExtensionProviderInterface $extensionProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly WorkflowResultMessageReporterInterface $messageReporter,
        private readonly ?ExtensionLifecycleAssetRebuilderInterface $assetRebuilder = null,
        private readonly string $environment = 'test',
        private readonly ?ExtensionRuntimeContributionRegistry $runtimeContributions = null,
        private readonly PathGuard $pathGuard = new PathGuard(),
        private readonly ?DatabaseReadyState $databaseReadyState = null,
        private readonly ?ExtensionContentSchemaImpact $contentSchemaImpact = null,
        ?ExtensionDependentDeactivator $dependentDeactivator = null,
        ?ExtensionClassAutoloader $classAutoloader = null,
        ?ExtensionRuntimeServices $extensionRuntimeServices = null,
    ) {
        $this->dependentDeactivator = $dependentDeactivator ?? new ExtensionDependentDeactivator($entityManager);
        $this->classAutoloader = $classAutoloader ?? new ExtensionClassAutoloader($projectDir, $pathGuard);
        if (null !== $extensionRuntimeServices) {
            ExtensionRuntime::configure($extensionRuntimeServices);
        }
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

        $this->classAutoloader->reset();

        foreach ($extensions as $extension) {
            if (ExtensionStatus::Active !== $extension->status()) {
                $skipped[] = $extension->extensionName();
                continue;
            }

            try {
                $this->classAutoloader->register($extension);
            } catch (Throwable $error) {
                $issue = $this->phpLoadIssue($extension, $extension->path().'/src', $error);
                $issues[] = $issue;
                $messages[] = Message::exception(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ['%extension%' => $extension->extensionName()],
                    $issue->context(),
                );
                $fault = $this->markFaulty($extension, $extension->path().'/src', $error);
                array_push($issues, ...$fault['issues']);
                array_push($messages, ...$fault['messages']);
                array_push($dependentChanges, ...$fault['dependent_changes']);
                $assetRebuildNeeded = $assetRebuildNeeded || ([] === $fault['issues'] && ($fault['changed'] || [] !== $fault['dependent_changes']));

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
                $runtime = $this->runtimeContributionsAndBoots($extension, $result);

                if (null !== $this->runtimeContributions) {
                    $this->runtimeContributions->addStaged(
                        $extension,
                        $runtime['contributions'],
                        fn (): null => $this->executeRuntimeBoots($extension, $runtime['boots']),
                    );
                } else {
                    $this->executeRuntimeBoots($extension, $runtime['boots']);
                }

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
                array_push($issues, ...$fault['issues']);
                array_push($messages, ...$fault['messages']);
                array_push($dependentChanges, ...$fault['dependent_changes']);
                $assetRebuildNeeded = $assetRebuildNeeded || ([] === $fault['issues'] && ($fault['changed'] || [] !== $fault['dependent_changes']));
            }
        }

        $assetRebuild = $assetRebuildNeeded
            ? $this->assetRebuilder?->rebuild($this->environment)
            : null;
        $messages = [...$messages, ...($assetRebuild?->messages() ?? [])];

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
     * @return array{contributions: list<mixed>, boots: list<ExtensionRuntimeBoot>}
     */
    private function runtimeContributionsAndBoots(Extension $extension, mixed $contribution): array
    {
        if (null === $contribution) {
            return ['contributions' => [], 'boots' => []];
        }

        if ($contribution instanceof ExtensionRuntimeBoot) {
            return ['contributions' => [], 'boots' => [$contribution]];
        }

        if ($contribution instanceof ExtensionActivationContributionFactory) {
            return ['contributions' => [], 'boots' => []];
        }

        if ($contribution instanceof ExtensionRuntimeContributionFactory) {
            return $this->runtimeContributionsAndBoots(
                $extension,
                $contribution->contributions(new ExtensionContributionContext($extension)),
            );
        }

        if (is_iterable($contribution)) {
            $contributions = [];
            $boots = [];

            foreach ($contribution as $item) {
                $expanded = $this->runtimeContributionsAndBoots($extension, $item);
                array_push($contributions, ...$expanded['contributions']);
                array_push($boots, ...$expanded['boots']);
            }

            return ['contributions' => $contributions, 'boots' => $boots];
        }

        return ['contributions' => [$contribution], 'boots' => []];
    }

    /**
     * @param list<ExtensionRuntimeBoot> $boots
     */
    private function executeRuntimeBoots(Extension $extension, array $boots): null
    {
        foreach ($boots as $boot) {
            $boot->boot(new ExtensionRuntimeContext($extension, $this->environment));
        }

        return null;
    }

    /**
     * @return array{changed: bool, dependent_changes: list<array{extension: string, action: string, status: string, dependency: string, reason: string}>, issues: list<Message>, messages: list<Message>}
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
        } catch (Throwable $flushError) {
            return [
                'changed' => false,
                'dependent_changes' => $dependentChanges,
                'issues' => [
                    $this->exceptionIssue($flushError, [
                        'stage' => 'extension_fault_persist',
                        'extension' => $extension->extensionName(),
                    ]),
                ],
                'messages' => $messages,
            ];
        }

        return ['changed' => $changed, 'dependent_changes' => $dependentChanges, 'issues' => [], 'messages' => $messages];
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
