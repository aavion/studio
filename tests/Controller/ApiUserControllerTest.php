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

        $client->request('GET', '/api/v1/admin/users?q=apiusertarget&limit=25', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(25, $payload['meta']['pagination']['limit']);
        self::assertArrayNotHasKey('per_page', $payload['meta']['pagination']);
        self::assertArrayNotHasKey('total_pages', $payload['meta']['pagination']);
        self::assertSame(25, $payload['meta']['filters']['limit']);
        self::assertArrayNotHasKey('per_page', $payload['meta']['filters']);
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

    public function testUserDetailRejectsRetainedDeletedUsers(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiuserdeleted', 'current-password');
        $target->changeStatus(UserAccountStatus::Deleted);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $plainKey = $this->createPlainApiKey('apiuserdeladm');
        $writeKey = $this->createPlainApiKey('apiuserdelwr', ApiKeyStatus::ReadWrite);

        $client->request('GET', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(404);

        $client->request('PATCH', '/api/v1/admin/users/items/'.$target->username(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$writeKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
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

    public function testCurrentUserProfileRequiresApiKey(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/user');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCurrentUserProfileReturnsAuthenticatedUser(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(AccessLevel::USER, 'apiselfprofile', 'current-password');
        $plainKey = $this->createPlainApiKeyForUser($user, 'apiselfro');

        $client->request('GET', '/api/v1/user', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('user_profile', $payload['data']['type']);
        self::assertSame($user->username(), $payload['data']['id']);
        self::assertSame($user->email(), $payload['data']['attributes']['email']);
        self::assertSame('/api/v1/user/api-keys', $payload['data']['links']['api_keys']);
    }

    public function testCurrentUserProfileCanBePatchedWithReadWriteKey(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(AccessLevel::USER, 'apiselfpatch', 'current-password');
        $plainKey = $this->createPlainApiKeyForUser($user, 'apiselfrw', ApiKeyStatus::ReadWrite);

        $client->request('PATCH', '/api/v1/user', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => 'api-self-patch@example.test',
            'display_name' => 'API Self Patch',
            'language' => 'de',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api-self-patch@example.test', $payload['data']['attributes']['email']);
        self::assertSame('API Self Patch', $payload['data']['attributes']['display_name']);
        self::assertSame('de', $payload['data']['attributes']['language']);
        self::assertSame(['email', 'display_name', 'language'], $payload['meta']['updated_fields']);
    }

    public function testCurrentUserProfilePatchRequiresReadWriteKey(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(AccessLevel::USER, 'apiselfreadonly', 'current-password');
        $plainKey = $this->createPlainApiKeyForUser($user, 'apiselfkey');

        $client->request('PATCH', '/api/v1/user', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'display_name' => 'Should Not Persist',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCurrentUserApiKeysCanBeListedCreatedAndRevoked(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(AccessLevel::USER, 'apiselfkeys', 'current-password');
        $plainKey = $this->createPlainApiKeyForUser($user, 'apiselfmgmt', ApiKeyStatus::ReadWrite);

        $client->request('GET', '/api/v1/user/api-keys', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame(1, $payload['meta']['count']);
        self::assertSame('apiselfmgmt', $payload['data'][0]['id']);

        $client->request('POST', '/api/v1/user/api-keys', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'prefix' => 'apiselfnew',
            'read_only' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $created = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('apiselfnew', $created['data']['attributes']['prefix']);
        self::assertSame('read_only', $created['data']['attributes']['status']);
        self::assertStringStartsWith('apiselfnew.', $created['data']['attributes']['plain_key']);
        $revokeLink = $created['data']['links']['revoke'];

        $client->request('DELETE', $revokeLink, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $revoked = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('revoked', $revoked['data']['attributes']['status']);
        self::assertArrayNotHasKey('revoke', $revoked['data']['links']);
    }

    public function testCurrentUserApiKeyCreationReturnsValidationDetails(): void
    {
        $client = self::createClient();
        $user = $this->createUserWithLevel(AccessLevel::USER, 'apiselfinvalid', 'current-password');
        $plainKey = $this->createPlainApiKeyForUser($user, 'apiselfbad', ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/user/api-keys', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'prefix' => 'x',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('api.validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('prefix', $payload['error']['details']['fields']);
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

        $client->request('DELETE', '/api/v1/admin/users/groups/items/'.$groupIdentifier, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('acl_group_review', $payload['data']['type']);
        self::assertSame('delete', $payload['data']['attributes']['operation']);
        self::assertSame('/api/v1/admin/users/groups/items/'.$groupIdentifier.'?confirm=true', $payload['links']['confirm']);

        $client->request('DELETE', '/api/v1/admin/users/groups/items/'.$groupIdentifier.'?confirm=true', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonPayload($client->getResponse()->getContent());
        self::assertSame('acl_group_delete_result', $payload['data']['type']);
        self::assertSame('deleted', $payload['data']['attributes']['status']);
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

    public function testUserGroupMembershipRejectsRetainedDeletedUsers(): void
    {
        $client = self::createClient();
        $target = $this->createUserWithLevel(AccessLevel::AUTHOR, 'apiusermemdel', 'current-password');
        $existingGroup = $this->createGroup('api_deleted_existing', AccessLevel::USER);
        $newGroup = $this->createGroup('api_deleted_new', AccessLevel::USER);
        $target->addGroup($existingGroup);
        $target->changeStatus(UserAccountStatus::Deleted);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $plainKey = $this->createPlainApiKey('apiusrmemdel', ApiKeyStatus::ReadWrite);

        $client->request('POST', '/api/v1/admin/users/items/'.$target->username().'/groups/'.$newGroup->identifier(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(404);

        $client->request('DELETE', '/api/v1/admin/users/items/'.$target->username().'/groups/'.$existingGroup->identifier(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainKey,
        ]);

        self::assertResponseStatusCodeSame(404);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $unchanged = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $target->username()]);

        self::assertInstanceOf(UserAccount::class, $unchanged);
        self::assertSame(UserAccountStatus::Deleted, $unchanged->status());
        self::assertSame([$existingGroup->identifier()], $this->userGroupIdentifiers($unchanged));
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
        self::assertArrayHasKey('/user', $payload['paths']);
        self::assertArrayHasKey('/user/api-keys', $payload['paths']);
        self::assertArrayHasKey('/user/api-keys/items/{key_uid}', $payload['paths']);
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
        self::assertSame('getCurrentUserProfile', $payload['paths']['/user']['get']['operationId']);
        self::assertSame('updateCurrentUserProfile', $payload['paths']['/user']['patch']['operationId']);
        self::assertSame(['frontend-user', 'frontend-user-profile'], $payload['paths']['/user']['get']['tags']);
        self::assertSame('listCurrentUserApiKeys', $payload['paths']['/user/api-keys']['get']['operationId']);
        self::assertSame('createCurrentUserApiKey', $payload['paths']['/user/api-keys']['post']['operationId']);
        self::assertSame('revokeCurrentUserApiKey', $payload['paths']['/user/api-keys/items/{key_uid}']['delete']['operationId']);
        self::assertSame('listUsers', $payload['paths']['/admin/users']['get']['operationId']);
        self::assertSame(['backend-admin', 'backend-admin-users'], $payload['paths']['/admin/users']['get']['tags']);
        self::assertSame('getUser', $payload['paths']['/admin/users/items/{username}']['get']['operationId']);
        self::assertSame('updateUser', $payload['paths']['/admin/users/items/{username}']['patch']['operationId']);
        self::assertSame('listUserGroups', $payload['paths']['/admin/users/groups']['get']['operationId']);
        self::assertSame('createUserGroup', $payload['paths']['/admin/users/groups']['post']['operationId']);
        self::assertSame('getUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['get']['operationId']);
        self::assertSame('updateUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['patch']['operationId']);
        self::assertSame('deleteUserGroup', $payload['paths']['/admin/users/groups/items/{group_identifier}']['delete']['operationId']);
        self::assertArrayNotHasKey('requestBody', $payload['paths']['/admin/users/groups/items/{group_identifier}']['delete']);
        self::assertSame('addUserGroupMembership', $payload['paths']['/admin/users/items/{username}/groups/{group_identifier}']['post']['operationId']);
        self::assertSame('removeUserGroupMembership', $payload['paths']['/admin/users/items/{username}/groups/{group_identifier}']['delete']['operationId']);
        self::assertSame('listUserReviews', $payload['paths']['/admin/users/reviews']['get']['operationId']);
        self::assertSame('reactivateUserReview', $payload['paths']['/admin/users/reviews/items/{username}/reactivate']['post']['operationId']);
        self::assertSame('denyUserReview', $payload['paths']['/admin/users/reviews/items/{username}']['delete']['operationId']);
        self::assertSame('approveUserRegistrationReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}/approve']['post']['operationId']);
        self::assertSame('reissueUserAccountTokenReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}/reissue']['post']['operationId']);
        self::assertSame('denyUserAccountTokenReview', $payload['paths']['/admin/users/reviews/tokens/{token_uid}']['delete']['operationId']);
        self::assertContains([
            'name' => 'frontend-user',
            'summary' => 'Frontend User',
            'description' => 'Authenticated user self-service resources.',
            'kind' => 'nav',
        ], $payload['tags']);
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

    private function createPlainApiKeyForUser(UserAccount $user, string $prefix, ApiKeyStatus $status = ApiKeyStatus::ReadOnly): string
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '69000000-0000-7000-8003-'.substr(md5($prefix.$user->uid().$status->value), 0, 12),
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
