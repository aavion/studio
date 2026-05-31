<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Database\DatabaseReadyState;
use App\Entity\ExtensionPackage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class PackagePhpLoader implements EventSubscriberInterface
{
    /**
     * @var array<string, true>
     */
    private array $loadedPackages = [];
    private PackageDependentDeactivator $dependentDeactivator;

    public function __construct(
        private readonly ActivePackageProviderInterface $packageProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly WorkflowResultMessageReporterInterface $messageReporter,
        private readonly ?PackageAssetRebuildDispatcher $assetRebuildDispatcher = null,
        private readonly string $environment = 'test',
        private readonly ?PackageRuntimeContributionRegistry $runtimeContributions = null,
        private readonly PathGuard $pathGuard = new PathGuard(),
        private readonly ?DatabaseReadyState $databaseReadyState = null,
        ?PackageDependentDeactivator $dependentDeactivator = null,
    ) {
        $this->dependentDeactivator = $dependentDeactivator ?? new PackageDependentDeactivator($entityManager);
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

        $this->loadActivePackages();
    }

    /**
     * @return WorkflowResult<array{loaded: list<string>, skipped: list<string>}>
     */
    public function loadActivePackages(): WorkflowResult
    {
        return $this->report($this->doLoadActivePackages());
    }

    /**
     * @return WorkflowResult<array{loaded: list<string>, skipped: list<string>}>
     */
    private function doLoadActivePackages(): WorkflowResult
    {
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return WorkflowResult::success(['loaded' => [], 'skipped' => []], ['database_ready' => false]);
        }

        try {
            $packages = $this->packageProvider->packages();
        } catch (Throwable $error) {
            return WorkflowResult::failed([$this->exceptionIssue($error, ['stage' => 'active_package_lookup'])]);
        }

        $loaded = [];
        $skipped = [];
        $issues = [];
        $messages = [];
        $dependentChanges = [];
        $assetRebuildNeeded = false;

        foreach ($packages as $package) {
            if (ExtensionPackageStatus::Active !== $package->status()) {
                $skipped[] = $package->packageName();
                continue;
            }

            if (isset($this->loadedPackages[$package->packageName()])) {
                $skipped[] = $package->packageName();
                continue;
            }

            $loaderPath = $this->loaderPath($package);

            if (null === $loaderPath || !is_file($loaderPath)) {
                $skipped[] = $package->packageName();
                continue;
            }

            try {
                $result = $this->includeLoader($loaderPath, $package);

                if (is_callable($result)) {
                    $result = $result($package);
                }

                $this->runtimeContributions?->add($package, $result);

                $this->loadedPackages[$package->packageName()] = true;
                $loaded[] = $package->packageName();
            } catch (Throwable $error) {
                $issue = $this->phpLoadIssue($package, $loaderPath, $error);
                $issues[] = $issue;
                $messages[] = Message::exception(
                    MessageCode::PACKAGE_LIFECYCLE_PHP_LOAD_FAILED,
                    MessageKey::PACKAGE_LIFECYCLE_PHP_LOAD_FAILED,
                    ['%package%' => $package->packageName()],
                    $issue->context(),
                );
                $fault = $this->markFaulty($package, $loaderPath, $error);
                array_push($messages, ...$fault['messages']);
                array_push($dependentChanges, ...$fault['dependent_changes']);
                $assetRebuildNeeded = $assetRebuildNeeded || $fault['changed'] || [] !== $fault['dependent_changes'];
            }
        }

        $assetRebuild = $assetRebuildNeeded
            ? $this->assetRebuildDispatcher?->dispatch($this->environment, 'package_php_loader_faulty')
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
            'operation' => 'package.php_load',
            'environment' => $this->environment,
        ]);
    }

    private function loaderPath(ExtensionPackage $package): ?string
    {
        try {
            return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($package->path().'/package.php');
        } catch (Throwable) {
            return null;
        }
    }

    private function includeLoader(string $loaderPath, ExtensionPackage $package): mixed
    {
        return (static function (string $loaderPath, ExtensionPackage $package): mixed {
            return require $loaderPath;
        })($loaderPath, $package);
    }

    /**
     * @return array{changed: bool, dependent_changes: list<array{package: string, action: string, status: string, dependency: string, reason: string}>, messages: list<Message>}
     */
    private function markFaulty(ExtensionPackage $package, string $loaderPath, Throwable $error): array
    {
        $changed = $package->markFaulty($package->path(), $package->manifestVersion(), [
            ...$package->metadata(),
            'registry_state' => 'faulty',
            'runtime_loader' => [
                'failed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'loader' => $this->projectRelativePath($loaderPath),
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ],
        ]);
        $dependentChanges = [];
        $messages = [];

        if ($changed) {
            $deactivation = $this->dependentDeactivator->deactivateActiveDependents($package, 'package_php_loader_fault');
            $dependentChanges = $deactivation['changes'];
            $messages = $deactivation['messages'];
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable) {
        }

        return ['changed' => $changed, 'dependent_changes' => $dependentChanges, 'messages' => $messages];
    }

    private function phpLoadIssue(ExtensionPackage $package, string $loaderPath, Throwable $error): Message
    {
        return Message::create(
            MessageCode::PACKAGE_LIFECYCLE_PHP_LOAD_FAILED,
            MessageKey::PACKAGE_LIFECYCLE_PHP_LOAD_FAILED,
            ['%package%' => $package->packageName()],
            [
                'package' => $package->packageName(),
                'path' => $package->path(),
                'loader' => $this->projectRelativePath($loaderPath),
                'exception' => $error::class,
                'message' => $error->getMessage(),
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
            MessageCode::OPERATION_EXCEPTION,
            MessageKey::OPERATION_EXCEPTION,
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
}
