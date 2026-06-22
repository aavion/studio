<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Content\ContentVisibility;
use App\Core\Extension\ExtensionAclGroupMemberProviderInterface;
use App\Core\Extension\ExtensionReferenceFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\AclGroup;
use App\Entity\ContentItem;
use App\Entity\Extension;
use App\Entity\UserAccount;
use App\Security\UserRole;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class ExtensionRuntimeReferenceTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/reference-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/reference-facade');
        ExtensionRuntime::reset();
    }

    public function testItReturnsSafeDefaultsForNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, references: new ExtensionReferenceFacade($this->entityManager([]))));

        self::assertNull(ExtensionRuntime::lookup('role', 'user'));
        self::assertNull(ExtensionRuntime::entity('71000000-0000-7000-8000-000000000001', 'user'));
    }

    public function testItReturnsRedactedReferencesForVisibleEntities(): void
    {
        $group = new AclGroup('72000000-0000-7000-8000-000000000001', 'team_admin', 'Team Admin', UserRole::User->accessLevel());
        $admin = new UserAccount('71000000-0000-7000-8000-000000000001', 'adminuser', 'admin@example.test', 'admin-hash', role: UserRole::Admin);
        $user = new UserAccount(
            '71000000-0000-7000-8000-000000000002',
            'memberuser',
            'member@example.test',
            'secret-hash',
            ['display_name' => 'Member'],
            ['token' => 'secret'],
            role: UserRole::User,
        );
        $user->addGroup($group);
        $content = new ContentItem('73000000-0000-7000-8000-000000000001', 'hello-world');
        $content->publish();
        $content->setAvailableLanguages(['en']);
        $extension = new Extension(
            '74000000-0000-7000-8000-000000000001',
            [ExtensionScope::Module],
            'reference-module',
            'extensions/reference-module',
            ExtensionStatus::Active,
            ['secret' => 'hidden'],
            installedVersion: '1.2.3',
        );
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            references: new ExtensionReferenceFacade(
                $this->entityManager([$group, $admin, $user, $content, $extension]),
                security: $this->security($admin),
                aclGroupMembers: new FakeExtensionAclGroupMemberProvider([$group->uid() => [$user]]),
            ),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_lookup('content', 'hello-world'),
                extension_lookup('user', 'memberuser'),
                extension_lookup('acl_group', 'team_admin'),
                extension_lookup('role', 'public'),
                extension_lookup('extension', 'reference-module'),
                extension_entity('71000000-0000-7000-8000-000000000002', 'user'),
            ];
            PHP);

        [$contentRef, $userRef, $groupRef, $roleRef, $extensionRef, $userEntity] = require $this->projectDir.'/extensions/reference-facade/extension.php';

        self::assertSame('content', $contentRef['type']);
        self::assertSame($content->uid(), $contentRef['uid']);
        self::assertSame('hello-world', $contentRef['slug']);
        self::assertSame(['en'], $contentRef['available_languages']);
        self::assertSame('user', $userRef['type']);
        self::assertSame($user->uid(), $userRef['uid']);
        self::assertSame('memberuser', $userRef['username']);
        self::assertSame('user', $userRef['role']);
        self::assertSame([[
            'uid' => $group->uid(),
            'identifier' => 'team_admin',
            'name' => 'Team Admin',
            'min_role' => UserRole::User->accessLevel(),
        ]], $userRef['groups']);
        self::assertArrayNotHasKey('email', $userRef);
        self::assertArrayNotHasKey('password_hash', $userRef);
        self::assertArrayNotHasKey('profile', $userRef);
        self::assertArrayNotHasKey('settings', $userRef);
        self::assertSame($userRef, $userEntity);
        self::assertSame('acl_group', $groupRef['type']);
        self::assertSame('team_admin', $groupRef['identifier']);
        self::assertSame([[
            'type' => 'user',
            'uid' => $user->uid(),
            'username' => 'memberuser',
            'status' => 'active',
            'role' => 'user',
            'access_level' => UserRole::User->accessLevel(),
        ]], $groupRef['members']);
        self::assertSame('role', $roleRef['type']);
        self::assertSame('public', $roleRef['value']);
        self::assertSame('extension', $extensionRef['type']);
        self::assertSame('reference-module', $extensionRef['extension_name']);
        self::assertSame(['module'], $extensionRef['scopes']);
        self::assertArrayNotHasKey('metadata', $extensionRef);
        self::assertArrayNotHasKey('path', $extensionRef);
    }

    public function testItDeniesReferencesOutsideCurrentActorVisibility(): void
    {
        $group = new AclGroup('72000000-0000-7000-8000-000000000001', 'team_admin', 'Team Admin', UserRole::User->accessLevel());
        $otherUser = new UserAccount('71000000-0000-7000-8000-000000000002', 'memberuser', 'member@example.test', 'secret-hash', role: UserRole::User);
        $privateContent = new ContentItem('73000000-0000-7000-8000-000000000001', 'private-page');
        $privateContent->publish();
        $privateContent->setVisibility(ContentVisibility::Private);
        $inactiveExtension = new Extension(
            '74000000-0000-7000-8000-000000000001',
            [ExtensionScope::Module],
            'inactive-module',
            'extensions/inactive-module',
            ExtensionStatus::Inactive,
        );
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            references: new ExtensionReferenceFacade($this->entityManager([$group, $otherUser, $privateContent, $inactiveExtension])),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_lookup('user', 'memberuser'),
                extension_lookup('acl_group', 'team_admin'),
                extension_lookup('content', 'private-page'),
                extension_lookup('extension', 'inactive-module'),
                extension_entity('not-a-uuid', 'user'),
            ];
            PHP);

        self::assertSame([null, null, null, null, null], require $this->projectDir.'/extensions/reference-facade/extension.php');
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/reference-facade/extension.php', $contents);
    }

    /**
     * @param list<object> $entities
     */
    private function entityManager(array $entities): EntityManagerInterface
    {
        $byClassAndUid = [];
        $byClassAndField = [];

        foreach ($entities as $entity) {
            if ($entity instanceof UserAccount) {
                $byClassAndUid[UserAccount::class][$entity->uid()] = $entity;
                $byClassAndField[UserAccount::class]['username'][$entity->username()] = $entity;
            } elseif ($entity instanceof AclGroup) {
                $byClassAndUid[AclGroup::class][$entity->uid()] = $entity;
                $byClassAndField[AclGroup::class]['identifier'][$entity->identifier()] = $entity;
            } elseif ($entity instanceof ContentItem) {
                $byClassAndUid[ContentItem::class][$entity->uid()] = $entity;
                $byClassAndField[ContentItem::class]['slug'][$entity->slug()] = $entity;
            } elseif ($entity instanceof Extension) {
                $byClassAndUid[Extension::class][$entity->uid()] = $entity;
                $byClassAndField[Extension::class]['extensionName'][$entity->extensionName()] = $entity;
            }
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?object => $byClassAndUid[$class][(string) $id] ?? null,
        );
        $entityManager->method('getRepository')->willReturnCallback(
            function (string $class) use ($byClassAndField): EntityRepository {
                $repository = $this->createStub(EntityRepository::class);
                $repository->method('findOneBy')->willReturnCallback(
                    static function (array $criteria) use ($class, $byClassAndField): ?object {
                        foreach ($criteria as $field => $value) {
                            return $byClassAndField[$class][$field][(string) $value] ?? null;
                        }

                        return null;
                    },
                );

                return $repository;
            },
        );

        return $entityManager;
    }

    private function security(UserAccount $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }
}

final readonly class FakeExtensionAclGroupMemberProvider implements ExtensionAclGroupMemberProviderInterface
{
    /**
     * @param array<string, list<UserAccount>> $membersByGroupUid
     */
    public function __construct(private array $membersByGroupUid)
    {
    }

    public function members(AclGroup $group): array
    {
        return $this->membersByGroupUid[$group->uid()] ?? [];
    }
}
