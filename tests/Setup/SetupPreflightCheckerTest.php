<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupPreflightChecker;
use PHPUnit\Framework\TestCase;

final class SetupPreflightCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio_preflight_'.bin2hex(random_bytes(6));
        mkdir($this->root.'/public', 0775, true);
        mkdir($this->root.'/bin', 0775, true);
        file_put_contents($this->root.'/bin/console', "#!/usr/bin/env php\n<?php echo \"Studio test\";\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItDetectsProjectRootWebroot(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('failed', $result['checks'][0]['status']);
        self::assertSame('setup.preflight.checks.webroot_public.instruction', $result['checks'][0]['instruction_key']);
    }

    public function testItAutoHealsMissingWritablePaths(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);

        self::assertTrue($result['ok']);
        self::assertFileExists($this->root.'/.env.test.local');
        self::assertDirectoryExists($this->root.'/var');
        self::assertDirectoryExists($this->root.'/translations/runtime');
    }

    public function testItChecksCliRunnerAvailability(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);

        $keys = array_column($result['checks'], 'key');
        $detailKeys = array_column($result['detail_rows'], 'key');

        self::assertContains('cli_runner', $keys);
        self::assertContains('composer_binary', $keys);
        self::assertContains('tailwind_build', $keys);
        self::assertContains('php_version', $keys);
        self::assertContains('safe_mode', $detailKeys);
        self::assertContains('process_functions', $detailKeys);
        self::assertContains('tailwind_build', $detailKeys);
        self::assertContains('required_extensions', $detailKeys);
        self::assertContains('writable_paths', $detailKeys);
    }

    public function testItReportsTailwindSmokeBuildAsOptionalWarning(): void
    {
        $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $tailwind = array_values(array_filter($result['checks'], static fn (array $check): bool => 'tailwind_build' === $check['key']))[0] ?? null;

        self::assertTrue($result['ok']);
        self::assertSame('warning', $tailwind['status'] ?? null);
        self::assertFalse($tailwind['required'] ?? true);
    }

    public function testItAcceptsSuccessfulTailwindSmokeBuild(): void
    {
        $binary = $this->root.'/var/tailwind/v0.0.0/tailwindcss-test';
        mkdir(dirname($binary), 0775, true);
        file_put_contents($binary, <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        shift
        printf ".ok{color:red}\n" > "$1"
        exit 0
    fi
    shift
done
exit 1
SH);
        chmod($binary, 0755);

        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $tailwind = array_values(array_filter($result['checks'], static fn (array $check): bool => 'tailwind_build' === $check['key']))[0] ?? null;

        self::assertSame('ok', $tailwind['status'] ?? null);
    }

    public function testItUsesHighestPhpRequirementFromComposerFiles(): void
    {
        file_put_contents($this->root.'/composer.json', json_encode([
            'require' => [
                'php' => '>=8.4',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/composer.lock', json_encode([
            'platform' => [
                'php' => '>=8.4',
            ],
            'packages' => [
                [
                    'name' => 'example/package',
                    'require' => [
                        'php' => '>=99.1.2',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $php = array_values(array_filter($result['checks'], static fn (array $check): bool => 'php_version' === $check['key']))[0] ?? null;

        self::assertSame('failed', $php['status'] ?? null);
        self::assertSame('99.1.2', $php['value_parameters']['%required%'] ?? null);
    }

    public function testItUsesRequiredPhpExtensionsFromComposerJson(): void
    {
        file_put_contents($this->root.'/composer.json', json_encode([
            'require' => [
                'php' => '>=8.4',
                'ext-definitely_missing_for_studio_tests' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $extension = array_values(array_filter(
            $result['checks'],
            static fn (array $check): bool => 'extension_definitely_missing_for_studio_tests' === $check['key'],
        ))[0] ?? null;

        self::assertFalse($result['ok']);
        self::assertSame('missing', $extension['status'] ?? null);
        self::assertTrue($extension['required'] ?? false);
    }

    public function testItAutoHealsBundledComposerExecutableBit(): void
    {
        mkdir($this->root.'/bin', 0775, true);
        file_put_contents($this->root.'/bin/composer', "#!/usr/bin/env php\n<?php echo \"Composer version test\";\n");
        chmod($this->root.'/bin/composer', 0644);

        $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $composer = array_values(array_filter($result['checks'], static fn (array $check): bool => 'composer_binary' === $check['key']))[0] ?? null;

        self::assertSame('ok', $composer['status'] ?? null);
        self::assertTrue(is_executable($this->root.'/bin/composer'));
    }

    public function testItAcceptsReadableBundledComposerWithoutExecutableBit(): void
    {
        mkdir($this->root.'/bin', 0775, true);
        file_put_contents($this->root.'/bin/composer', "#!/usr/bin/env php\n<?php echo \"Composer version test\";\n");
        chmod($this->root.'/bin/composer', 0644);

        $result = (new SetupPreflightChecker())->check($this->root, 'test', server: [
            'DOCUMENT_ROOT' => $this->root.'/public',
        ]);
        $composer = array_values(array_filter($result['checks'], static fn (array $check): bool => 'composer_binary' === $check['key']))[0] ?? null;

        self::assertSame('ok', $composer['status'] ?? null);
        self::assertFalse(is_executable($this->root.'/bin/composer'));
    }

    public function testItAutoHealsCorruptWritableBundledComposer(): void
    {
        mkdir($this->root.'/bin', 0775, true);
        file_put_contents($this->root.'/bin/composer', 'broken');
        chmod($this->root.'/bin/composer', 0644);
        $toolBin = $this->root.'/tools';
        mkdir($toolBin, 0775, true);
        file_put_contents($toolBin.'/curl', <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
    echo "curl test"
    exit 0
fi
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        shift
        printf '%s\n' '<?php echo "Composer version repaired";' > "$1"
        exit 0
    fi
    shift
done
exit 1
SH);
        chmod($toolBin.'/curl', 0755);
        $previousPath = getenv('PATH');
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousEnvPath = $_ENV['PATH'] ?? null;
        putenv('PATH='.$toolBin);
        $_SERVER['PATH'] = $toolBin;
        $_ENV['PATH'] = $toolBin;

        try {
            $result = (new SetupPreflightChecker())->check($this->root, 'test', autoHeal: true, server: [
                'DOCUMENT_ROOT' => $this->root.'/public',
            ]);
        } finally {
            false === $previousPath ? putenv('PATH') : putenv('PATH='.$previousPath);
            if (null === $previousServerPath) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $previousServerPath;
            }
            if (null === $previousEnvPath) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $previousEnvPath;
            }
        }

        $composer = array_values(array_filter($result['checks'], static fn (array $check): bool => 'composer_binary' === $check['key']))[0] ?? null;

        self::assertSame('ok', $composer['status'] ?? null);
        self::assertStringContainsString('Composer version repaired', file_get_contents($this->root.'/bin/composer') ?: '');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = array_diff(scandir($path) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
