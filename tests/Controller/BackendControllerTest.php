<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
