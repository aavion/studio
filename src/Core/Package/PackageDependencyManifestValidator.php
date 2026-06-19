<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Process\CliProcessEnvironment;
use App\Setup\SetupComposerEnvironment;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class PackageDependencyManifestValidator
{
    public function __construct(
        private ?string $projectDir = null,
        private SetupComposerEnvironment $composerEnvironment = new SetupComposerEnvironment(),
    ) {
    }

    /**
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, PackageInspection $inspection): array
    {
        if (!in_array('composer.json', $inspection->inventory(), true)) {
            return [];
        }

        $command = $this->composerCommand();
        if (null === $command) {
            return [];
        }

        try {
            $process = new Process([
                ...$command,
                'validate',
                '--no-check-publish',
                '--no-check-lock',
                '--no-check-version',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                'composer.json',
            ], $candidate->directory(), $this->processEnvironment());
            $process->setTimeout(15);
            $process->run();
        } catch (Throwable $error) {
            return [$this->issue($candidate, 'composer_validation_unavailable', [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ])];
        }

        if ($process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return [$this->issue($candidate, 'composer_manifest_invalid', [
            'exit_code' => $process->getExitCode(),
            'output' => substr($output, 0, 2000),
        ])];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function issue(PackageCandidate $candidate, string $reason, array $context): Message
    {
        return Message::create(
            PackageMessageCode::PACKAGE_POLICY_BLOCKED_PATH,
            PackageMessageKey::PACKAGE_POLICY_BLOCKED_PATH,
            ['%path%' => 'composer.json', '%reason%' => $reason],
            context: [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'path' => 'composer.json',
                'reason' => $reason,
                'policy' => 'package.dependency_manifest',
                ...$context,
            ],
            level: MessageLevel::Error,
        );
    }

    /**
     * @return list<string>|null
     */
    private function composerCommand(): ?array
    {
        $projectDir = $this->projectDir ?? getcwd() ?: null;
        if (is_string($projectDir)) {
            $bundledComposer = rtrim($projectDir, DIRECTORY_SEPARATOR.'/\\').DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'composer';
            if (is_file($bundledComposer) && is_readable($bundledComposer)) {
                return [PHP_BINARY, $bundledComposer];
            }
        }

        return $this->commandWorks(['composer', '--version']) ? ['composer'] : null;
    }

    /**
     * @param list<string> $command
     */
    private function commandWorks(array $command): bool
    {
        try {
            $process = new Process($command, null, $this->processEnvironment());
            $process->setTimeout(5);
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful() && str_contains($process->getOutput().$process->getErrorOutput(), 'Composer');
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        return CliProcessEnvironment::fromCurrentProcess(
            $this->composerEnvironment->create($this->resolvedProjectDir()),
        );
    }

    private function resolvedProjectDir(): string
    {
        return rtrim($this->projectDir ?? getcwd() ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR.'/\\');
    }
}
