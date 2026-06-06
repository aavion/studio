<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait UserControllerFixtureTrait
{
    private function createUserWithLevel(int $level, string $username, string $password): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus(UserAccountStatus::Active);
            $existingUser->changeRole(UserRole::fromAccessLevel($level));
            $existingUser->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($existingUser, $password));
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '60000000-0000-7000-8000-'.substr(md5($username), 0, 12),
            $username,
            $username.'@example.test',
            'pending',
            role: UserRole::fromAccessLevel($level),
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createApiKey(UserAccount $user, string $prefix): ApiKey
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '63000000-0000-7000-8000-'.substr(md5($prefix.$user->uid()), 0, 12),
            $prefix,
            $vault->hmac($plainKey),
            $vault->encrypt($plainKey, $prefix),
            $user,
            ApiKeyStatus::ReadWrite,
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiKey);

        return $apiKey;
    }

    private function createGroup(string $identifier, int $minRole): AclGroup
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if ($existingGroup instanceof AclGroup) {
            $existingGroup->changeMinRole($minRole);

            return $existingGroup;
        }

        $group = new AclGroup(
            '62000000-0000-7000-8000-'.substr(md5($identifier), 0, 12),
            $identifier,
            ucfirst(str_replace('_', ' ', $identifier)),
            $minRole,
        );
        $entityManager->persist($group);

        return $group;
    }

    /**
     * @return list<string>
     */
    private function userGroupIdentifiers(UserAccount $user): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return $identifiers;
    }
}
