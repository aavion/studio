<?php

declare(strict_types=1);

namespace App\Tests\Core\AdminAcl;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\AdminAcl\AdminPermissionState;
use App\Entity\AclGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminFeatureAccessPolicyTest extends KernelTestCase
{
    public function testAclGroupOverridesGrantPermissionOnlyAfterSurfaceGate(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $overrides = self::getContainer()->get(AdminFeatureOverrideStore::class);
        self::assertInstanceOf(AdminFeatureOverrideStore::class, $overrides);
        $policy = self::getContainer()->get(AdminFeatureAccessPolicy::class);
        self::assertInstanceOf(AdminFeatureAccessPolicy::class, $policy);

        $adminGroup = $this->upsertGroup($entityManager, 'admin_acl_grant', AccessLevel::ADMIN);
        $userGroup = $this->upsertGroup($entityManager, 'user_acl_grant', AccessLevel::USER);
        $policy->resetCache();
        $overrides->save([
            'admin.settings.api' => [
                'state' => AdminPermissionState::Denied->value,
                'groups' => [
                    $adminGroup->identifier() => AdminPermissionState::Visible->value,
                    $userGroup->identifier() => AdminPermissionState::Mutable->value,
                ],
            ],
        ], 'test');

        try {
            self::assertSame(
                AdminPermissionState::Visible,
                $policy->state('admin.settings.api', AccessActor::fromAccess(AccessLevel::ADMIN, [$adminGroup->identifier()])),
            );
            self::assertSame(
                AdminPermissionState::Denied,
                $policy->state('admin.settings.api', AccessActor::fromAccess(AccessLevel::DIRECTOR, [$adminGroup->identifier()])),
            );
            self::assertSame(
                AdminPermissionState::Denied,
                $policy->state('admin.settings.api', AccessActor::fromAccess(AccessLevel::ADMIN, [$userGroup->identifier()])),
            );

            $overrides->save([
                'admin.settings.statistics' => [
                    'state' => AdminPermissionState::Mutable->value,
                    'groups' => [
                        $adminGroup->identifier() => AdminPermissionState::Visible->value,
                    ],
                ],
            ], 'test');

            self::assertSame(
                AdminPermissionState::Visible,
                $policy->state('admin.settings.statistics', AccessActor::fromAccess(AccessLevel::ADMIN, [$adminGroup->identifier()])),
            );
            self::assertSame(
                AdminPermissionState::Mutable,
                $policy->state('admin.settings.statistics', AccessActor::fromAccess(AccessLevel::ADMIN)),
            );
        } finally {
            $overrides->save([], 'test');
            $entityManager->remove($adminGroup);
            $entityManager->remove($userGroup);
            $entityManager->flush();
            $policy->resetCache();
        }
    }

    private function upsertGroup(EntityManagerInterface $entityManager, string $identifier, int $minRole): AclGroup
    {
        $existing = $entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);
        if ($existing instanceof AclGroup) {
            $existing->changeMinRole($minRole);
            $entityManager->flush();

            return $existing;
        }

        $group = new AclGroup(
            '71000000-0000-7000-8000-'.substr(md5($identifier), 0, 12),
            $identifier,
            ucfirst(str_replace('_', ' ', $identifier)),
            $minRole,
        );
        $entityManager->persist($group);
        $entityManager->flush();

        return $group;
    }
}
