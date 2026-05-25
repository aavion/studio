<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BackendControllerTest extends WebTestCase
{
    public function testSetupRouteRendersWithoutAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-setup-shell');
        self::assertSelectorTextContains('h1', 'Setup');
    }

    public function testAdminRouteRequiresAdministrativeAccess(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.studio-alert', 'Access denied');
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

    public function testEditorRouteAllowsEditorsButAdminRouteDoesNot(): void
    {
        $client = self::createClient();
        $client->loginUser($this->createUserWithLevel(3));
        $client->request('GET', '/editor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Editor dashboard');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
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
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => 'level_'.$level]);

        if (!$group instanceof AclGroup) {
            $group = new AclGroup(
                '20000000-0000-0000-0000-00000000000'.$level,
                'level_'.$level,
                ['en' => 'Level '.$level],
                $level,
            );
            $entityManager->persist($group);
        }

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
}
