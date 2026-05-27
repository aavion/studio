<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageScope;
use App\Core\Translation\TranslationCatalogueAggregator;
use Doctrine\DBAL\Connection;
use JsonException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DemoControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private KernelBrowser $client;
    /**
     * @var array<string, string|null>
     */
    private array $catalogueBackups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireDemoModule();
        self::ensureKernelShutdown();
        $this->aggregateDemoTranslations();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->activateDemoModule();
    }

    protected function tearDown(): void
    {
        $this->restoreGeneratedCatalogues();

        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRendersFrontendDemoShellFromDemoModule(): void
    {
        $this->client->request('GET', '/demo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-frontend-shell');
        self::assertSelectorExists('.studio-frontend-demo');
    }

    public function testItRendersBackendDemoShellFromDemoModule(): void
    {
        $this->client->request('GET', '/demo/backend');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-admin-shell');
        self::assertSelectorExists('.studio-editor-workspace');
    }

    public function testItRendersTypographyDemoGuideFromDemoModule(): void
    {
        $this->client->request('GET', '/demo/typography');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-typography-guide');
        self::assertSelectorExists('.studio-markdown-result');
        self::assertSelectorExists('.studio-markdown table');
        self::assertSelectorExists('.studio-markdown pre code');
        self::assertSelectorExists('.studio-markdown-embed iframe');
        self::assertStringContainsString('```markdown', (string) $this->client->getResponse()->getContent());
    }

    public function testItDoesNotRegisterTheRemovedFrontendDemoChildRoute(): void
    {
        $this->client->request('GET', '/demo/frontend');

        self::assertResponseStatusCodeSame(404);
    }

    public function testItUsesConfiguredDemoRouteOnFrontendDemoShell(): void
    {
        $this->setDemoRoute('demo2');

        $this->client->request('GET', '/demo2');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-frontend-demo');
        self::assertSelectorExists('.studio-button-primary[href="/demo2/backend"]');
        self::assertSelectorExists('.studio-button-secondary[href="/demo2/typography"]');

        $this->client->request('GET', '/demo');

        self::assertResponseStatusCodeSame(404);
    }

    private function requireDemoModule(): void
    {
        if (is_file(self::demoModulePath().'/package.php')) {
            return;
        }

        fwrite(STDERR, "[NOTICE] Demo module package is not available; skipping portable demo module route tests.\n");
        self::markTestSkipped('Demo module package is not available.');
    }

    /**
     * @throws JsonException
     */
    private function activateDemoModule(): void
    {
        $this->connection?->delete('extension_package', ['package_name' => 'demo-module']);
        $this->connection?->insert('extension_package', [
            'uid' => '00000000-0000-4000-8000-000000000101',
            'package_name' => 'demo-module',
            'path' => 'packages/demo-module',
            'package_scopes' => json_encode(['module'], JSON_THROW_ON_ERROR),
            'manifest_version' => '0.1.1',
            'installed_version' => '0.1.1',
            'status' => 'active',
            'metadata' => json_encode([
                'display_name' => 'Demo Module',
                'description' => 'Demo module route fixture.',
                'manifest' => [
                    'PACKAGE_NAME' => 'Demo Module',
                    'PACKAGE_VERSION' => '0.1.1',
                    'PACKAGE_SCOPE' => 'module',
                ],
            ], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-26 00:00:00',
        ]);
    }

    /**
     * @throws JsonException
     */
    private function setDemoRoute(string $route): void
    {
        $this->connection?->delete('package_setting_entry', ['package_name' => 'demo-module', 'setting_key' => 'demo.route']);
        $this->connection?->insert('package_setting_entry', [
            'package_name' => 'demo-module',
            'setting_key' => 'demo.route',
            'value' => json_encode($route, JSON_THROW_ON_ERROR),
            'value_type' => 'string',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'modified_at' => '2026-05-26 00:00:00',
            'modified_by' => 'test',
        ]);
    }

    private function aggregateDemoTranslations(): void
    {
        $projectDir = dirname(__DIR__, 2);
        foreach (['translations/runtime/messages.en.yaml', 'translations/runtime/messages.de.yaml'] as $relativePath) {
            $path = $projectDir.'/'.$relativePath;
            $this->catalogueBackups[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }

        $result = (new TranslationCatalogueAggregator($projectDir))->aggregate([
            new PackageAssetSyncPackage('demo-module', 'packages/demo-module', [PackageScope::Module]),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        (new Filesystem())->remove($projectDir.'/var/cache/test/translations');
    }

    private function restoreGeneratedCatalogues(): void
    {
        foreach ($this->catalogueBackups as $path => $contents) {
            if (null === $contents) {
                if (is_file($path)) {
                    unlink($path);
                }

                continue;
            }

            file_put_contents($path, $contents);
        }

        $this->catalogueBackups = [];
    }

    private static function demoModulePath(): string
    {
        return dirname(__DIR__, 2).'/packages/demo-module';
    }
}
