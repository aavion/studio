<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Entity\AclGroup;
use App\Entity\ExtensionPackage;
use App\Entity\UserAccount;
use App\Setup\SetupCompletionMarker;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BackendControllerTest extends WebTestCase
{
    public function testSetupRouteRendersWithoutAuthentication(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $client->request('GET', '/setup');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.studio-setup-shell');
            self::assertSelectorTextContains('h1', 'Setup');
            self::assertSelectorExists('form#setup-web');
            self::assertSelectorExists('form#setup-web[data-turbo="false"]');
            self::assertSelectorExists('input[name="_csrf_token"]');
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupRouteRunsDryRunWithoutAuthentication(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/setup');
            $form = $crawler->selectButton('Run setup')->form([
                'language' => 'en',
                'site_title' => 'Dry Run Studio',
                'default_uri' => 'http://localhost',
                'database_driver' => 'sqlite',
                'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                'admin_username' => 'admin',
                'admin_password' => 'admin',
                'admin_password_confirm' => 'admin',
                'admin_email' => 'admin@localhost',
                'dry_run' => '1',
            ]);

            $client->submit($form);

            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Setup result', $html);
            self::assertStringContainsString('Write environment', $html);
            self::assertStringContainsString('Skipped', $html);
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testAdminRouteRequiresAdministrativeAccess(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('h1', 'Sign in');
        self::assertSelectorTextContains('.studio-auth-notice', 'This content is only available after signing in with sufficient access.');
        self::assertSelectorExists('input[name="_target_path"][value="/admin"]');
    }

    public function testAdminRouteAllowsAccessLevelEight(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-admin-shell');
        self::assertSelectorTextContains('h1', 'Admin dashboard');
        self::assertSelectorExists('.studio-backend-nav a[aria-current="page"]');
    }

    public function testAdminRegisteredBackendViewRouteRendersThroughRegistry(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $client->request('GET', '/admin/packages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package management');
        self::assertSelectorTextContains('.studio-backend-nav', 'Packages');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/packages"][aria-current="page"]');
        self::assertSelectorExists('.studio-page-actions form input[name="_backend_action"][value="package_discovery"]');
        self::assertSelectorExists('.studio-backend-topbar form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorExists('.studio-backend-topbar form input[name="_backend_action"][value="cache_clear"]');
        self::assertSelectorNotExists('.studio-page-actions form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorNotExists('.studio-page-actions form input[name="_backend_action"][value="cache_clear"]');
        self::assertSelectorExists('.studio-backend-nav .is-collapsed a[href="/admin/settings"][aria-expanded="false"]');
        self::assertSelectorNotExists('.studio-backend-nav a[href="/admin/settings/general"]');
        self::assertSelectorTextContains('.studio-table', 'aavion Studio');
        self::assertSelectorTextContains('.studio-table', '0.1.0');
        self::assertSelectorTextContains('.studio-table', 'Active');
        self::assertSelectorTextContains('.studio-table', 'System template');
        self::assertSelectorExists('.studio-table tr.is-immutable[data-package-name="system"][data-immutable="true"]');
        self::assertSelectorExists('.studio-table a[href="/admin/packages/system"]');

        $client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Theme management');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/themes"][aria-current="page"]');
        self::assertSelectorNotExists('.studio-page-actions form input[name="_backend_action"][value="package_discovery"]');
        self::assertSelectorExists('.studio-backend-topbar form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorExists('.studio-backend-topbar form input[name="_backend_action"][value="cache_clear"]');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', 'Frontend themes');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', 'aavion Studio');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', '0.1.0');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] a[href="/admin/packages/system"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card.is-immutable[data-package-name="system"][data-theme-status="active"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-preview');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card[data-package-name="system"]', 'Active');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', 'Backend themes');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', 'aavion Studio');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', '0.1.0');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="backend"] a[href="/admin/packages/system"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="backend"] .studio-theme-card.is-immutable[data-package-name="system"][data-theme-status="active"]');

        $this->removePackageByName('test-frontend-theme');
        $this->removePackageByName('test-removed-theme');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $frontendTheme = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000499',
            [PackageScope::FrontendTheme],
            'test-frontend-theme',
            'packages/test-frontend-theme',
            ExtensionPackageStatus::Active,
            ['display_name' => 'Test Frontend Theme'],
            manifestVersion: '1.0.0',
        );
        $removedTheme = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000497',
            [PackageScope::FrontendTheme],
            'test-removed-theme',
            'packages/test-removed-theme',
            ExtensionPackageStatus::Removed,
            ['display_name' => 'Test Removed Theme'],
            manifestVersion: '1.0.0',
        );
        $entityManager->persist($frontendTheme);
        $entityManager->persist($removedTheme);
        $entityManager->flush();

        $client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card.is-immutable[data-package-name="system"][data-theme-status="inactive"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] a[href="/admin/packages/test-frontend-theme/deactivate"]');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', 'Test Frontend Theme');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card[data-package-name="test-frontend-theme"]', 'Active');
        self::assertSelectorNotExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card[data-package-name="test-removed-theme"]');
        $this->removePackageByName('test-frontend-theme');
        $this->removePackageByName('test-removed-theme');

        foreach ([
            '/admin/users' => 'User management',
            '/admin/scheduler' => 'Scheduler',
            '/admin/backups' => 'Backup and restore',
            '/admin/operations' => 'Operations',
            '/admin/logs' => 'Logs',
        ] as $path => $title) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorExists(sprintf('.studio-backend-nav a[href="%s"][aria-current="page"]', $path));
        }
    }

    public function testAdminOperationsViewListsTransientLiveOperationState(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $lock = $store->acquireRunnerLock($run['operation_id']);

        try {
            self::assertNotNull($lock);
            $client->request('GET', '/admin/operations');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Operations');
            self::assertSelectorExists(sprintf('tr[data-operation-id="%s"][data-operation-status="queued"]', $run['operation_id']));
            self::assertSelectorExists('form input[name="_operations_action"][value="cleanup"]');
            self::assertSelectorNotExists('form input[name="_operations_action"][value="kill_stale_runner"]');
            self::assertSelectorExists('.studio-backend-nav a[href="/admin/operations"][aria-current="page"]');
        } finally {
            $lock?->release();
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
        }
    }

    public function testAdminOperationDetailShowsRetainedActionLogEntries(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $store->appendEntry(
            $run['operation_id'],
            ActionLogEntry::pending('Clear cache')->start()->finish(ActionLogStatus::Success),
            1,
            1,
        );
        $store->finish($run['operation_id'], true, ['status' => 'success', 'issues' => [], 'messages' => []]);

        try {
            $client->request('GET', '/admin/operations/'.$run['operation_id']);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Cache clear');
            self::assertSelectorTextContains('.studio-panel', 'Operation overview');
            self::assertSelectorTextContains('.studio-action-log-list', 'Clear cache');
            self::assertSelectorTextContains('.studio-action-log-list', 'Successful');
        } finally {
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
        }
    }

    public function testAdminBackendActionFormsRunPackageDiscoveryImmediately(): void
    {
        $client = self::createClient();
        $demoPackages = ['demo-module', 'demo-frontend-theme', 'demo-captcha-provider'];

        foreach ($demoPackages as $packageName) {
            $this->removePackageByName($packageName);
        }

        try {
            $client->loginUser($this->createUserWithLevel(8));
            $crawler = $client->request('GET', '/admin/packages');

            self::assertSelectorNotExists('.studio-table tr[data-package-name="demo-module"]');

            $form = $crawler->selectButton('Update registry')->form();

            $client->submit($form);

            self::assertResponseRedirects('/admin/packages');

            $client->followRedirect();

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.studio-alert-success');
            self::assertSelectorExists('.studio-table tr[data-package-name="demo-module"]');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(
                ExtensionPackage::class,
                $entityManager->getRepository(ExtensionPackage::class)->findOneBy(['packageName' => 'demo-module']),
            );
        } finally {
            foreach ($demoPackages as $packageName) {
                $this->removePackageByName($packageName);
            }
        }
    }

    public function testAdminTopbarActionsHandlePackageDetailPosts(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $crawler = $client->request('GET', '/admin/packages/system');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'aavion Studio');

        $form = $crawler->filter('.studio-backend-topbar form')->first()->form();

        $client->submit($form);

        self::assertResponseRedirects('/admin/packages/system');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'aavion Studio');
    }

    public function testAdminPackageDetailAndLifecycleReviewRoutesRender(): void
    {
        $client = self::createClient();
        $this->removePackageByName('test-lifecycle');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $packageDir = $projectDir.'/packages/test-lifecycle';
        $assetsDir = $packageDir.'/assets';
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0775, true);
        }
        file_put_contents($packageDir.'/.manifest', <<<'MANIFEST'
            PACKAGE_AUTHOR=Test Suite
            PACKAGE_SLUG=test-lifecycle
            PACKAGE_NAME=Test Lifecycle
            PACKAGE_DESCRIPTION=Lifecycle package fixture
            PACKAGE_VERSION=1.0.0
            PACKAGE_SCOPE=module
            PACKAGE_DEPENDENCIES=["demo-base >=1.0"]
            PACKAGE_LICENSE=MIT
            PACKAGE_SOURCE=https://github.com/example/test-lifecycle
            PACKAGE_CHANNEL=main
            PACKAGE_IMAGE=assets/preview.svg
            MANIFEST);
        file_put_contents($packageDir.'/README.md', "# Lifecycle README\n\nThis package has **markdown** docs.");
        file_put_contents($assetsDir.'/preview.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9"><rect width="16" height="9" fill="#315bdc"/></svg>');

        $client->loginUser($this->createUserWithLevel(8));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $package = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000498',
            [PackageScope::Module],
            'test-lifecycle',
            'packages/test-lifecycle',
            ExtensionPackageStatus::Inactive,
            [
                'display_name' => 'Test Lifecycle',
                'description' => 'Lifecycle package fixture',
                'author' => 'Test Suite',
                'manifest' => [
                    'PACKAGE_NAME' => 'Test Lifecycle',
                    'PACKAGE_VERSION' => '1.0.0',
                ],
            ],
            manifestVersion: '1.0.0',
        );
        $entityManager->persist($package);
        $entityManager->flush();

        try {
            $client->request('GET', '/admin/packages');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle"]');
            self::assertSelectorNotExists('a[href="/admin/packages/test-lifecycle/activate"]');
            self::assertSelectorNotExists('a[href="/admin/packages/test-lifecycle/purge"]');
            self::assertSelectorNotExists('a[href="/admin/packages/test-lifecycle/delete"]');

            $client->request('GET', '/admin/packages/test-lifecycle');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Test Lifecycle');
            self::assertSelectorTextContains('.studio-table', 'Lifecycle package fixture');
            self::assertSelectorTextContains('.studio-table', 'MIT');
            self::assertSelectorTextContains('.studio-table', 'demo-base >=1.0');
            self::assertSelectorExists('.studio-package-hero img[src^="data:image/svg+xml;base64,"]');
            self::assertSelectorExists('a[href="https://github.com/example/test-lifecycle/tree/main"]');
            self::assertSelectorTextContains('a[href="https://github.com/example/test-lifecycle/tree/main"]', 'https://github.com/example/test-lifecycle/tree/main');
            self::assertSelectorTextContains('.studio-markdown h1', 'Lifecycle README');
            self::assertSelectorTextContains('.studio-markdown strong', 'markdown');
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle/activate"]');
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle/purge"]');
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle/delete"]');

            $client->request('GET', '/admin/packages/test-lifecycle/activate');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Activate Test Lifecycle');
            self::assertSelectorTextContains('.studio-table', 'activated');
            self::assertSelectorExists('button[type="submit"]');
            self::assertSelectorExists('form[data-controller="operation-overlay"][data-operation-overlay-enabled-value="true"]');

            $client->request('GET', '/admin/packages/test-lifecycle/purge');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Delete data for Test Lifecycle');
            self::assertSelectorTextContains('.studio-table', 'purged');
            self::assertSelectorTextContains('.studio-alert-warning', 'This step is irreversible.');
            self::assertSelectorExists('button.studio-button-danger[type="submit"]');

            $client->request('GET', '/admin/packages/test-lifecycle/delete');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Delete Test Lifecycle');
            self::assertSelectorTextContains('.studio-table', 'removed');
            self::assertSelectorTextContains('.studio-alert-warning', 'This step is irreversible.');
            self::assertSelectorExists('button[type="submit"]');
        } finally {
            $this->removePackageByName('test-lifecycle');
            @unlink($assetsDir.'/preview.svg');
            @rmdir($assetsDir);
            @unlink($packageDir.'/README.md');
            @unlink($packageDir.'/.manifest');
            @rmdir($packageDir);
        }
    }

    public function testAdminPackageDeactivationReviewIncludesActiveDependents(): void
    {
        $client = self::createClient();
        $this->removePackageByName('test-dependent-theme');
        $this->removePackageByName('test-dependent-captcha');

        $client->loginUser($this->createUserWithLevel(8));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $theme = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000596',
            [PackageScope::FrontendTheme],
            'test-dependent-theme',
            'packages/test-dependent-theme',
            ExtensionPackageStatus::Active,
            ['display_name' => 'Test Dependent Theme', 'manifest' => ['PACKAGE_DEPENDENCIES' => '[]']],
            manifestVersion: '1.0.0',
        );
        $captcha = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000597',
            [PackageScope::CaptchaProvider],
            'test-dependent-captcha',
            'packages/test-dependent-captcha',
            ExtensionPackageStatus::Active,
            [
                'display_name' => 'Test Dependent Captcha',
                'manifest' => ['PACKAGE_DEPENDENCIES' => "[['test-dependent-theme', '1.0.0']]"],
            ],
            manifestVersion: '1.0.0',
            installedVersion: '1.0.0',
        );
        $entityManager->persist($theme);
        $entityManager->persist($captcha);
        $entityManager->flush();

        try {
            $client->request('GET', '/admin/packages/test-dependent-theme/deactivate');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Deactivate Test Dependent Theme');
            self::assertSelectorTextContains('.studio-table', 'test-dependent-captcha');
            self::assertSelectorTextContains('.studio-table', 'test-dependent-theme');
        } finally {
            $this->removePackageByName('test-dependent-captcha');
            $this->removePackageByName('test-dependent-theme');
        }
    }

    public function testAdminSettingsRoutesRenderThroughRegistry(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $client->request('GET', '/admin/settings');

        self::assertResponseRedirects('/admin/settings/general');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'General settings');
        self::assertSelectorTextContains('.studio-backend-nav', 'Settings');
        self::assertSelectorExists('.studio-backend-nav .is-active-ancestor a[href="/admin/settings"][aria-expanded="true"]');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/settings/general"][aria-current="page"]');
        self::assertSelectorExists('form#admin-settings-general');
        self::assertStringContainsString('name="site.title"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('maxlength="120"', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('select[name="localization.default_language"][required]');
        self::assertStringContainsString('name="content.home_path"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('pattern="^/.*$"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/settings/packages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package settings');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/settings/packages"][aria-current="page"]');
        self::assertSelectorExists('form#admin-settings-packages');
        self::assertSelectorExists('select[name="packages.update_check_interval"]');

        $client->request('GET', '/admin/settings/security');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Security settings');
        self::assertSelectorExists('form#admin-settings-security');
        self::assertSelectorExists('select[name="security.captcha.provider"]');
    }

    public function testAdminSettingsFormsPersistCoreSettings(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $config = self::getContainer()->get(Config::class);

        try {
            $crawler = $client->request('GET', '/admin/settings/general');
            $form = $crawler->selectButton('Save settings')->form([
                'site.title' => 'Saved Admin Title',
                'site.url' => 'https://example.test',
                'localization.default_language' => 'en',
                'content.home_path' => '/saved-home',
            ]);

            $client->submit($form);

            self::assertResponseRedirects('/admin/settings/general');
            self::assertSame('Saved Admin Title', $config->get('site.title'));
            self::assertSame('https://example.test', $config->get('site.url'));
            self::assertSame('/saved-home', $config->get('content.home_path'));

            $client->followRedirect();

            self::assertSelectorTextContains('.studio-alert-success', 'Settings saved.');
            self::assertStringContainsString('value="Saved Admin Title"', (string) $client->getResponse()->getContent());
        } finally {
            $config->set('site.title', 'aavion Studio', ConfigValueType::String, modifiedBy: 'test');
            $config->set('site.url', 'http://localhost', ConfigValueType::String, modifiedBy: 'test');
            $config->set('localization.default_language', 'en', ConfigValueType::String, modifiedBy: 'test');
            $config->set('localization.route_prefixes_enabled', false, ConfigValueType::Boolean, modifiedBy: 'test');
            $config->set('content.home_path', '/home', ConfigValueType::String, modifiedBy: 'test');
        }
    }

    public function testAdminSettingsFormsRenderValidationErrors(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $crawler = $client->request('GET', '/admin/settings/general');
        $form = $crawler->selectButton('Save settings')->form([
            'site.title' => '',
            'site.url' => 'https://example.test',
            'localization.default_language' => 'en',
            'content.home_path' => 'saved-home',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringContainsString('The submitted value does not match the expected format.', $html);
    }

    public function testAdminStaticViewInjectionsRenderThroughBackendRegistry(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(StaticViewInjectionRegistryEvent::class, static function (StaticViewInjectionRegistryEvent $event): void {
            $event->addInjection(new StaticViewInjection(
                'test-admin-reports',
                ViewSurface::Admin,
                'reports',
                'admin.navigation.packages',
                '@backend/admin/packages.html.twig',
                accessLevel: 8,
            ));
        });

        $client->loginUser($this->createUserWithLevel(8));
        $client->request('GET', '/admin/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package management');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/reports"][aria-current="page"]');
    }

    public function testEditorRouteAllowsEditorsButAdminRouteDoesNot(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(3));
        $client->request('GET', '/editor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Editor dashboard');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('h1', 'Sign in required');
        self::assertSelectorNotExists('.studio-auth-panel');
    }

    public function testAuthenticatedBackendAreaReturnsMessageForUnknownRoute(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $client->request('GET', '/admin/missing');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.studio-alert', 'Backend route "/admin/missing" is not registered.');
    }

    private function createUserWithLevel(int $level): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => $level >= 8 ? 'admin' : 'editor',
        ]);

        self::assertInstanceOf(AclGroup::class, $group);

        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'testuser'.$level]);

        if ($existingUser instanceof UserAccount) {
            return $existingUser;
        }

        $user = new UserAccount(
            '10000000-0000-0000-0000-00000000000'.$level,
            'testuser'.$level,
            'testuser'.$level.'@example.test',
            'hash',
        );
        $user->addGroup($group);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function removePackageByName(string $packageName): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $package = $entityManager->getRepository(ExtensionPackage::class)->findOneBy(['packageName' => $packageName]);

        if (!$package instanceof ExtensionPackage) {
            return;
        }

        $entityManager->remove($package);
        $entityManager->flush();
    }

    private function restoreSetupMarker(mixed $serverValue, mixed $envValue, mixed $putenvValue): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $serverValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $serverValue;
        }

        if (null !== $envValue) {
            $_ENV[SetupCompletionMarker::KEY] = $envValue;
        }

        if (is_string($putenvValue)) {
            putenv(SetupCompletionMarker::KEY.'='.$putenvValue);

            return;
        }

        putenv(SetupCompletionMarker::KEY);
    }
}
