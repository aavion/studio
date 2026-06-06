<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountTokenLookup
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountTokenIssuer $tokenIssuer,
    ) {
    }

    public function pending(string $plainToken, ?AccountTokenType $type = null): ?AccountToken
    {
        $criteria = [
            'tokenHash' => $this->tokenIssuer->hash($plainToken),
            'status' => AccountTokenStatus::Pending,
        ];

        if ($type instanceof AccountTokenType) {
            $criteria['type'] = $type;
        }

        $token = $this->entityManager->getRepository(AccountToken::class)->findOneBy($criteria);

        return $token instanceof AccountToken && !$token->isExpired() ? $token : null;
    }
}
