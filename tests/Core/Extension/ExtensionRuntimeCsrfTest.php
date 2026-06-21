<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionCsrfFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

final class ExtensionRuntimeCsrfTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/csrf-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/csrf-facade-b');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/csrf-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/csrf-facade-b');
        ExtensionRuntime::reset();
    }

    public function testItScopesTokensToCallingExtensionAndIntent(): void
    {
        $tokens = new CsrfTokenManager();
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            csrf: new ExtensionCsrfFacade($tokens),
        ));
        $this->writeExtensionFile('csrf-facade-a', <<<'PHP'
            <?php

            $token = extension_csrf_token('form.submit');

            return [$token, extension_csrf_valid('form.submit', $token), extension_csrf_valid('other.submit', $token)];
            PHP);
        $this->writeExtensionFile('csrf-facade-b', <<<'PHP'
            <?php

            return extension_csrf_valid('form.submit', $tokenFromA);
            PHP);

        [$tokenFromA, $validForA, $wrongIntent] = require $this->projectDir.'/extensions/csrf-facade-a/extension.php';
        self::assertIsString($tokenFromA);
        self::assertNotSame('', $tokenFromA);
        self::assertTrue($validForA);
        self::assertFalse($wrongIntent);
        self::assertFalse(require $this->projectDir.'/extensions/csrf-facade-b/extension.php');
    }

    public function testItCanValidateTokenFromCurrentRequest(): void
    {
        $stack = new RequestStack();
        $tokens = new CsrfTokenManager();
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            csrf: new ExtensionCsrfFacade($tokens, $stack),
        ));
        $this->writeExtensionFile('csrf-facade-a', <<<'PHP'
            <?php

            return extension_csrf_token('request.submit');
            PHP);
        $token = require $this->projectDir.'/extensions/csrf-facade-a/extension.php';
        $stack->push(Request::create('/submit', 'POST', ['_csrf_token' => $token]));
        $this->writeExtensionFile('csrf-facade-a', <<<'PHP'
            <?php

            return extension_csrf_valid('request.submit');
            PHP);

        self::assertTrue(require $this->projectDir.'/extensions/csrf-facade-a/extension.php');
    }

    public function testItReturnsSafeDefaultsForInvalidAndNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            csrf: new ExtensionCsrfFacade(new CsrfTokenManager()),
        ));

        self::assertSame('', ExtensionRuntime::csrfToken('form.submit'));
        self::assertFalse(ExtensionRuntime::csrfValid('form.submit', 'token'));

        $this->writeExtensionFile('csrf-facade-a', <<<'PHP'
            <?php

            return [
                extension_csrf_token(''),
                extension_csrf_token(str_repeat('x', 121)),
                extension_csrf_valid('', 'token'),
            ];
            PHP);

        self::assertSame(['', '', false], require $this->projectDir.'/extensions/csrf-facade-a/extension.php');
    }

    private function writeExtensionFile(string $extension, string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extension.'/extension.php', $contents);
    }
}
