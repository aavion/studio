<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Core\Workflow\WorkflowResult;
use App\Entity\AclGroup;
use App\Entity\ExtensionPackage;
use App\Security\UserAccountStatus;
use App\Security\UserFlowConfig;
use App\Setup\SetupCompletionMarker;
use App\Setup\SetupInputValidator;
use App\Setup\SetupWizardState;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BackendControllerTest extends WebTestCase
{
    use BackendAuthenticatedClientTrait;

    public function testSetupWizardRendersUsesSelectedLanguageAndAdvancesPastGreenPreflight(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/setup');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Welcome');
            self::assertSelectorExists('form#setup-wizard');
            self::assertSelectorExists('input[name="_csrf_token"]');

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
            self::assertSelectorExists('form#setup-wizard button[name="_setup_action"][value="apply"]');
            $storedState = $client->getRequest()->getSession()->get(SetupWizardState::SESSION_KEY);
            $encodedState = json_encode($storedState, JSON_THROW_ON_ERROR);
            self::assertIsArray($storedState);
            self::assertIsString($encodedState);
            self::assertSame('[protected]', $storedState['values']['admin_password'] ?? null);
            self::assertSame('[protected]', $storedState['values']['admin_password_confirm'] ?? null);
            self::assertStringNotContainsString('Safe1!pass', $encodedState);
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupSiteStepPrefillsDefaultUriFromHttpHost(): void
    {
        $previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $previousPutenvValue = getenv(SetupCompletionMarker::KEY);

        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);

        try {
            $client = self::createClient();
            $server = [
                'HTTP_HOST' => 'studio.example.test:8443',
                'HTTPS' => 'on',
            ];
            $client->request('GET', '/setup', server: $server);
            $this->setSetupWizardState($client, [
                'values' => ['language' => 'en'],
                'completed' => ['language'],
                'workflow' => null,
                'action_log' => null,
            ]);

            $crawler = $client->request('GET', '/setup/site', server: $server);

            self::assertResponseIsSuccessful();
            self::assertSame('https://studio.example.test:8443', $crawler->filter('input[name="default_uri"]')->attr('value'));

            $this->setSetupWizardState($client, [
                'values' => ['language' => 'en', 'default_uri' => 'https://configured.example.test'],
                'completed' => ['language'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/site', server: $server);

            self::assertResponseIsSuccessful();
            self::assertSame('https://configured.example.test', $crawler->filter('input[name="default_uri"]')->attr('value'));
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupDatabaseStepDoesNotRequireServerFieldsForInitialSqliteRender(): void
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
                    'site_title' => 'Wizard Studio',
                    'default_uri' => 'http://localhost',
                    'database_driver' => 'sqlite',
                    'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                ],
                'completed' => ['language', 'site'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $client->request('GET', '/setup/database');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="database_driver"] option[value="sqlite"][selected]');
            self::assertSelectorExists('input[name="database_port"]');
            self::assertSelectorNotExists('input[name="database_host"][required]');
            self::assertSelectorNotExists('input[name="database_port"][required]');
            self::assertSelectorNotExists('input[name="database_name"][required]');
            self::assertSelectorNotExists('input[name="database_user"][required]');
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupAdminStepUsesConfiguredAppSecretMinimumLength(): void
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
                    'site_title' => 'Wizard Studio',
                    'default_uri' => 'http://localhost',
                    'database_driver' => 'sqlite',
                    'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                ],
                'completed' => ['language', 'site', 'database'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/admin');

            self::assertResponseIsSuccessful();
            self::assertSame(
                (string) SetupInputValidator::MIN_APP_SECRET_LENGTH,
                $crawler->filter('input[name="app_secret"]')->attr('minlength'),
            );
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupDatabaseStepCanClearStoredDatabasePassword(): void
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
                    'site_title' => 'Wizard Studio',
                    'default_uri' => 'http://localhost',
                    'database_driver' => 'sqlite',
                    'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                    'database_password' => 'old-secret',
                ],
                'completed' => ['language', 'site'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/database');
            $client->submit($crawler->selectButton('Continue')->form([
                'database_driver' => 'sqlite',
                'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                'database_password' => '',
            ]));

            self::assertResponseIsSuccessful();
            $storedState = $client->getRequest()->getSession()->get(SetupWizardState::SESSION_KEY);
            self::assertIsArray($storedState);
            self::assertSame('', $storedState['values']['database_password'] ?? null);
        } finally {
            $this->restoreSetupMarker($previousServerValue, $previousEnvValue, $previousPutenvValue);
        }
    }

    public function testSetupApplyWithoutJavaScriptRendersHtmlResultFallback(): void
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
                    'site_title' => 'Fallback Studio',
                    'default_uri' => 'http://localhost',
                    'registration_mode' => 'admin_approval',
                    'statistics_enabled' => true,
                    'statistics_respect_dnt' => true,
                    'database_driver' => 'sqlite',
                    'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
                    'admin_username' => 'admin',
                    'admin_password' => 'Safe1!pass',
                    'admin_password_confirm' => 'Safe1!pass',
                    'admin_email' => 'admin@localhost.local',
                    'app_secret' => 'custom-setup-app-secret-not-secure',
                    'dry_run' => true,
                ],
                'completed' => ['language', 'site', 'database', 'admin'],
                'workflow' => null,
                'action_log' => null,
            ]);
            $crawler = $client->request('GET', '/setup/review');
            $client->submit($crawler->selectButton('Apply setup')->form(), ['_setup_action' => 'apply']);

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
            self::assertSelectorTextContains('h1', 'Setup result');
            self::assertSelectorTextContains('.system-panel', 'Setup completed');
            $storedState = $client->getRequest()->getSession()->get(SetupWizardState::SESSION_KEY);
            $encodedState = json_encode($storedState, JSON_THROW_ON_ERROR);
            self::assertIsString($encodedState);
            self::assertStringNotContainsString('Safe1!pass', $encodedState);
            self::assertStringNotContainsString('custom-setup-app-secret-not-secure', $encodedState);
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
        self::assertSelectorTextContains('.system-frontend-auth-notice', 'This content is only available after signing in with sufficient access.');
        self::assertSelectorExists('input[name="_target_path"][value="/admin"]');
    }

    public function testAdminRouteAllowsAccessLevelEight(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, AccessLevel::ADMIN);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Admin dashboard');
    }

    public function testAdminRegisteredBackendViewRouteRendersThroughRegistry(): void
    {
        $manifest = $this->rootManifest();
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $client->request('GET', '/admin/packages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package management');
        self::assertSelectorExists('.system-page-actions form input[name="_backend_action"][value="package_discovery"]');
        self::assertSelectorExists('.system-backend-topbar form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorExists('.system-backend-topbar form input[name="_backend_action"][value="cache_clear"]');
        self::assertSelectorNotExists('.system-page-actions form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorTextContains('.system-table', $manifest['APP_NAME']);
        self::assertSelectorTextContains('.system-table', $manifest['APP_VERSION']);
        self::assertSelectorTextContains('.system-table', 'Active');
        self::assertSelectorExists('.system-table a[href="/admin/packages/system"]');

        $client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Theme management');
        self::assertSelectorNotExists('.system-page-actions form input[name="_backend_action"][value="package_discovery"]');
        self::assertSelectorExists('.system-backend-topbar form input[name="_backend_action"][value="asset_rebuild"]');
        self::assertSelectorTextContains('.system-backend-theme-overview[data-theme-section="frontend"]', 'Frontend themes');
        self::assertSelectorTextContains('.system-backend-theme-overview[data-theme-section="frontend"]', $manifest['APP_NAME']);
        self::assertSelectorExists('.system-backend-theme-overview[data-theme-section="frontend"] a[href="/admin/packages/system"]');
        self::assertSelectorTextContains('.system-backend-theme-overview[data-theme-section="backend"]', 'Backend themes');
        self::assertSelectorTextContains('.system-backend-theme-overview[data-theme-section="backend"]', $manifest['APP_NAME']);

        $this->removePackageByName('test-frontend-theme');
        $this->removePackageByName('test-removed-theme');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $frontendTheme = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000499',
            [PackageScope::FrontendTheme],
            'test-frontend-theme',
            'packages/test-frontend-theme',
            ExtensionPackageStatus::Active,
            ['display_name' => 'Test Frontend Theme'],
            manifestVersion: '1.0.0',
        );
        $removedTheme = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000497',
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
        self::assertSelectorExists('.system-backend-theme-overview[data-theme-section="frontend"] a[href="/admin/packages/test-frontend-theme/deactivate"]');
        self::assertSelectorTextContains('.system-backend-theme-overview[data-theme-section="frontend"]', 'Test Frontend Theme');
        self::assertStringNotContainsString('Test Removed Theme', (string) $client->getResponse()->getContent());
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
        }
    }

    public function testAdminOperationsViewListsTransientLiveOperationState(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
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
        $this->loginUserWithLevel($client, 8);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test/audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        $crawler = $client->request('GET', '/admin/operations');
        $form = $crawler->selectButton('Clean up expired operations')->form();

        $client->submit($form);

        self::assertResponseRedirects('/admin/operations');

        $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test/audit-*.log') ?: []));
        self::assertStringContainsString('operations.cleanup', $auditLog);
        self::assertStringContainsString('"ttl_seconds":3600', $auditLog);
        self::assertStringContainsString('"result_status":"success"', $auditLog);
    }

    public function testAdminOperationDetailShowsRetainedActionLogEntries(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
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
            self::assertSelectorTextContains('.system-panel', 'Operation overview');
            self::assertSelectorTextContains('.system-backend-action-log-list', 'Clear cache');
            self::assertSelectorTextContains('.system-backend-action-log-list', 'Successful');
        } finally {
            @unlink(dirname($store->outputPath($run['operation_id'])).'/'.$run['operation_id'].'.json');
            @unlink($store->outputPath($run['operation_id']));
            @unlink($store->pidPath($run['operation_id']));
        }
    }

    public function testAdminLogsViewReadsSelectedLogSource(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->delete('access_log_entry', ['request_id' => 'request-admin-logs']);
        $connection->delete('access_statistic_event', ['route' => 'backend_admin_route']);
        $accessLogContext = [
            'method' => 'GET',
            'path' => '/admin/logs',
            'requested_path' => '/admin/logs',
            'route' => 'backend_admin_route',
            'resolved_route' => 'backend_admin_route',
            'http_status' => 200,
            'client_ip' => '127.0.0.1',
        ];
        $connection->insert('access_log_entry', [
            'uid' => '99999999-0000-7000-8000-000000000900',
            'occurred_at' => '2099-01-01 10:00:00',
            'request_id' => 'request-admin-logs',
            'correlation_id' => 'n/a',
            'method' => 'GET',
            'path' => '/admin/logs',
            'requested_path' => '/admin/logs',
            'route' => 'backend_admin_route',
            'resolved_route' => 'backend_admin_route',
            'surface' => 'admin',
            'query_string' => '',
            'http_status' => 200,
            'duration_ms' => 12,
            'visitor_id' => hash('sha256', 'test-visitor'),
            'scheme' => 'https',
            'host' => 'example.test',
            'client_ip' => '127.0.0.1',
            'proxy_client_ip' => 'n/a',
            'user_agent' => 'Test Browser',
            'referrer' => 'n/a',
            'referrer_host' => 'n/a',
            'accept_language' => 'en',
            'preferred_language' => 'en',
            'request_content_type' => 'n/a',
            'response_content_type' => 'text/html',
            'response_size' => 100,
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
            'context' => json_encode($accessLogContext, JSON_THROW_ON_ERROR),
        ]);
        $connection->insert('access_statistic_event', [
            'uid' => '99999999-0000-7000-8000-000000000901',
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
        $logDir = (string) self::getContainer()->getParameter('kernel.logs_dir');
        $applicationLog = $logDir.'/test.log';
        $previousApplicationLog = is_file($applicationLog) ? file_get_contents($applicationLog) : null;
        $applicationLine = '[2099-01-01T10:02:00.000000+00:00] app.ERROR: app.functional_failure {"code":"app.functional_failure","request_id":"functional-application-request"} []';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $applicationPrefix = is_string($previousApplicationLog) && '' !== $previousApplicationLog ? rtrim($previousApplicationLog).PHP_EOL : '';
        file_put_contents($applicationLog, $applicationPrefix.$applicationLine.PHP_EOL);
        $applicationEntryId = substr(hash('sha256', "application\0test.log\0".$applicationLine), 0, 24);

        try {
            $client->request('GET', '/admin/logs?source=access&q=/admin/logs');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Logs');
            self::assertSelectorTextContains('.system-tabs', 'Application');
            self::assertSelectorTextContains('.system-tabs', 'Security signals');
            self::assertSelectorTextContains('.system-backend-log-table', 'GET /admin/logs');
            self::assertSelectorTextContains('.system-backend-log-table', 'Details');

            $client->clickLink('Details');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Log event');
            self::assertSelectorTextContains('body', '127.0.0.1');
            self::assertSelectorTextContains('body', 'backend_admin_route');

            $client->request('GET', '/admin/logs?source=application&level%5B0%5D=ERROR&q=functional-application-request');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.system-backend-log-table', 'app.functional_failure');

            $client->request('GET', '/admin/logs/'.$applicationEntryId.'?source=application');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Log event');
            self::assertSelectorTextContains('body', 'functional-application-request');

            $client->request('GET', '/admin/statistics?statistics_window=all');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Statistics');
            self::assertSelectorTextContains('body', 'Access statistics');
            self::assertSelectorTextContains('body', 'access-log entries are included');
            self::assertSelectorTextContains('body', 'Unique visitors');
            self::assertSelectorTextContains('body', 'Top browsers');
        } finally {
            $connection->delete('access_log_entry', ['request_id' => 'request-admin-logs']);
            $connection->delete('access_statistic_event', ['route' => 'backend_admin_route']);
            if (is_string($previousApplicationLog)) {
                file_put_contents($applicationLog, $previousApplicationLog);
            } else {
                @unlink($applicationLog);
            }
        }
    }

    public function testAdminOperationDetailExposesReviewContinuation(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $store = self::getContainer()->get(LiveOperationRunStore::class);
        self::assertInstanceOf(LiveOperationRunStore::class, $store);
        $run = $store->create('package.install.verify', [], 'Install package');
        $result = WorkflowResult::requiresReview(null, [
            Message::info(
                OperationMessageCode::OPERATION_ACTION_REQUIRED,
                OperationMessageKey::OPERATION_ACTION_REQUIRED,
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
        foreach (glob($logDir.'/test/audit-*.log') ?: [] as $logFile) {
            @unlink($logFile);
        }

        try {
            $this->loginUserWithLevel($client, 8);
            $crawler = $client->request('GET', '/admin/packages');

            self::assertSelectorNotExists('.system-table tr[data-package-name="demo-module"]');

            $form = $crawler->selectButton('Update registry')->form();

            $client->submit($form);

            self::assertResponseRedirects('/admin/packages');

            $this->followAdminRedirect($client);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.system-alert-success');
            self::assertSelectorExists('.system-table tr[data-package-name="demo-module"]');

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(
                ExtensionPackage::class,
                $entityManager->getRepository(ExtensionPackage::class)->findOneBy(['packageName' => 'demo-module']),
            );
            $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test/audit-*.log') ?: []));
            self::assertStringContainsString('backend.action.package_discovery', $auditLog);
            self::assertStringContainsString('"result_status":"success"', $auditLog);
        } finally {
            foreach ($demoPackages as $packageName) {
                $this->removePackageByName($packageName);
            }
        }
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

        $this->loginUserWithLevel($client, 8);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $package = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000498',
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
            self::assertSelectorTextContains('.system-table', 'Lifecycle package fixture');
            self::assertSelectorTextContains('.system-table', 'MIT');
            self::assertSelectorTextContains('.system-table', 'demo-base >=1.0');
            self::assertSelectorExists('a[href="https://github.com/example/test-lifecycle/tree/main"]');
            self::assertSelectorTextContains('a[href="https://github.com/example/test-lifecycle/tree/main"]', 'https://github.com/example/test-lifecycle/tree/main');
            self::assertSelectorTextContains('.system-markdown h1', 'Lifecycle README');
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle/activate"]');
            self::assertSelectorNotExists('a[href="/admin/packages/test-lifecycle/purge"]');
            self::assertSelectorExists('a[href="/admin/packages/test-lifecycle/delete"]');

            $client->request('GET', '/admin/packages/test-lifecycle/activate');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Activate Test Lifecycle');
            self::assertSelectorTextContains('.system-table', 'activated');
            self::assertSelectorExists('button[type="submit"]');

            $client->request('GET', '/admin/packages/test-lifecycle/purge');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Delete data for Test Lifecycle');
            self::assertStringContainsString(
                'Package &quot;test-lifecycle&quot; cannot change lifecycle state while it is &quot;inactive&quot;.',
                (string) $client->getResponse()->getContent(),
            );
            self::assertSelectorNotExists('button.system-button-danger[type="submit"]');

            $client->request('GET', '/admin/packages/test-lifecycle/delete');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Delete Test Lifecycle');
            self::assertSelectorTextContains('.system-table', 'removed');
            self::assertSelectorTextContains('.system-alert-warning', 'This step is irreversible.');
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

        $this->loginUserWithLevel($client, 8);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $theme = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000596',
            [PackageScope::FrontendTheme],
            'test-dependent-theme',
            'packages/test-dependent-theme',
            ExtensionPackageStatus::Active,
            ['display_name' => 'Test Dependent Theme', 'manifest' => ['PACKAGE_DEPENDENCIES' => '[]']],
            manifestVersion: '1.0.0',
        );
        $captcha = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000597',
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
            self::assertSelectorTextContains('.system-table', 'test-dependent-captcha');
            self::assertSelectorTextContains('.system-table', 'test-dependent-theme');
        } finally {
            $this->removePackageByName('test-dependent-captcha');
            $this->removePackageByName('test-dependent-theme');
        }
    }

    public function testAdminPackageDetailRendersUnsafeMetadataUrlsAsPlainText(): void
    {
        $client = self::createClient();
        $this->removePackageByName('test-unsafe-metadata');

        $this->loginUserWithLevel($client, 8);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $package = new ExtensionPackage(
            '00000000-0000-7000-8000-000000000598',
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
            self::assertSelectorTextContains('.system-table', 'javascript:alert(1)');
            self::assertSelectorTextContains('.system-table', 'data:text/plain,package');

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
        $this->loginUserWithLevel($client, 8);
        $client->request('GET', '/admin/settings');

        self::assertResponseRedirects('/admin/settings/general');

        $this->followAdminRedirect($client);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'General settings');
        self::assertSelectorExists('form#admin-settings-general');
        self::assertStringContainsString('name="site.title"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('maxlength="120"', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('select[name="localization.default_language"][required]');
        self::assertStringContainsString('name="content.home_path"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('pattern="^/.*$"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/settings/packages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package settings');
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
        self::assertSelectorExists('input[name="security.signals.retention_days"]');

        $client->request('GET', '/admin/settings/logging');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Log settings');
        self::assertSelectorExists('form#admin-settings-logging');
        self::assertSelectorExists('input[name="logging.database.message_retention_days"]');
        self::assertSelectorExists('input[name="logging.database.audit_retention_days"]');
        self::assertSelectorExists('input[name="logging.database.access_retention_days"]');

        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set('statistics.geoip.maxmind.license_key', '', ConfigValueType::String, sensitive: true);

        $this->loginUserWithLevel($client, AccessLevel::OWNER);
        $client->request('GET', '/admin/settings/statistics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Statistics settings');
        self::assertSelectorExists('form#admin-settings-statistics');
        self::assertSelectorExists('input[name="statistics.geoip.enabled"]');
        self::assertSelectorExists('input[name="statistics.geoip.maxmind.license_key"][type="password"]');
        self::assertSelectorExists('a[href="https://www.maxmind.com/en/geolite2/signup"]');
        self::assertSelectorTextContains('h3', 'GeoIP2 status');
        self::assertSelectorTextContains('.system-definition-list', 'Provider');
        self::assertSelectorNotExists('input[name="_backend_action"][value="geoip_database_update"]');

        $config->set('statistics.geoip.maxmind.license_key', 'saved-test-key', ConfigValueType::String, sensitive: true);
        $client->request('GET', '/admin/settings/statistics');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_backend_action"][value="geoip_database_update"]');

        $this->loginUserWithLevel($client, AccessLevel::ADMIN);
        $client->request('GET', '/admin/settings/statistics');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#admin-settings-statistics');
        self::assertSelectorExists('input[name="statistics.enabled"]');
        self::assertSelectorNotExists('input[name="statistics.geoip.enabled"]');
        self::assertSelectorNotExists('input[name="statistics.geoip.maxmind.license_key"]');
        self::assertSelectorNotExists('input[name="_backend_action"][value="geoip_database_update"]');

        $client->request('GET', '/admin/settings/scheduler');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Scheduler settings');
        self::assertSelectorExists('form#admin-settings-scheduler');
        self::assertSelectorExists('input[name="scheduler.enabled"]');
        self::assertSelectorExists('input[name="scheduler.get_auth_enabled"]');
        self::assertSelectorExists('input[name="scheduler.package_action_queues_enabled"]');
        self::assertSelectorExists('input[name="scheduler.web_trigger_enabled"]');

        $client->request('GET', '/admin/settings/system-info');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'System information');
        self::assertSelectorTextContains('.system-panel', PHP_VERSION);
        self::assertStringContainsString('GD', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('$_SERVER', (string) $client->getResponse()->getContent());
    }

    public function testAdminSettingsFormsPersistCoreSettings(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, AccessLevel::ADMIN);
        $config = self::getContainer()->get(Config::class);
        $logDir = self::getContainer()->getParameter('kernel.logs_dir');

        foreach (glob($logDir.'/test/audit-*.log') ?: [] as $logFile) {
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

            $auditLog = implode(PHP_EOL, array_map(static fn (string $file): string => (string) file_get_contents($file), glob($logDir.'/test/audit-*.log') ?: []));
            self::assertStringContainsString('settings.core.save', $auditLog);
            self::assertStringContainsString('"section":"general"', $auditLog);
            self::assertStringContainsString('"setting_keys":["content.home_path","localization.default_language","localization.route_prefixes_enabled","site.footer_copyright","site.title","site.url"]', $auditLog);
            self::assertStringNotContainsString('Saved Admin Title', $auditLog);
            self::assertStringNotContainsString('https://example.test', $auditLog);

            $this->followAdminRedirect($client);

            self::assertSelectorTextContains('.system-alert-success', 'Settings saved.');
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
        $this->loginUserWithLevel($client, 8);
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

    public function testAdminSettingsFormsDoNotReRenderSubmittedSensitiveValuesAfterValidationErrors(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, AccessLevel::OWNER);
        $crawler = $client->request('GET', '/admin/settings/statistics');
        $form = $crawler->selectButton('Save settings')->form([
            'statistics.enabled' => '1',
            'statistics.respect_do_not_track' => '1',
            MaxMindGeoIpConfig::ENABLED_KEY => '1',
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => '',
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => 'submitted-geoip-secret',
        ]);

        $client->submit($form);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('This field is required.', $html);
        self::assertStringNotContainsString('submitted-geoip-secret', $html);
        self::assertSelectorExists(sprintf('input[name="%s"][type="password"]', MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    public function testAdminTestUserHelperRestoresUsableAccountStatus(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $staleUser = $this->createUserWithLevel(8);
        $staleUser->changeStatus(UserAccountStatus::Inactive);
        $entityManager->flush();

        $this->loginUserWithLevel($client, 8);
        $client->request('GET', '/admin/settings/general');

        self::assertResponseIsSuccessful();
        self::assertSame(UserAccountStatus::Active, $this->createUserWithLevel(8)->status());
    }

    public function testAdminUserSettingsRejectInvalidDefaultAclGroup(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
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
        self::assertSelectorTextContains('.system-backend-form-errors', 'Enter an existing ACL group with minimum role User or lower, or leave the field empty.');
        self::assertSame($originalDefaultGroup, $config->get('user.default_acl_group', ''));
    }

    public function testAdminUserSettingsAllowClearingDefaultAclGroup(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $config = self::getContainer()->get(Config::class);
        $originalDefaultGroup = $config->get('user.default_acl_group', '');
        $originalRegistrationMode = $config->get(UserFlowConfig::REGISTRATION_MODE_KEY, UserFlowConfig::REGISTRATION_DISABLED);
        $originalAccountLinkTtl = $config->get(UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS);
        $originalRegistrationEmail = $config->get(UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, '');
        $originalSecurityEmail = $config->get(UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, '');
        $originalMenuSortOrder = $config->get(UserFlowConfig::MENU_SORT_ORDER_KEY, 900);
        $group = new AclGroup('66000000-0000-7000-8000-000000000001', 'settings_clear_default', 'Settings clear default', AccessLevel::USER);
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
        $this->loginUserWithLevel($client, 8);
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
            self::assertSelectorTextContains('.system-backend-form-errors', 'Enter a valid email address or leave the field empty.');
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

        $this->loginUserWithLevel($client, 8);
        $client->request('GET', '/admin/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Package management');
    }

    public function testEditorRouteAllowsEditorsButAdminRouteDoesNot(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 3);
        $client->request('GET', '/editor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Editor dashboard');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('h1', 'Sign in required');
        self::assertSelectorNotExists('.system-frontend-auth-panel');
    }

    public function testAuthenticatedBackendAreaReturnsMessageForUnknownRoute(): void
    {
        $client = self::createClient();
        $this->loginUserWithLevel($client, 8);
        $client->request('GET', '/admin/missing');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.system-alert', 'Backend route "/admin/missing" is not registered.');
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
        $session->set(SetupWizardState::SESSION_KEY, $state);
        $session->save();
    }
}
