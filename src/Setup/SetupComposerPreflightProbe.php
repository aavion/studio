<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Process\PhpCliBinaryManager;

final readonly class SetupComposerPreflightProbe
{
    public function __construct(
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
        private SetupComposerEnvironment $composerEnvironment = new SetupComposerEnvironment(),
        private SetupPreflightCheckFactory $checkFactory = new SetupPreflightCheckFactory(),
        private SetupPreflightProcessProbe $processProbe = new SetupPreflightProcessProbe(),
        private SetupPreflightPhpCliFailureMapper $phpCliFailureMapper = new SetupPreflightPhpCliFailureMapper(),
    ) {
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    public function check(string $projectDir, string $environment, bool $autoHeal): array
    {
        $bundledComposer = $projectDir.'/bin/composer';
        $composerEnvironment = $this->composerEnvironment->create($projectDir);
        if ($autoHeal && is_file($bundledComposer) && !is_executable($bundledComposer) && is_writable($bundledComposer)) {
            @chmod($bundledComposer, 0755);
        }

        $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $environment, $composerEnvironment, $autoHeal);
        $phpCommand = $phpCli->commandPrefix();

        if ($phpCli->isAvailable()
            && is_file($bundledComposer)
            && is_readable($bundledComposer)
            && $this->processProbe->composerCommandWorks([...$phpCommand, $bundledComposer, '--version'], $projectDir, $composerEnvironment)
        ) {
            return $this->checkFactory->row('composer_binary', 'ok', true, false, 'composer_bundled');
        }

        if (is_file($bundledComposer)) {
            $works = $phpCli->isAvailable()
                && is_readable($bundledComposer)
                && $this->processProbe->composerCommandWorks([...$phpCommand, $bundledComposer, '--version'], $projectDir, $composerEnvironment);
            if (!$works && $autoHeal && is_writable($bundledComposer) && $this->downloadBundledComposer($bundledComposer, $projectDir, $environment)) {
                return $this->checkFactory->row('composer_binary', 'ok', true, false, 'composer_bundled');
            }

            if (!$works && $this->processProbe->composerCommandWorks(['composer', '--version'], $projectDir, $composerEnvironment)) {
                return $this->checkFactory->row('composer_binary', 'ok', true, false, 'composer_system');
            }

            return $this->checkFactory->row(
                'composer_binary',
                $works ? 'ok' : 'failed',
                true,
                !$works && is_writable($bundledComposer) && $this->canDownloadBundledComposer($projectDir),
                $works ? 'composer_bundled' : ($phpCli->isAvailable() ? 'composer_not_executable' : $this->phpCliFailureMapper->valueKey($phpCli->reason())),
            );
        }

        if ($this->processProbe->composerCommandWorks(['composer', '--version'], $projectDir, $composerEnvironment)) {
            return $this->checkFactory->row('composer_binary', 'ok', true, false, 'composer_system');
        }

        if ($autoHeal && $this->canDownloadBundledComposer($projectDir) && $this->downloadBundledComposer($bundledComposer, $projectDir, $environment)) {
            return $this->checkFactory->row('composer_binary', 'ok', true, false, 'composer_bundled');
        }

        return $this->checkFactory->row('composer_binary', 'failed', true, $this->canDownloadBundledComposer($projectDir), 'unavailable');
    }

    private function canDownloadBundledComposer(string $projectDir): bool
    {
        return is_dir($projectDir.'/bin')
            && is_writable($projectDir.'/bin')
            && $this->processProbe->commandWorks(['curl', '--version'], $projectDir);
    }

    private function downloadBundledComposer(string $target, string $projectDir, string $environment): bool
    {
        $temporary = $target.'.tmp-'.bin2hex(random_bytes(4));

        try {
            $downloaded = $this->processProbe->commandWorks([
                'curl',
                '-fsSL',
                'https://getcomposer.org/download/latest-stable/composer.phar',
                '-o',
                $temporary,
            ], $projectDir, timeout: 30.0);

            if (!$downloaded || !is_file($temporary)) {
                @unlink($temporary);

                return false;
            }

            @chmod($temporary, 0755);

            if (!@rename($temporary, $target)) {
                @unlink($temporary);

                return false;
            }

            $composerEnvironment = $this->composerEnvironment->create($projectDir);
            $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $environment, $composerEnvironment, true);

            return is_executable($target)
                && $phpCli->isAvailable()
                && $this->processProbe->composerCommandWorks([...$phpCli->commandPrefix(), $target, '--version'], $projectDir, $composerEnvironment);
        } catch (\Throwable) {
            @unlink($temporary);

            return false;
        }
    }

}
