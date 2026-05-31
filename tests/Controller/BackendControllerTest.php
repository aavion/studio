<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\AclGroup;
use App\Entity\ExtensionPackage;
use App\Entity\UserAccount;
use App\Security\UserFlowConfig;
use App\Security\UserRole;
use App\Setup\SetupCompletionMarker;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
            self::assertSelectorTextContains('h1', 'Welcome');
            self::assertSelectorExists('form#setup-wizard');
            self::assertSelectorExists('form#setup-wizard[data-turbo="false"]');
            self::assertSelectorExists('input[name="_csrf_token"]');
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupWizardUsesSelectedLanguageAndAdvancesPastGreenPreflight(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/setup');
            $form = $crawler->selectButton('Continue')->form(['language' => 'de']);
            $crawler = $client->submit($form, ['_setup_action' => 'set_language']);

            self::assertSelectorTextContains('h1', 'Willkommen');
            if (str_contains((string) $client->getResponse()->getContent(), 'Reparieren')) {
                $crawler = $client->submit($crawler->selectButton('Reparieren')->form(), ['_setup_action' => 'heal_preflight']);
            }
            self::assertStringContainsString('Alles Nötige für das Setup ist bereit.', (string) $client->getResponse()->getContent());

            $client->submit($crawler->selectButton('Weiter')->form());

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Grundeinstellungen', (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupRouteWalksToReviewWithoutAuthentication(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $client->request('GET', '/setup');
            $this->setSetupWizardState($client, [
                'values' => ['language' => 'en'],
                'completed' => ['language'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/site');
            $crawler = $client->submit($crawler->selectButton('Continue')->form([
                'site_title' => 'Wizard Studio',
                'default_uri' => 'http://localhost',
                'registration_mode' => 'admin_approval',
                'statistics_enabled' => '1',
                'statistics_respect_dnt' => '1',
            ]));
            $crawler = $client->submit($crawler->selectButton('Continue')->form([
                'database_driver' => 'sqlite',
                'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            ]));
            self::assertSelectorExists('form#setup-wizard[data-controller="setup-wizard password-policy"] .studio-password-meter');
            $client->submit($crawler->selectButton('Continue')->form([
                'admin_username' => 'admin',
                'admin_password' => 'Safe1!pass',
                'admin_password_confirm' => 'Safe1!pass',
                'admin_email' => 'admin@localhost.local',
            ]));

            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Review setup', $html);
            self::assertStringContainsString('Wizard Studio', $html);
            self::assertStringContainsString('Admin approval', $html);
            self::assertSelectorExists('form#setup-wizard[data-controller="setup-wizard operation-overlay"]');
            self::assertSelectorExists('form#setup-wizard[data-action="submit->operation-overlay#submit"]');
            self::assertSelectorExists('form#setup-wizard[data-operation-overlay-enabled-value="true"]');
            self::assertSelectorExists('form#setup-wizard input[name="_setup_action"][value=""]');
            self::assertSelectorExists('form#setup-wizard button[name="_setup_action"][value="apply"]');
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupRouteRejectsShortAdminPassword(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $client->request('GET', '/setup');
            $this->setSetupWizardState($client, [
                'values' => [
                    'language' => 'en',
                    'site_title' => 'Short Password Studio',
                    'default_uri' => 'http://localhost',
                    'database_driver' => 'sqlite',
                    'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                ],
                'completed' => ['language', 'site', 'database'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/admin');

            self::assertSelectorExists('input[name="admin_email"][value=""]');

            $form = $crawler->selectButton('Continue')->form([
                'admin_username' => 'admin',
                'admin_password' => 'short',
                'admin_password_confirm' => 'short',
                'admin_email' => 'admin@localhost.local',
            ]);

            $client->submit($form);

            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('The admin password must contain at least 8 characters.', $html);
            self::assertStringNotContainsString('Setup result', $html);
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupPostIsIgnoredAfterSetupLock(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/setup');
            $form = $crawler->selectButton('Continue')->form([
                'language' => 'en',
            ]);

            $_SERVER[SetupCompletionMarker::KEY] = '1';
            $_ENV[SetupCompletionMarker::KEY] = '1';
            putenv(SetupCompletionMarker::KEY.'=1');

            $client->submit($form);

            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextContains('h1', 'Page not found');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Setup result', $html);
            self::assertStringNotContainsString('Write environment', $html);
            self::assertStringNotContainsString('Setup is already completed and is no longer available.', $html);
            self::assertStringNotContainsString('Symfony Exception', $html);
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
        $manifest = $this->rootManifest();
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
        self::assertSelectorTextContains('.studio-table', $manifest['APP_NAME']);
        self::assertSelectorTextContains('.studio-table', $manifest['APP_VERSION']);
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
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', $manifest['APP_NAME']);
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"]', $manifest['APP_VERSION']);
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] a[href="/admin/packages/system"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card.is-immutable[data-package-name="system"][data-theme-status="active"]');
        self::assertSelectorExists('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-preview');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="frontend"] .studio-theme-card[data-package-name="system"]', 'Active');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', 'Backend themes');
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', $manifest['APP_NAME']);
        self::assertSelectorTextContains('.studio-theme-overview[data-theme-section="backend"]', $manifest['APP_VERSION']);
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
            '/admin/statistics' => 'Statistics',
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

    public function testAdminOperationsCleanupWritesAuditEntry(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        $crawler = $client->request('GET', '/admin/operations');
        $form = $crawler->selectButton('Clean up expired operations')->form();

        $client->submit($form);

        self::assertResponseRedirects('/admin/operations');

        $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
        self::assertStringContainsString('operations.cleanup', $auditLog);
        self::assertStringContainsString('"ttl_seconds":3600', $auditLog);
        self::assertStringContainsString('"result_status":"success"', $auditLog);
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

    public function testAdminLogsViewReadsSelectedLogSource(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');
        $logFile = $logDir.'/test.studio-access-2099-01-01.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        foreach (glob($logDir.'/test.studio-access-*.log') ?: [] as $existingLogFile) {
            @unlink($existingLogFile);
        }
        file_put_contents($logFile, '[2099-01-01T10:00:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/admin/logs","route":"backend_admin_route","http_status":200,"ip":"127.0.0.1","city":"n/a","state":"n/a","country":"n/a","continent":"n/a"} []'.PHP_EOL);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->delete('access_statistic_event', ['route' => 'backend_admin_route']);
        $connection->insert('access_statistic_event', [
            'uid' => '99999999-0000-0000-0000-000000000901',
            'occurred_at' => '2099-01-01 10:00:00',
            'request_id' => 'request-admin-logs',
            'visitor_id' => hash('sha256', 'test-visitor'),
            'method' => 'GET',
            'path' => '/admin/logs',
            'requested_path' => '/admin/logs',
            'route' => 'backend_admin_route',
            'resolved_route' => 'backend_admin_route',
            'surface' => 'admin',
            'http_status' => 200,
            'duration_ms' => 12,
            'browser_family' => 'firefox',
            'device_type' => 'desktop',
            'is_bot' => false,
            'do_not_track' => false,
            'referrer_host' => 'n/a',
            'preferred_language' => 'en-us',
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => 100,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
            'metadata' => '{}',
        ]);

        try {
            $client->request('GET', '/admin/logs?source=access&q=/admin/logs');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Logs');
            self::assertSelectorTextContains('.studio-log-table', 'GET /admin/logs');
            self::assertSelectorTextContains('.studio-log-table', 'Details');

            $client->clickLink('Details');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Log event');
            self::assertSelectorTextContains('body', '127.0.0.1');
            self::assertSelectorTextContains('.studio-code-block', 'access.request');

            $client->request('GET', '/admin/statistics?statistics_window=all');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Statistics');
            self::assertSelectorTextContains('body', 'Access statistics');
            self::assertSelectorTextContains('body', 'access-log entries are included');
            self::assertSelectorTextContains('body', 'Unique visitors');
            self::assertSelectorTextContains('body', 'Top browsers');
        } finally {
            @unlink($logFile);
            $connection->delete('access_statistic_event', ['route' => 'backend_admin_route']);
        }
    }

    public function testAdminOperationDetailExposesReviewContinuation(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('package.install.verify', [], 'Install package');
        $result = WorkflowResult::requiresReview(null, [
            Message::info(
                MessageCode::OPERATION_ACTION_REQUIRED,
                MessageKey::OPERATION_ACTION_REQUIRED,
                ['%operation%' => 'Install package'],
            ),
        ], [
            'live_operation_continuation' => [
                'operation' => 'package.install.apply',
                'payload' => ['install_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'package' => 'demo-module'],
                'label' => 'Install package',
            ],
        ]);
        $store->finish($run['operation_id'], false, $result->toArray());

        try {
            $client->request('GET', '/admin/operations/'.$run['operation_id']);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists(sprintf('form[action="/admin/operations/%s/continue"][method="post"]', $run['operation_id']));
            self::assertSelectorTextContains('form[action$="/continue"] button', 'Continue');
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
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach ($demoPackages as $packageName) {
            $this->removePackageByName($packageName);
        }
        foreach (glob($logDir.'/test.studio-audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
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
            $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
            self::assertStringContainsString('backend.action.package_discovery', $auditLog);
            self::assertStringContainsString('"result_status":"success"', $auditLog);
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
        self::assertSelectorTextContains('h1', 'Studio');

        $form = $crawler->filter('.studio-backend-topbar form')->first()->form();

        $client->submit($form);

        self::assertResponseRedirects('/admin/packages/system');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Studio');
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
            self::assertSelectorNotExists('a[href="/admin/packages/test-lifecycle/purge"]');
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
            self::assertStringContainsString(
                'Package &quot;test-lifecycle&quot; cannot change lifecycle state while it is &quot;inactive&quot;.',
                (string) $client->getResponse()->getContent(),
            );
            self::assertSelectorNotExists('button.studio-button-danger[type="submit"]');

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

    public function testAdminPackageDetailRendersUnsafeMetadataUrlsAsPlainText(): void
    {
        $client = self::createClient();
        $this->removePackageByName('test-unsafe-metadata');

        $client->loginUser($this->createUserWithLevel(8));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $package = new ExtensionPackage(
            '00000000-0000-0000-0000-000000000598',
            [PackageScope::Module],
            'test-unsafe-metadata',
            'packages/test-unsafe-metadata',
            ExtensionPackageStatus::Inactive,
            [
                'display_name' => 'Unsafe Metadata',
                'homepage' => 'javascript:alert(1)',
                'source' => 'data:text/plain,package',
                'manifest' => ['PACKAGE_DEPENDENCIES' => '[]'],
            ],
            manifestVersion: '1.0.0',
        );
        $entityManager->persist($package);
        $entityManager->flush();

        try {
            $client->request('GET', '/admin/packages/test-unsafe-metadata');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-table', 'javascript:alert(1)');
            self::assertSelectorTextContains('.studio-table', 'data:text/plain,package');

            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('href="javascript:alert(1)"', $content);
            self::assertStringNotContainsString('href="data:text/plain,package"', $content);
        } finally {
            $this->removePackageByName('test-unsafe-metadata');
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

        $client->request('GET', '/admin/settings/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User settings');
        self::assertSelectorExists('form#admin-settings-users');
        self::assertSelectorExists(sprintf('input[name="%s"][min="1"][max="3650"]', UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY));

        $client->request('GET', '/admin/settings/security');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Security settings');
        self::assertSelectorExists('form#admin-settings-security');
        self::assertSelectorExists('select[name="security.captcha.provider"]');
        self::assertSelectorExists(sprintf('input[name="%s"]', ConfigAuditLogPolicy::ENABLED_KEY));
        self::assertSelectorExists(sprintf('input[name="%s[]"]', ConfigAuditLogPolicy::EVENTS_KEY));
    }

    public function testAdminSettingsFormsPersistCoreSettings(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $config = self::getContainer()->get(Config::class);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test.studio-audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

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

            $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test.studio-audit-*.log') ?: []));
            self::assertStringContainsString('settings.core.save', $auditLog);
            self::assertStringContainsString('"section":"general"', $auditLog);
            self::assertStringContainsString('"setting_keys":["content.home_path","localization.default_language","localization.route_prefixes_enabled","site.footer_copyright","site.title","site.url"]', $auditLog);
            self::assertStringNotContainsString('Saved Admin Title', $auditLog);
            self::assertStringNotContainsString('https://example.test', $auditLog);

            $client->followRedirect();

            self::assertSelectorTextContains('.studio-alert-success', 'Settings saved.');
            self::assertStringContainsString('value="Saved Admin Title"', (string) $client->getResponse()->getContent());
        } finally {
            $config->set('site.title', 'Studio', ConfigValueType::String, modifiedBy: 'test');
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

    public function testAdminUserSettingsRejectInvalidDefaultAclGroup(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $config = self::getContainer()->get(Config::class);
        $originalDefaultGroup = $config->get('user.default_acl_group', '');

        $crawler = $client->request('GET', '/admin/settings/users');
        $form = $crawler->selectButton('Save settings')->form([
            'user.registration.mode' => 'auto_approval',
            'user.default_acl_group' => 'missing_default_acl_group',
            'user.account_link_ttl_hours' => '24',
            'user.registration.admin_notification_email' => '',
            'user.security_notification_email' => '',
            'user.menu.sort_order' => '900',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-backend-form-errors', 'Enter an existing ACL group with minimum role User or lower, or leave the field empty.');
        self::assertSame($originalDefaultGroup, $config->get('user.default_acl_group', ''));
    }

    public function testAdminUserSettingsAllowClearingDefaultAclGroup(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalDefaultGroup = $config->get('user.default_acl_group', '');
        $originalRegistrationMode = $config->get(UserFlowConfig::REGISTRATION_MODE_KEY, UserFlowConfig::REGISTRATION_DISABLED);
        $originalAccountLinkTtl = $config->get(UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS);
        $originalRegistrationEmail = $config->get(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, '');
        $originalSecurityEmail = $config->get(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, '');
        $originalMenuSortOrder = $config->get(UserFlowConfig::MENU_SORT_ORDER_KEY, 900);
        $group = new AclGroup('66000000-0000-0000-0000-000000000001', 'settings_clear_default', ['en' => 'Settings clear default'], AccessLevel::USER);
        $entityManager->persist($group);
        $entityManager->flush();
        $config->set('user.default_acl_group', $group->identifier(), ConfigValueType::String, modifiedBy: 'test');

        try {
            $crawler = $client->request('GET', '/admin/settings/users');
            $form = $crawler->selectButton('Save settings')->form([
                'user.registration.mode' => 'auto_approval',
                'user.default_acl_group' => '',
                'user.account_link_ttl_hours' => '24',
                'user.registration.admin_notification_email' => '',
                'user.security_notification_email' => '',
                'user.menu.sort_order' => '900',
            ]);

            $client->submit($form);

            self::assertResponseRedirects('/admin/settings/users');
            self::assertNull($config->get('user.default_acl_group', 'fallback'));
        } finally {
            $config->set('user.default_acl_group', $originalDefaultGroup, ConfigValueType::String, modifiedBy: 'test');
            $config->set(UserFlowConfig::REGISTRATION_MODE_KEY, $originalRegistrationMode, ConfigValueType::String, modifiedBy: 'test');
            $config->set(UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, $originalAccountLinkTtl, ConfigValueType::Integer, modifiedBy: 'test');
            $config->set(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, $originalRegistrationEmail, ConfigValueType::String, modifiedBy: 'test');
            $config->set(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, $originalSecurityEmail, ConfigValueType::String, modifiedBy: 'test');
            $config->set(UserFlowConfig::MENU_SORT_ORDER_KEY, $originalMenuSortOrder, ConfigValueType::Integer, modifiedBy: 'test');
            $managedGroup = $entityManager->find(AclGroup::class, $group->uid());

            if ($managedGroup instanceof AclGroup) {
                $entityManager->remove($managedGroup);
                $entityManager->flush();
            }
        }
    }

    public function testAdminUserSettingsRejectInvalidNotificationEmail(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(8));
        $config = self::getContainer()->get(Config::class);
        $originalRegistrationEmail = $config->get(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, '');
        $originalSecurityEmail = $config->get(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, '');

        try {
            $crawler = $client->request('GET', '/admin/settings/users');
            $form = $crawler->selectButton('Save settings')->form([
                'user.registration.mode' => 'admin_approval',
                'user.default_acl_group' => '',
                'user.account_link_ttl_hours' => '24',
                'user.registration.admin_notification_email' => 'not an email',
                'user.security_notification_email' => 'security@example.test',
                'user.menu.sort_order' => '900',
            ]);

            $client->submit($form);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.studio-backend-form-errors', 'Enter a valid email address or leave the field empty.');
            self::assertSame($originalRegistrationEmail, $config->get(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, ''));
            self::assertSame($originalSecurityEmail, $config->get(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, ''));
        } finally {
            $config->set(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, $originalRegistrationEmail, ConfigValueType::String, modifiedBy: 'test');
            $config->set(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, $originalSecurityEmail, ConfigValueType::String, modifiedBy: 'test');
        }
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
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'testuser'.$level]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeRole(UserRole::fromAccessLevel($level));
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '10000000-0000-0000-0000-00000000000'.$level,
            'testuser'.$level,
            'testuser'.$level.'@example.test',
            'hash',
            role: UserRole::fromAccessLevel($level),
        );
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

    /**
     * @return array<string, string>
     */
    private function rootManifest(): array
    {
        $manifest = [];
        $lines = file(dirname(__DIR__, 2).'/.manifest', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $manifest[trim($key)] = trim($value);
        }

        return $manifest;
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

    /**
     * @param array<string, mixed> $state
     */
    private function setSetupWizardState(KernelBrowser $client, array $state): void
    {
        $session = $client->getRequest()->getSession();
        $session->set('_studio_setup_wizard', $state);
        $session->save();
    }
}
