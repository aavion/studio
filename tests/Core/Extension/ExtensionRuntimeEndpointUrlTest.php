<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionEndpointUrlGenerator;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class ExtensionRuntimeEndpointUrlTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/url-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/url-facade');
        ExtensionRuntime::reset();
    }

    public function testItGeneratesCallerOwnedLiveAndApiUrls(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, endpointUrls: new ExtensionEndpointUrlGenerator($this->urlGenerator())));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_live_url('captcha/seed', ['form' => 'register']),
                extension_api_url('messages/send', ['draft' => 1]),
                extension_live_url('owned', ['extensionSlug' => 'other-extension', 'resourcePath' => 'other/path']),
                extension_api_url('owned', ['resourcePath' => 'other/path']),
            ];
            PHP);

        self::assertSame([
            '/api/live/url-facade/captcha/seed?form=register',
            '/api/v1/extensions/url-facade/messages/send?draft=1',
            '/api/live/url-facade/owned',
            '/api/v1/extensions/url-facade/owned',
        ], require $this->projectDir.'/extensions/url-facade/extension.php');
    }

    public function testItRejectsInvalidEndpointsAndNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, endpointUrls: new ExtensionEndpointUrlGenerator($this->urlGenerator())));
        self::assertNull(ExtensionRuntime::liveUrl('captcha/seed'));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_live_url('../escape'),
                extension_api_url('/absolute'),
                extension_live_url(''),
            ];
            PHP);

        self::assertSame([null, null, null], require $this->projectDir.'/extensions/url-facade/extension.php');
    }

    private function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('api_live_extension_dispatch', new Route('/api/live/{extensionSlug}/{resourcePath}', requirements: ['resourcePath' => '.+']));
        $routes->add('api_v1_endpoint_dispatch', new Route('/api/v1/{resourcePath}', requirements: ['resourcePath' => '.+']));

        return new UrlGenerator($routes, new RequestContext());
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/url-facade/extension.php', $contents);
    }
}
