<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use App\Entity\AclGroup;

final readonly class AclGroupAccountTokenReferenceProvider implements AclGroupReferenceProviderInterface
{
    public function __construct(
        private AclGroupReferenceQuery $references,
        private AclGroupReferenceValues $values,
    ) {
    }

    public function key(): string
    {
        return 'account_tokens';
    }

    public function impact(AclGroup $group): array
    {
        $rows = [];

        foreach ($this->tokens($group->identifier()) as $token) {
            $rows[] = [
                'uid' => $token->uid(),
                'email' => $token->email(),
                'type' => $token->type()->value,
                'status' => $token->status()->value,
                'fields' => ['groups'],
            ];
        }

        return $rows;
    }

    public function removeReferences(AclGroup $group): void
    {
        $identifier = $group->identifier();

        foreach ($this->tokens($identifier) as $token) {
            $token->updateGroups($this->values->withoutIdentifier($token->groupIdentifiers(), $identifier));
        }
    }

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int
    {
        $removed = 0;
        $identifier = $group->identifier();

        foreach ($this->tokens($identifier) as $token) {
            if ($token->role()->accessLevel() >= $minRole) {
                continue;
            }

            $token->updateGroups($this->values->withoutIdentifier($token->groupIdentifiers(), $identifier));
            ++$removed;
        }

        return $removed;
    }

    /**
     * @return list<AccountToken>
     */
    private function tokens(string $identifier): array
    {
        return $this->references->entitiesByJsonColumns(
            AccountToken::class,
            'account_token',
            ['group_identifiers'],
            $identifier,
        );
    }
}
