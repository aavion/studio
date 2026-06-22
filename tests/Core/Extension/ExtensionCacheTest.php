<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionCache;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ExtensionCacheTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/cache-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/cache-facade-b');
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, new ExtensionCache(new ArrayAdapter())));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/cache-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/cache-facade-b');
        ExtensionRuntime::reset();
    }

    public function testItStoresReadsAndDeletesExtensionOwnedArtifacts(): void
    {
        $this->writeExtensionFile('cache-facade-a', <<<'PHP'
            <?php

            extension_cache_set('captcha.challenge.demo', ['answer' => 'ok'], 60);
            $first = extension_cache_get('captcha.challenge.demo');
            $deleted = extension_cache_delete('captcha.challenge.demo');
            $afterDelete = extension_cache_get('captcha.challenge.demo', 'missing');

            return [$first, $deleted, $afterDelete];
            PHP);

        self::assertSame([['answer' => 'ok'], true, 'missing'], require $this->projectDir.'/extensions/cache-facade-a/extension.php');
    }

    public function testItScopesArtifactsByCallingExtension(): void
    {
        $this->writeExtensionFile('cache-facade-a', <<<'PHP'
            <?php

            extension_cache_set('shared.challenge', 'a', 60);

            return extension_cache_get('shared.challenge');
            PHP);
        $this->writeExtensionFile('cache-facade-b', <<<'PHP'
            <?php

            $before = extension_cache_get('shared.challenge', 'missing');
            extension_cache_set('shared.challenge', 'b', 60);

            return [$before, extension_cache_get('shared.challenge')];
            PHP);

        self::assertSame('a', require $this->projectDir.'/extensions/cache-facade-a/extension.php');
        self::assertSame(['missing', 'b'], require $this->projectDir.'/extensions/cache-facade-b/extension.php');
    }

    public function testItExpiresTtlBoundArtifacts(): void
    {
        $this->writeExtensionFile('cache-facade-a', <<<'PHP'
            <?php

            return extension_cache_set('short.challenge', 'value', 1);
            PHP);

        self::assertTrue(require $this->projectDir.'/extensions/cache-facade-a/extension.php');

        $this->writeExtensionFile('cache-facade-a', <<<'PHP'
            <?php

            return extension_cache_get('short.challenge', 'expired');
            PHP);

        sleep(2);

        self::assertSame('expired', require $this->projectDir.'/extensions/cache-facade-a/extension.php');
    }

    public function testItRejectsInvalidKeysTtlsOversizedValuesAndNonExtensionCallers(): void
    {
        $cache = new ExtensionCache(new ArrayAdapter());

        self::assertFalse($cache->set('invalid extension', 'outside', 'value'));
        self::assertFalse(ExtensionRuntime::cacheSet('outside', 'value'));
        self::assertSame('default', ExtensionRuntime::cacheGet('outside', 'default'));
        self::assertFalse(ExtensionRuntime::cacheDelete('outside'));

        $this->writeExtensionFile('cache-facade-a', <<<'PHP'
            <?php

            return [
                extension_cache_set('../escape', 'value', 60),
                extension_cache_set('valid.key', 'value', 0),
                extension_cache_set('valid.key', str_repeat('x', 1048577), 60),
                extension_cache_set('valid.object', new stdClass(), 60),
                extension_cache_get('../escape', 'default'),
            ];
            PHP);

        self::assertSame([false, false, false, false, 'default'], require $this->projectDir.'/extensions/cache-facade-a/extension.php');
    }

    private function writeExtensionFile(string $extension, string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extension.'/extension.php', $contents);
    }
}
