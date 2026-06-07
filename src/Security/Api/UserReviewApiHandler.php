<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\Message;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use App\Security\AccountLinkDeliveryInterface;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use App\Security\AdminUserInvitationWorkflow;
use App\Security\AdminUserReviewViewFactory;
use App\Security\AdminUserAccessPolicy;
use App\Security\UserAccountLifecycle;
use App\Security\UserAccountStatus;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserReviewApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminUserReviewViewFactory $reviews,
        private EntityManagerInterface $entityManager,
        private AdminUserAccessPolicy $policy,
        private UserAccountLifecycle $userLifecycle,
        private UserApiReadModel $userReadModel,
        private AccountLinkDeliveryInterface $linkDelivery,
        private AdminUserInvitationWorkflow $invitationWorkflow,
        private MailLocaleResolver $mailLocaleResolver,
        private UserPasswordHasherInterface $passwordHasher,
        private StateMarkerRecorder $stateMarkers,
        private AuditLoggerInterface $auditLogger,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return UserApiEndpointProvider::HANDLER_USER_REVIEWS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $tokenAction = $this->tokenActionFromPath($request->getPathInfo());
        if (null !== $tokenAction) {
            return $this->reviewTokenAction($request, $tokenAction['token_uid'], $tokenAction['action']);
        }

        $action = $this->actionFromPath($request->getPathInfo());
        if (null !== $action) {
            return $this->reviewAction($request, $action['username'], $action['action']);
        }

        $view = $this->reviews->reviewView($request);
        $items = array_map($this->resource(...), $view['items']);
        unset($view['items']);

        return $this->responder->data($items, meta: $view);
    }

    private function reviewAction(Request $request, string $username, string $action): Response
    {
        $user = $this->entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);
        if (!$user instanceof UserAccount) {
            return $this->notFound($request, $username);
        }

        if (!$this->hasUnresolvedSecurityReview($user)) {
            return $this->validationFailed($request, ['__review' => ['admin.users.invitation.unavailable']], ['username' => $username]);
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        $policyError = null === $actor ? 'admin.users.form.errors.invalid' : $this->policy->validateUserAction($actor, $user);
        if (null !== $policyError) {
            return $this->validationFailed($request, ['__form' => [$policyError]], ['username' => $username]);
        }

        if ('deny' === $action && !$this->policy->allowsAccountClosure($user)) {
            return $this->validationFailed($request, ['__form' => ['admin.users.form.errors.last_owner']], ['username' => $username]);
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->reviewConfirmation($user, $action);
        }

        return 'reactivate' === $action
            ? $this->reactivate($request, $user)
            : $this->deny($request, $user);
    }

    private function reviewTokenAction(Request $request, string $tokenUid, string $action): Response
    {
        $token = $this->entityManager->find(AccountToken::class, $tokenUid);
        if (!$token instanceof AccountToken) {
            return $this->notFound($request, $tokenUid);
        }

        if (!$this->tokenActionAvailable($token, $action)) {
            return $this->validationFailed($request, ['__review' => ['admin.users.invitation.unavailable']], ['token_uid' => $tokenUid]);
        }

        $actor = ApiRequestContext::fromRequest($request)?->actor();
        if (null === $actor) {
            return $this->validationFailed($request, ['__actor' => ['admin.users.form.errors.invalid']], ['token_uid' => $tokenUid]);
        }

        if (!$request->query->getBoolean('confirm')) {
            return $this->tokenReviewConfirmation($token, $action);
        }

        $result = match ($action) {
            'approve' => $this->invitationWorkflow->approve($actor, $tokenUid),
            'reissue' => $this->invitationWorkflow->reissue($actor, $tokenUid),
            default => $this->invitationWorkflow->revoke($actor, $tokenUid),
        };

        if ('error' === $result->successLevel()) {
            return $this->validationFailed($request, ['__form' => [$result->flashKey()]], ['token_uid' => $tokenUid]);
        }

        return $this->responder->data([
            'type' => 'user_review_token_action_result',
            'id' => $tokenUid,
            'attributes' => [
                'status' => 'completed',
                'action' => $action,
                'message' => $result->flashKey(),
            ],
            'links' => [
                'reviews' => '/api/v1/admin/users/reviews',
            ],
        ]);
    }

    private function reactivate(Request $request, UserAccount $user): Response
    {
        $actorName = ApiRequestContext::fromRequest($request)?->actor()->username();
        $user->changePassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $this->stateMarkers->record(StateSubjectType::USER_ACCOUNT, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $actorName, 'reactivated');
        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Active, $actorName);
        $this->deleteUsedSecurityReviewTokens($user);
        $this->entityManager->flush();
        $this->linkDelivery->notifyAddress($user->email(), AccountMailFlow::PasswordChangeReactivated, $this->mailLocaleResolver->forAdminAction($user), [
            'username' => $user->username(),
            'user_uid' => $user->uid(),
        ]);
        $this->audit($request, 'user.security_review_reactivated', ['target_user' => $user->uid(), ...$effects]);

        return $this->responder->data($this->userReadModel->resource($user, includeUid: true), meta: [
            'action' => 'reactivate',
            'effects' => $effects,
        ]);
    }

    private function deny(Request $request, UserAccount $user): Response
    {
        $actorName = ApiRequestContext::fromRequest($request)?->actor()->username();
        $effects = $this->userLifecycle->changeStatus($user, UserAccountStatus::Deleted, $actorName);
        $this->deleteUsedSecurityReviewTokens($user);
        $this->entityManager->flush();
        $this->audit($request, 'user.security_review_deleted', ['target_user' => $user->uid(), ...$effects]);

        return $this->responder->data($this->userReadModel->resource($user, includeUid: true), meta: [
            'action' => 'deny',
            'effects' => $effects,
        ]);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function resource(array $item): array
    {
        $requestedAt = $item['requested_at'] ?? null;

        $token = $item['token'] ?? null;
        $user = $item['user'] ?? null;
        $id = $token instanceof AccountToken
            ? $token->uid()
            : ($user instanceof UserAccount ? $user->username() : sha1((string) ($item['kind'] ?? '').'|'.(string) ($item['email'] ?? '').'|'.(string) ($item['status'] ?? '')));

        $resource = [
            'type' => 'user_review',
            'id' => $id,
            'attributes' => [
                'kind' => $item['kind'] ?? null,
                'filter' => $item['filter'] ?? null,
                'status' => $item['status'] ?? null,
                'expired' => (bool) ($item['expired'] ?? false),
                'email' => $item['email'] ?? null,
                'username' => $item['username'] ?? null,
                'requested_at' => $requestedAt instanceof DateTimeInterface ? $requestedAt->format(DATE_ATOM) : null,
                'role' => $item['role'] ?? null,
                'groups' => is_array($item['groups'] ?? null) ? $item['groups'] : [],
            ],
        ];

        if ($token instanceof AccountToken) {
            $resource['attributes']['token_uid'] = $token->uid();
            $resource['links'] = $this->tokenReviewLinks($token);
        }

        if ($user instanceof UserAccount) {
            $resource['links'] = [
                'user' => '/api/v1/admin/users/items/'.$user->username(),
                'reactivate' => '/api/v1/admin/users/reviews/items/'.$user->username().'/reactivate',
                'deny' => '/api/v1/admin/users/reviews/items/'.$user->username(),
            ];
        }

        return $resource;
    }

    private function reviewConfirmation(UserAccount $user, string $action): Response
    {
        $path = 'reactivate' === $action
            ? '/api/v1/admin/users/reviews/items/'.$user->username().'/reactivate'
            : '/api/v1/admin/users/reviews/items/'.$user->username();
        $links = [
            'self' => $path,
            'confirm' => $path.'?confirm=true',
            'user' => '/api/v1/admin/users/items/'.$user->username(),
        ];

        return $this->responder->data([
            'type' => 'user_review_action',
            'id' => $user->username(),
            'attributes' => [
                'status' => 'requires_confirmation',
                'action' => $action,
                'confirm_parameter' => 'confirm=true',
                'target_user' => [
                    'username' => $user->username(),
                    'email' => $user->email(),
                    'status' => $user->status()->value,
                    'role' => $user->role()->value,
                ],
            ],
            'links' => $links,
        ], links: $links);
    }

    private function tokenReviewConfirmation(AccountToken $token, string $action): Response
    {
        $links = $this->tokenReviewLinks($token);
        $self = (string) ($links[$action] ?? $links['self']);
        $links['self'] = $self;
        $links['confirm'] = $self.'?confirm=true';

        return $this->responder->data([
            'type' => 'user_review_token_action',
            'id' => $token->uid(),
            'attributes' => [
                'status' => 'requires_confirmation',
                'action' => $action,
                'confirm_parameter' => 'confirm=true',
                'target_token' => [
                    'uid' => $token->uid(),
                    'type' => $token->type()->value,
                    'status' => $token->status()->value,
                    'email' => $token->email(),
                    'role' => $token->role()->value,
                    'groups' => $token->groupIdentifiers(),
                ],
            ],
            'links' => $links,
        ], links: $links);
    }

    /**
     * @return array<string, string>
     */
    private function tokenReviewLinks(AccountToken $token): array
    {
        $base = '/api/v1/admin/users/reviews/tokens/'.$token->uid();
        $links = [
            'self' => $base,
            'deny' => $base,
        ];

        if (AccountTokenType::Registration === $token->type() && AccountTokenStatus::PendingApproval === $token->status()) {
            $links['approve'] = $base.'/approve';
        }

        if (AccountTokenStatus::Pending === $token->status()) {
            $links['reissue'] = $base.'/reissue';
        }

        return $links;
    }

    /**
     * @return array{username: string, action: string}|null
     */
    private function actionFromPath(string $path): ?array
    {
        if (1 === preg_match('#^/api/v1/admin/users/reviews/items/([A-Za-z][A-Za-z0-9_-]{4,29})/reactivate$#', $path, $matches)) {
            return ['username' => rawurldecode($matches[1]), 'action' => 'reactivate'];
        }

        if (1 === preg_match('#^/api/v1/admin/users/reviews/items/([A-Za-z][A-Za-z0-9_-]{4,29})$#', $path, $matches)) {
            return ['username' => rawurldecode($matches[1]), 'action' => 'deny'];
        }

        return null;
    }

    /**
     * @return array{token_uid: string, action: string}|null
     */
    private function tokenActionFromPath(string $path): ?array
    {
        if (1 === preg_match('#^/api/v1/admin/users/reviews/tokens/([a-f0-9-]{36})/(approve|reissue)$#', $path, $matches)) {
            return ['token_uid' => $matches[1], 'action' => $matches[2]];
        }

        if (1 === preg_match('#^/api/v1/admin/users/reviews/tokens/([a-f0-9-]{36})$#', $path, $matches)) {
            return ['token_uid' => $matches[1], 'action' => 'deny'];
        }

        return null;
    }

    private function tokenActionAvailable(AccountToken $token, string $action): bool
    {
        return match ($action) {
            'approve' => AccountTokenType::Registration === $token->type() && AccountTokenStatus::PendingApproval === $token->status(),
            'reissue' => AccountTokenStatus::Pending === $token->status(),
            default => in_array($token->status(), [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval], true),
        };
    }

    private function hasUnresolvedSecurityReview(UserAccount $user): bool
    {
        if (UserAccountStatus::Inactive !== $user->status()) {
            return false;
        }

        return $this->entityManager->getRepository(AccountToken::class)->findOneBy([
            'user' => $user,
            'type' => AccountTokenType::SecurityReview,
            'status' => AccountTokenStatus::Used,
        ]) instanceof AccountToken;
    }

    private function deleteUsedSecurityReviewTokens(UserAccount $user): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => AccountTokenType::SecurityReview,
            'status' => AccountTokenStatus::Used,
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $this->entityManager->remove($token);
            }
        }
    }

    private function notFound(Request $request, string $username): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'username' => $username,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $context
     */
    private function validationFailed(Request $request, array $errors, array $context = []): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_VALIDATION_FAILED, ApiMessageKey::API_VALIDATION_FAILED, context: [
                ...$context,
                'path' => $request->getPathInfo(),
                'errors' => $errors,
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(Request $request, string $action, array $context): void
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor();
        if (null === $actor) {
            return;
        }

        $this->auditLogger->log($actor, $action, [
            ...$context,
            'result_status' => 'success',
        ]);
    }
}
