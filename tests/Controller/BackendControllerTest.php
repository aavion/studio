<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\AclGroup;
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
        self::assertSelectorExists('.studio-backend-nav .is-collapsed a[href="/admin/settings"][aria-expanded="false"]');
        self::assertSelectorNotExists('.studio-backend-nav a[href="/admin/settings/general"]');

        $client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Theme management');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/themes"][aria-current="page"]');

        foreach ([
            '/admin/users' => 'User management',
            '/admin/scheduler' => 'Scheduler',
            '/admin/backups' => 'Backup and restore',
            '/admin/logs' => 'Logs',
        ] as $path => $title) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorExists(sprintf('.studio-backend-nav a[href="%s"][aria-current="page"]', $path));
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
