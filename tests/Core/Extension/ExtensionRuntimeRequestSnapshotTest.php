<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionRequestSnapshot;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ExtensionRuntimeRequestSnapshotTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/request-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/request-facade');
        ExtensionRuntime::reset();
    }

    public function testItReturnsRedactedCurrentRequestSnapshotForCallingExtension(): void
    {
        $stack = new RequestStack();
        $file = $this->uploadedFile('avatar.png', 'image-bytes', 'image/png');
        $request = Request::create(
            '/content/contact?term=hello&token=query-secret',
            'POST',
            ['name' => 'Letica', 'password' => 'body-secret'],
            ['system_visitor' => 'visitor-secret'],
            ['profile' => $file],
            [
                'HTTP_AUTHORIZATION' => 'Bearer secret',
                'HTTP_ACCEPT_LANGUAGE' => 'de',
            ],
        );
        $request->attributes->set('_route', 'frontend_contact');
        $request->setLocale('de');
        $stack->push($request);

        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            requests: new ExtensionRequestSnapshot($stack),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_request();
            PHP);

        $snapshot = require $this->projectDir.'/extensions/request-facade/extension.php';

        self::assertSame('POST', $snapshot['method']);
        self::assertSame('/content/contact', $snapshot['path']);
        self::assertSame('frontend_contact', $snapshot['route']);
        self::assertSame('de', $snapshot['locale']);
        self::assertSame('hello', $snapshot['query']['term']);
        self::assertSame('[redacted]', $snapshot['query']['token']);
        self::assertSame('Letica', $snapshot['body']['name']);
        self::assertSame('[redacted]', $snapshot['body']['password']);
        self::assertSame('[redacted]', $snapshot['headers']['authorization']);
        self::assertSame(['de'], $snapshot['headers']['accept-language']);
        self::assertSame(['present' => true, 'size' => strlen('visitor-secret')], $snapshot['cookies']['system_visitor']);
        self::assertSame('avatar.png', $snapshot['files']['profile']['name']);
        self::assertSame(strlen('image-bytes'), $snapshot['files']['profile']['size']);
        self::assertSame('image/png', $snapshot['files']['profile']['mime_type']);
        self::assertArrayNotHasKey('path', $snapshot['files']['profile']);
        self::assertArrayNotHasKey('contents', $snapshot['files']['profile']);
    }

    public function testItReturnsSafeDefaultsForNonExtensionCallersAndMissingRequests(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            requests: new ExtensionRequestSnapshot(new RequestStack()),
        ));

        self::assertSame([], ExtensionRuntime::request());

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_request();
            PHP);

        self::assertSame([], require $this->projectDir.'/extensions/request-facade/extension.php');
    }

    public function testItBoundsDeepAndLargeValues(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/demo', 'POST', [
            'long' => str_repeat('x', 32),
            'nested' => ['a' => ['b' => ['c' => 'hidden']]],
            'ignored' => 'value',
        ]);
        $stack->push($request);

        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            requests: new ExtensionRequestSnapshot($stack),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_request(['max_items' => 2, 'max_string_length' => 8, 'max_depth' => 2]);
            PHP);

        $snapshot = require $this->projectDir.'/extensions/request-facade/extension.php';

        self::assertSame('xxxxxxxx', $snapshot['body']['long']);
        self::assertSame(['a' => ['_truncated' => true]], $snapshot['body']['nested']);
        self::assertTrue($snapshot['body']['_truncated']);
        self::assertArrayNotHasKey('ignored', $snapshot['body']);
    }

    private function uploadedFile(string $originalName, string $contents, string $mimeType): UploadedFile
    {
        $directory = $this->createTemporaryDirectory('extension-request');
        $path = $directory.'/'.$originalName;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $originalName, $mimeType, UPLOAD_ERR_OK, true);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/request-facade/extension.php', $contents);
    }
}
