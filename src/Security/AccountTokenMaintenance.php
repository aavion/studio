<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountTokenMaintenance
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<AccountTokenType> $types
     */
    public function revokePendingForEmail(string $email, array $types): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'email' => strtolower($email),
            'type' => $types,
            'status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval],
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $token->revoke();
            }
        }
    }

    /**
     * @param list<AccountTokenType> $types
     */
    public function revokePendingForUser(UserAccount $user, array $types): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => $types,
            'status' => AccountTokenStatus::Pending,
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $token->revoke();
            }
        }
    }
}
