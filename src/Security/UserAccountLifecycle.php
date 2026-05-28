<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\State\StateMarkerKey;
use App\Core\State\StateMarkerRecorder;
use App\Core\State\StateSubjectType;
use App\Entity\AccountToken;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserAccountLifecycle
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StateMarkerRecorder $stateMarkers,
    ) {
    }

    /**
     * @return array{api_keys_revoked: int, account_tokens_revoked: int}
     */
    public function changeStatus(UserAccount $user, UserAccountStatus $status, ?string $changedBy = null): array
    {
        $oldStatus = $user->status();
        $user->changeStatus($status);

        if ($oldStatus !== $status) {
            $this->stateMarkers->record(
                StateSubjectType::USER_ACCOUNT,
                $user->uid(),
                StateMarkerKey::STATUS_CHANGED,
                $changedBy,
                $status->value,
                ['old_status' => $oldStatus->value, 'new_status' => $status->value],
            );
        }

        if ($status->isUsable()) {
            return [
                'api_keys_revoked' => 0,
                'account_tokens_revoked' => 0,
            ];
        }

        return [
            'api_keys_revoked' => $this->revokeActiveApiKeys($user),
            'account_tokens_revoked' => $this->revokePendingRecoveryTokens($user),
        ];
    }

    private function revokeActiveApiKeys(UserAccount $user): int
    {
        $count = 0;
        $apiKeys = $this->entityManager->getRepository(ApiKey::class)->findBy([
            'user' => $user,
            'status' => [ApiKeyStatus::ReadOnly, ApiKeyStatus::ReadWrite],
        ]);

        foreach ($apiKeys as $apiKey) {
            if (!$apiKey instanceof ApiKey) {
                continue;
            }

            $apiKey->revoke();
            ++$count;
        }

        return $count;
    }

    private function revokePendingRecoveryTokens(UserAccount $user): int
    {
        $count = 0;
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => [AccountTokenType::PasswordReset, AccountTokenType::SecurityReview],
            'status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval],
        ]);

        foreach ($tokens as $token) {
            if (!$token instanceof AccountToken) {
                continue;
            }

            $token->revoke();
            ++$count;
        }

        return $count;
    }
}
