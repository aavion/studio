<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\AccountTokenStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserControllerTest extends WebTestCase
{
    public function testAdminUsersRouteRendersAccountsInvitationsAndPendingTokens(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User management');
        self::assertSelectorExists('form[action="/admin/users/invitations"]');
        self::assertSelectorExists('.studio-backend-nav a[href="/admin/users/groups"]');
    }

    public function testAdminCanCreateInvitationToken(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Create invitation')->form([
            'email' => 'invited-admin-flow@example.test',
        ]);
        $form['groups'][0]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $token = $entityManager->getRepository(AccountToken::class)->findOneBy([
            'email' => 'invited-admin-flow@example.test',
        ]);

        self::assertInstanceOf(AccountToken::class, $token);
        self::assertSame(AccountTokenStatus::Pending, $token->status());
        self::assertSame(['registered'], $token->groupIdentifiers());
        $entityManager->remove($token);
        $entityManager->flush();
    }

    public function testAdminCanCreateAclGroup(): void
    {
        $client = self::createClient();
        $client->loginUser($this->adminUser());
        $crawler = $client->request('GET', '/admin/users/groups');
        $form = $crawler->selectButton('Create group')->form([
            'identifier' => 'review_team',
            'name_en' => 'Review team',
            'name_de' => 'Review-Team',
            'access_level' => '6',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/groups');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $group = $entityManager->getRepository(AclGroup::class)->findOneBy([
            'identifier' => 'review_team',
        ]);

        self::assertInstanceOf(AclGroup::class, $group);
        self::assertSame(6, $group->accessLevel());
        self::assertSame(['en' => 'Review team', 'de' => 'Review-Team'], $group->name());
        $entityManager->remove($group);
        $entityManager->flush();
    }

    private function adminUser(): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $user);

        return $user;
    }

}
