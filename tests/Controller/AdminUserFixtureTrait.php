<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use App\Entity\AclGroup;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\ApiKeyVault;
use App\Security\UserAccountLifecycle;
use App\Security\UserAccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait AdminUserFixtureTrait
{
    private function adminUser(): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => 'admin']);

        self::assertInstanceOf(UserAccount::class, $user);

        return $user;
    }

    private function createUser(string $username, UserAccountStatus $status): UserAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingUser = $entityManager->getRepository(UserAccount::class)->findOneBy(['username' => $username]);

        if ($existingUser instanceof UserAccount) {
            $existingUser->changeStatus($status);
            $entityManager->flush();

            return $existingUser;
        }

        $user = new UserAccount(
            '61000000-0000-7000-8000-'.substr(md5($username), 0, 12),
            $username,
            $username.'@example.test',
            'pending',
            status: $status,
        );
        $user->changePassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'current-password'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function markDeletedAt(UserAccount $user, string $deletedBy, string $deletedAt): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(UserAccountLifecycle::class)->changeStatus($user, UserAccountStatus::Deleted, $deletedBy);
        $entityManager->flush();
        $entityManager->getConnection()->update('state_marker', [
            'marker_at' => $deletedAt,
        ], [
            'subject_type' => StateSubjectType::USER_ACCOUNT,
            'subject_uid' => $user->uid(),
            'marker_key' => StateMarkerKey::STATUS_CHANGED,
        ]);
    }

    private function createGroup(string $identifier, int $accessLevel): AclGroup
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existingGroup = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if ($existingGroup instanceof AclGroup) {
            $existingGroup->changeMinRole($accessLevel);
            $entityManager->flush();

            return $existingGroup;
        }

        $group = new AclGroup(
            '62000000-0000-7000-8000-'.substr(md5($identifier), 0, 12),
            $identifier,
            ucfirst(str_replace('_', ' ', $identifier)),
            $accessLevel,
        );
        $entityManager->persist($group);

        return $group;
    }

    private function contextUserGroup(): AclGroup
    {
        $group = $this->createGroup('qa_members', AccessLevel::USER);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

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

    private function createApiKey(UserAccount $user, string $prefix): ApiKey
    {
        $vault = self::getContainer()->get(ApiKeyVault::class);
        $plainKey = $vault->generatePlainKey($prefix);
        $apiKey = new ApiKey(
            '62000000-0000-7000-8000-'.substr(md5($prefix.$user->uid()), 0, 12),
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
}
