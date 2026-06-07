<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Entity\AccountToken;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiUserControllerTest extends WebTestCase
{
    use UserControllerFixtureTrait;

    public function testUsersRejectAnonymousAccess(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUsersReturnFilteredUserListForAdminApiKeys(): void
    {
        $client = self::createClient();
        $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiusertarget', 'current-password');
        $plainKey = $this->createPlainApiKey('apiuseradm');

        $client->request('GET', '/api/v1/admin/users?q=apiusertarget&per_page=5', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(1, $payload['meta']['pagination']['total']);

        $user = $payload['data'][0];
        self::assertSame('user', $user['type']);
        self::assertSame('apiusertarget', $user['attributes']['username']);
        self::assertSame('author', $user['attributes']['role']);
        self::assertSame(AccessLevel::AUTHOR, $user['attributes']['access_level']);
    }

    public function testUserDetailReturnsOneUserForAdminApiKeys(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiuserdetail', 'current-password');
        $plainKey = $this->createPlainApiKey('apiuserdetailadm');

        $client->request('GET', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('user', $payload['data']['type']);
        self::assertSame($target->username(), $payload['data']['id']);
        self::assertSame($target->uid(), $payload['data']['attributes']['uid']);
        self::assertSame('apiuserdetail', $payload['data']['attributes']['username']);
    }

    public function testUserCanBePatchedWithReadWriteAdminApiKeys(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiuserpatch', 'current-password');
        $plainKey = $this->createPlainApiKey('apiuserwrite', ApiKeyStatus::ReadWrite);

        $client->request('PATCH', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'status' => 'inactive',
            'role' => 'moderator',
            'groups' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('inactive', $payload['data']['attributes']['status']);
        self::assertSame('moderator', $payload['data']['attributes']['role']);
        self::assertSame(AccessLevel::MODERATOR, $payload['data']['attributes']['access_level']);
        self::assertSame('user.account_updated', $payload['meta']['audit_action']);
    }

    public function testUserPatchReturnsValidationErrors(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiuserbad', 'current-password');
        $plainKey = $this->createPlainApiKey('apiuserbadadm', ApiKeyStatus::ReadWrite);

        $client->request('PATCH', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'status' => 'unknown',
            'role' => 'public',
            'groups' => ['missing_group'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('status', $payload['error']['context']['errors']);
        self::assertArrayHasKey('role', $payload['error']['context']['errors']);
    }

    public function testUserPatchRequiresWriteApiKey(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiuserreadonly', 'current-password');
        $plainKey = $this->createPlainApiKey('apiusrro');

        $client->request('PATCH', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'status' => 'inactive',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserGroupAndReviewEndpointsReturnBasicListsForAdminApiKeys(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiusrsub');

        foreach (['/api/v1/admin/users/groups', '/api/v1/admin/users/reviews'] as $path) {
            $client->request('GET', $path, server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            ]);

            self::assertResponseIsSuccessful($path);
            $payload = $this->jsonPayload($client->getResponse()->getContent());
            self::assertArrayHasKey('data', $payload, $path);
            self::assertArrayHasKey('pagination', $payload['meta'], $path);
        }
    }

    public function testAclGroupsCanBeCreatedAndEditedWithConfirmation(): void
    {
        $client = self::createClient();
        $plainKey = $this->createPlainApiKey('apiusrgrpwrite', ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/admin/users/groups', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'identifier' => 'api_group_alpha',
            'name' => 'API Group Alpha',
            'min_role' => AccessLevel::USER,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('acl_group', $payload['data']['type']);
        $groupIdentifier = $payload['data']['id'];
        self::assertSame('api_group_alpha', $groupIdentifier);
        self::assertSame('api_group_alpha', $payload['data']['attributes']['identifier']);
        self::assertArrayHasKey('uid', $payload['data']['attributes']);

        $client->request('PATCH', '/api/v1/admin/users/groups/items/'.$groupIdentifier, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'name' => 'API Group Alpha Updated',
            'min_role' => AccessLevel::USER,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('acl_group_review', $payload['data']['type']);
        self::assertSame('requires_confirmation', $payload['data']['attributes']['status']);
        self::assertSame('/api/v1/admin/users/groups/items/'.$groupIdentifier.'?confirm=true', $payload['links']['confirm']);

        $client->request('PATCH', '/api/v1/admin/users/groups/items/'.$groupIdentifier.'?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'name' => 'API Group Alpha Updated',
            'min_role' => AccessLevel::USER,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('API Group Alpha Updated', $payload['data']['attributes']['name']);
    }

    public function testUserGroupMembershipCanBeAddedAndRemoved(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiusermember', 'current-password');
        $group = $this->createGroup('api_member_group', AccessLevel::USER);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $plainKey = $this->createPlainApiKey('apiusrmember', ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/admin/users/items/'.$target->username().'/groups/'.$group->identifier(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertContains($group->identifier(), array_column($payload['data']['attributes']['groups'], 'identifier'));

        $client->request('DELETE', '/api/v1/admin/users/items/'.$target->username().'/groups/'.$group->identifier(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertNotContains($group->identifier(), array_column($payload['data']['attributes']['groups'], 'identifier'));
    }

    public function testUserReviewActionsRequireConfirmation(): void
    {
        $client = self::createClient();
        $target = $this->createSecurityReviewUser('apiuserreview');
        $plainKey = $this->createPlainApiKey('apiusrreview', ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/admin/users/reviews/items/'.$target->username().'/reactivate', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('user_review_action', $payload['data']['type']);
        self::assertSame('reactivate', $payload['data']['attributes']['action']);
        self::assertSame('/api/v1/admin/users/reviews/items/'.$target->username().'/reactivate?confirm=true', $payload['links']['confirm']);

        $client->request('DELETE', '/api/v1/admin/users/reviews/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('deny', $payload['data']['attributes']['action']);
        self::assertSame('/api/v1/admin/users/reviews/items/'.$target->username().'?confirm=true', $payload['links']['confirm']);
    }

    public function testRegistrationReviewCanBeApprovedAndDeniedByToken(): void
    {
        $client = self::createClient();
        $token = $this->createPendingApprovalToken('apiapproval@example.test');
        $plainKey = $this->createPlainApiKey('apiusrtoken', ApiKeyStatus::ReadWrite);

        $client->request('GET', '/api/v1/admin/users/reviews?filter=registrations', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame($token->uid(), $payload['data'][0]['id']);
        self::assertSame('registration_approval', $payload['data'][0]['attributes']['kind']);
        self::assertSame('/api/v1/admin/users/reviews/tokens/'.$token->uid().'/approve', $payload['data'][0]['links']['approve']);

        $client->request('POST', '/api/v1/admin/users/reviews/tokens/'.$token->uid().'/approve', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('user_review_token_action', $payload['data']['type']);
        self::assertSame('approve', $payload['data']['attributes']['action']);
        self::assertSame('/api/v1/admin/users/reviews/tokens/'.$token->uid().'/approve?confirm=true', $payload['links']['confirm']);

        $client->request('POST', '/api/v1/admin/users/reviews/tokens/'.$token->uid().'/approve?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $approvedToken = $entityManager->find(AccountToken::class, $token->uid());
        self::assertInstanceOf(AccountToken::class, $approvedToken);
        self::assertSame(AccountTokenStatus::Pending, $approvedToken->status());

        $deniedToken = $this->createPendingApprovalToken('apidenied@example.test');
        $client->request('DELETE', '/api/v1/admin/users/reviews/tokens/'.$deniedToken->uid().'?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $revokedToken = $entityManager->find(AccountToken::class, $deniedToken->uid());
        self::assertInstanceOf(AccountToken::class, $revokedToken);
        self::assertSame(AccountTokenStatus::Revoked, $revokedToken->status());
    }

    public function testOpenApiIncludesUsersEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertArrayHasKey('/admin/users', $payload['paths']);
        self::assertArrayHasKey('/admin/users/items/{username}', $payload['paths']);
        self::assertArrayHasKey('/admin/users/groups', $payload['paths']);
        self::assertArrayHasKey('/admin/users/groups/items/{group_identifier}', $payload['paths']);
        self::assertArrayHasKey('/admin/users/items/{username}/groups/{group_identifier}', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews/items/{username}/reactivate', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews/items/{username}', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews/tokens/{token_uid}/approve', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews/tokens/{token_uid}/reissue', $payload['paths']);
        self::assertArrayHasKey('/admin/users/reviews/tokens/{token_uid}', $payload['paths']);
        self::assertSame('listUsers', $payload['paths']['/admin/users']['get']['operationId']);
        self::assertSame(['backend-admin', 'backend-admin-users'], $payload['paths']['/admin/users']['get']['tags']);
        self::assertSame('getUser', $payload['paths']['/admin/users/items/{username}']['get']['operationId']);
        self::assertSame('updateUser', $payload['paths']['/admin/users/items/{username}']['patch']['operationId']);
        self::assertSame('listUserGroups', $payload['paths']['/admin/users/groups']['get']['operationId']);
        self::assertSame('createUserGroup', $payload['paths']['/admin/users/groups']['post']['operationId']);
        self::assertSame('getUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['get']['operationId']);
        self::assertSame('updateUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['patch']['operationId']);
        self::assertSame('deleteUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['delete']['operationId']);
        self::assertSame('addUserGroupMembership', $payload['paths']['/admin/users/items/{username}/groups/{group_identifier}']['post']['operationId']);
        self::assertSame('removeUserGroupMembership', $payload['paths']['/admin/users/items/{username}/groups/{group_identifier}']['delete']['operationId']);
        self::assertSame('listUserReviews', $payload['paths']['/admin/users/reviews']['get']['operationId']);
        self::assertSame('reactivateUserReview', $payload['paths']['/admin/users/reviews/items/{username}/reactivate']['post']['operationId']);
        self::assertSame('denyUserReview', $payload['paths']['/admin/users/reviews/items/{username}']['delete']['operationId']);
        self::assertSame('approveUserRegistrationReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}/approve']['post']['operationId']);
        self::assertSame('reissueUserAccountTokenReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}/reissue']['post']['operationId']);
        self::assertSame('denyUserAccountTokenReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}']['delete']['operationId']);
        self::assertContains([
            'name' => 'backend-admin-users',
            'summary' => 'Backend Admin Users',
            'description' => 'Administrative user, ACL group, and review resources.',
            'parent' => 'backend-admin',
            'kind' => 'nav',
        ], $payload['tags']);
    }

    private function createPlainApiKey(string $prefix, ApiKeyStatus $status = ApiKeyStatus::ReadOnly): string
    {
        $user = $this->createUserWithLevel(AccessLevel::ADMIN, $prefix.'user', 'current-password');
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '69000000-0000-7000-8000-'.substr(md5($prefix.$status->value), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            $status,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);
        $entityManager->flush();

        return $plainKey;
    }

    private function createSecurityReviewUser(string $username): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUserWithLevel(AccessLevel::AUTHOR, $username, 'current-password');
        $user->changeStatus(UserAccountStatus::Inactive);
        $token = new AccountToken(
            '69000000-0000-7000-8001-'.substr(md5($username.'review'), 0, 12),
            str_repeat('a', 64),
            AccountTokenType::SecurityReview,
            $user->email(),
            user: $user,
            status: AccountTokenStatus::Used,
        );
        $entityManager->persist($token);
        $entityManager->flush();

        return $user;
    }

    private function createPendingApprovalToken(string $email): AccountToken
    {
        $token = new AccountToken(
            '69000000-0000-7000-8002-'.substr(md5($email), 0, 12),
            hash('sha256', $email),
            AccountTokenType::Registration,
            $email,
            status: AccountTokenStatus::PendingApproval,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
