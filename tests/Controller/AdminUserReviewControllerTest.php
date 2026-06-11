<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserReviewControllerTest extends WebTestCase
{
    use AuthenticatedClientTrait;
    use AdminUserFixtureTrait;

    public function testAdminReviewQueueRendersContextualRowsWithoutPasswordResetTokens(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issuer = self::getContainer()->get(AccountTokenIssuer::class);
        [$registration] = $issuer->issue(AccountTokenType::Registration, 'approval-review@example.test', ['qa_members'], status: AccountTokenStatus::PendingApproval);
        [$invitation] = $issuer->issue(AccountTokenType::Invitation, 'expired-invite-review@example.test', ['qa_members'], ttl: '-1 hour');
        [$passwordReset] = $issuer->issue(AccountTokenType::PasswordReset, $this->adminUser()->email(), [], $this->adminUser());

        foreach ([$registration, $invitation, $passwordReset] as $token) {
            $entityManager->persist($token);
        }

        $entityManager->flush();
        $client->request('GET', '/admin/users/reviews');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User reviews');
        self::assertSelectorTextContains('.system-backend-review-list', 'approval-review@example.test');
        self::assertSelectorTextContains('.system-backend-review-list', 'Registration approval');
        self::assertSelectorTextContains('.system-backend-review-list', 'expired-invite-review@example.test');
        self::assertSelectorTextContains('.system-backend-review-list', 'Link expired');
        self::assertStringNotContainsString('Password reset', (string) $client->getResponse()->getContent());

        foreach ([$registration, $invitation, $passwordReset] as $token) {
            $entityManager->remove($token);
        }

        $entityManager->flush();
    }

    public function testAdminReviewQueueSupportsSearchSortingAndPagination(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $issuer = self::getContainer()->get(AccountTokenIssuer::class);
        [$visible] = $issuer->issue(AccountTokenType::Registration, 'visible-review-filter@example.test', ['qa_members'], status: AccountTokenStatus::PendingApproval);
        [$hidden] = $issuer->issue(AccountTokenType::Invitation, 'hidden-review-filter@example.test', ['qa_members']);
        $entityManager->persist($visible);
        $entityManager->persist($hidden);
        $entityManager->flush();

        $client->request('GET', '/admin/users/reviews?q=visible-review-filter&sort=email&direction=asc&per_page=25');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="q"][value="visible-review-filter"]');
        self::assertSelectorTextContains('.system-backend-review-list', 'visible-review-filter@example.test');
        self::assertStringNotContainsString('hidden-review-filter@example.test', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.system-toolbar', 'Page 1 of 1');

        $entityManager->remove($entityManager->find(AccountToken::class, $visible->uid()));
        $entityManager->remove($entityManager->find(AccountToken::class, $hidden->uid()));
        $entityManager->flush();
    }

    public function testAdminCanReactivateDisputedAccountFromReviewQueue(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewlocked', UserAccountStatus::Inactive);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $client->submit($crawler->filter('form[action="/admin/users/reviews/details/'.$user->username().'/reactivate"]')->form());

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserAccountStatus::Active, $updatedUser->status());
        self::assertNull($updatedToken);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testAdminCanDeleteDisputedAccountWithConfirmation(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewdelete', UserAccountStatus::Inactive);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $form = $crawler->filter('form[action="/admin/users/reviews/details/'.$user->username().'/delete"]')->form();
        $form['confirm_delete']->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $updatedUser = $entityManager->find(UserAccount::class, $user->uid());
        $updatedToken = $entityManager->find(AccountToken::class, $token->uid());

        self::assertInstanceOf(UserAccount::class, $updatedUser);
        self::assertSame(UserAccountStatus::Deleted, $updatedUser->status());
        self::assertNull($updatedToken);
        $entityManager->remove($updatedUser);
        $entityManager->flush();
    }

    public function testStaleDisputeDeleteFormDoesNotDeleteRecoveredAccount(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewstaledelete', UserAccountStatus::Inactive);
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $form = $crawler->filter('form[action="/admin/users/reviews/details/'.$user->username().'/delete"]')->form();
        $form['confirm_delete']->tick();
        $user->changeStatus(UserAccountStatus::Active);
        $entityManager->remove($token);
        $entityManager->flush();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(UserAccountStatus::Active, $unchangedUser->status());

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }

    public function testStaleDisputeReactivateFormDoesNotResetRecoveredAccount(): void
    {
        $client = self::createClient();
        $this->loginTestUser($client, $this->adminUser());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser('reviewstalereactivate', UserAccountStatus::Inactive);
        $originalPassword = $user->getPassword();
        [$token] = self::getContainer()->get(AccountTokenIssuer::class)->issue(
            AccountTokenType::SecurityReview,
            $user->email(),
            [],
            $user,
        );
        $token->consume($user);
        $entityManager->persist($token);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/users/reviews');
        $form = $crawler->filter('form[action="/admin/users/reviews/details/'.$user->username().'/reactivate"]')->form();
        $user->changeStatus(UserAccountStatus::Active);
        $entityManager->remove($token);
        $entityManager->flush();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users/reviews');

        $entityManager->clear();
        $unchangedUser = $entityManager->find(UserAccount::class, $user->uid());

        self::assertInstanceOf(UserAccount::class, $unchangedUser);
        self::assertSame(UserAccountStatus::Active, $unchangedUser->status());
        self::assertSame($originalPassword, $unchangedUser->getPassword());

        $entityManager->remove($unchangedUser);
        $entityManager->flush();
    }
}
