<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Process\CliProcessEnvironment;
use App\Core\Process\PhpCliBinaryManager;
use App\Setup\SetupPreflightChecker;
use Symfony\Component\Process\Process;

final readonly class SystemInfoProvider
{
    public function __construct(
        private SetupPreflightChecker $preflightChecker,
        private PhpCliBinaryManager $phpCliBinaryManager,
        private string $projectDir,
        private string $environment,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array{
     *     preflight: array<string, mixed>,
     *     summary: list<array{label_key: string, value: string}>,
     *     capabilities: list<array{label_key: string, status: string, value: string}>,
     *     php_settings: list<array{label_key: string, value: string}>,
     *     php_info: list<array{label_key: string, value: string}>
     * }
     */
    public function report(array $server = []): array
    {
        return [
            'preflight' => $this->preflightChecker->check($this->projectDir, $this->environment, server: $server),
            'summary' => $this->summary($server),
            'capabilities' => $this->capabilities(),
            'php_settings' => $this->phpSettings(),
            'php_info' => $this->phpInfo(),
        ];
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return list<array{label_key: string, value: string}>
     */
    private function summary(array $server): array
    {
        $serverSoftware = $server['SERVER_SOFTWARE'] ?? null;

        return [
            ['label_key' => 'admin.system_info.summary.os', 'value' => $this->operatingSystem()],
            ['label_key' => 'admin.system_info.summary.architecture', 'value' => php_uname('m') ?: 'unknown'],
            ['label_key' => 'admin.system_info.summary.web_server', 'value' => is_string($serverSoftware) && '' !== trim($serverSoftware) ? trim($serverSoftware) : 'unknown'],
            ['label_key' => 'admin.system_info.summary.php_version', 'value' => PHP_VERSION],
            ['label_key' => 'admin.system_info.summary.php_sapi', 'value' => PHP_SAPI],
            ['label_key' => 'admin.system_info.summary.php_binary', 'value' => PHP_BINARY],
            ['label_key' => 'admin.system_info.summary.environment', 'value' => $this->environment],
            ['label_key' => 'admin.system_info.summary.composer', 'value' => $this->composerVersion()],
        ];
    }

    /**
     * @return list<array{label_key: string, status: string, value: string}>
     */
    private function capabilities(): array
    {
        return [
            $this->extensionCapability('gd', $this->gdVersion()),
            $this->extensionCapability('imagick', $this->imagickVersion()),
            $this->extensionCapability('intl', phpversion('intl') ?: null),
            $this->extensionCapability('fileinfo', phpversion('fileinfo') ?: null),
            $this->extensionCapability('opcache', phpversion('Zend OPcache') ?: phpversion('opcache') ?: null),
        ];
    }

    /**
     * @return list<array{label_key: string, value: string}>
     */
    private function phpSettings(): array
    {
        return [
            ['label_key' => 'admin.system_info.php_settings.memory_limit', 'value' => $this->iniValue('memory_limit')],
            ['label_key' => 'admin.system_info.php_settings.upload_max_filesize', 'value' => $this->iniValue('upload_max_filesize')],
            ['label_key' => 'admin.system_info.php_settings.post_max_size', 'value' => $this->iniValue('post_max_size')],
            ['label_key' => 'admin.system_info.php_settings.max_execution_time', 'value' => $this->iniValue('max_execution_time')],
            ['label_key' => 'admin.system_info.php_settings.max_input_vars', 'value' => $this->iniValue('max_input_vars')],
            ['label_key' => 'admin.system_info.php_settings.timezone', 'value' => date_default_timezone_get()],
        ];
    }

    /**
     * @return list<array{label_key: string, value: string}>
     */
    private function phpInfo(): array
    {
        return [
            ['label_key' => 'admin.system_info.php_info.loaded_ini', 'value' => php_ini_loaded_file() ?: 'none'],
            ['label_key' => 'admin.system_info.php_info.scanned_ini', 'value' => php_ini_scanned_files() ?: 'none'],
            ['label_key' => 'admin.system_info.php_info.extensions', 'value' => implode(', ', $this->loadedExtensions())],
            ['label_key' => 'admin.system_info.php_info.disabled_functions', 'value' => $this->iniValue('disable_functions', 'none')],
            ['label_key' => 'admin.system_info.php_info.temp_dir', 'value' => sys_get_temp_dir()],
        ];
    }

    /**
     * @return array{label_key: string, status: string, value: string}
     */
    private function extensionCapability(string $extension, ?string $version): array
    {
        $loaded = extension_loaded($extension);

        return [
            'label_key' => 'admin.system_info.capabilities.'.$extension,
            'status' => $loaded ? 'ok' : 'missing',
            'value' => $loaded ? ($version ?: 'available') : 'missing',
        ];
    }

    private function operatingSystem(): string
    {
        $details = trim(php_uname('s').' '.php_uname('r'));

        return '' === $details ? PHP_OS_FAMILY : PHP_OS_FAMILY.' ('.$details.')';
    }

    private function gdVersion(): ?string
    {
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            return phpversion('gd') ?: null;
        }

        $info = gd_info();
        $version = $info['GD Version'] ?? null;

        return is_string($version) && '' !== trim($version) ? trim($version) : (phpversion('gd') ?: null);
    }

    private function imagickVersion(): ?string
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return phpversion('imagick') ?: null;
        }

        $version = \Imagick::getVersion()['versionString'] ?? null;

        return is_string($version) && '' !== trim($version) ? trim($version) : (phpversion('imagick') ?: null);
    }

    /**
     * @return list<string>
     */
    private function loadedExtensions(): array
    {
        $extensions = get_loaded_extensions();
        natcasesort($extensions);

        return array_values($extensions);
    }

    private function iniValue(string $key, string $emptyValue = 'unknown'): string
    {
        $value = ini_get($key);

        return false !== $value && '' !== trim($value) ? trim($value) : $emptyValue;
    }

    private function composerVersion(): string
    {
        $bundledComposer = $this->projectDir.'/bin/composer';
        $processEnvironment = CliProcessEnvironment::fromCurrentProcess($this->processEnvironment());
        $phpCli = $this->phpCliBinaryManager->resolve($this->projectDir, $this->environment, $processEnvironment);

        if ($phpCli->isAvailable() && is_file($bundledComposer) && is_readable($bundledComposer)) {
            $version = $this->commandOutput([...$phpCli->commandPrefix(), $bundledComposer, '--version'], $processEnvironment);
            if (null !== $version) {
                return $version;
            }
        }

        return $this->commandOutput(['composer', '--version'], $processEnvironment) ?? 'unavailable';
    }

    /**
     * @param list<string> $command
     * @param array<string, string|false> $environment
     */
    private function commandOutput(array $command, array $environment): ?string
    {
        try {
            $process = new Process(
                $command,
                $this->projectDir,
                $environment,
                timeout: 3.0,
            );
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput().$process->getErrorOutput());

        if ('' === $output) {
            return null;
        }

        return preg_replace('/\s+/', ' ', $output) ?: $output;
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $path = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH');

        return is_string($path) && '' !== trim($path) ? ['PATH' => trim($path)] : [];
    }
}
