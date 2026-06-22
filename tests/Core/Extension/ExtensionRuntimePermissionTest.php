<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Access\AccessLevel;
use App\Core\Extension\ExtensionPermissionFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Entity\AclGroup;
use App\Entity\ContentItem;
use App\Entity\UserAccount;
use App\Security\UserRole;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class ExtensionRuntimePermissionTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/permission-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/permission-facade');
        ExtensionRuntime::reset();
    }

    public function testItEvaluatesRolesGroupsAndContentRulesForCurrentActor(): void
    {
        $group = new AclGroup('76000000-0000-7000-8000-000000000001', 'project_team', 'Project Team', AccessLevel::USER);
        $actor = new UserAccount('76000000-0000-7000-8000-000000000002', 'author', 'author@example.test', 'hash', role: UserRole::Author);
        $actor->addGroup($group);
        $content = new ContentItem('76000000-0000-7000-8000-000000000003', 'article');
        $content->setEditRule(AccessLevel::AUTHOR);
        $content->setManageRule(AccessLevel::MANAGER);
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            permissions: new ExtensionPermissionFacade($this->entityManager([$group], [$content]), $this->security($actor)),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_can('role', ['role' => 'author']),
                extension_can('role', ['role' => 'manager']),
                extension_can('acl_group', ['identifier' => 'project_team']),
                extension_can('acl_group', ['identifier' => 'other_team']),
                extension_can('content.edit', ['slug' => 'article']),
                extension_can('content.manage', ['uid' => '76000000-0000-7000-8000-000000000003']),
            ];
            PHP);

        self::assertSame(
            [true, false, true, false, true, false],
            require $this->projectDir.'/extensions/permission-facade/extension.php',
        );
    }

    public function testItReturnsSafeDefaultsForInvalidAndNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            permissions: new ExtensionPermissionFacade($this->entityManager([], [])),
        ));

        self::assertFalse(ExtensionRuntime::can('role', ['role' => 'public']));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_can('unknown', ['role' => 'owner']),
                extension_can('role', ['role' => 'not-a-role']),
                extension_can('acl_group', ['uid' => 'not-a-uuid']),
                extension_can('content.edit', ['slug' => 'missing']),
            ];
            PHP);

        self::assertSame(
            [false, false, false, false],
            require $this->projectDir.'/extensions/permission-facade/extension.php',
        );
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/permission-facade/extension.php', $contents);
    }

    /**
     * @param list<AclGroup> $groups
     * @param list<ContentItem> $contents
     */
    private function entityManager(array $groups, array $contents): EntityManagerInterface
    {
        $groupsByUid = [];
        $contentsByUid = [];
        $contentsBySlug = [];

        foreach ($groups as $group) {
            $groupsByUid[$group->uid()] = $group;
        }

        foreach ($contents as $content) {
            $contentsByUid[$content->uid()] = $content;
            $contentsBySlug[$content->slug()] = $content;
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(
            static function (string $class, mixed $id) use ($groupsByUid, $contentsByUid): ?object {
                return match ($class) {
                    AclGroup::class => $groupsByUid[(string) $id] ?? null,
                    ContentItem::class => $contentsByUid[(string) $id] ?? null,
                    default => null,
                };
            },
        );
        $entityManager->method('getRepository')->willReturnCallback(
            function () use ($contentsBySlug): EntityRepository {
                $repository = $this->createStub(EntityRepository::class);
                $repository->method('findOneBy')->willReturnCallback(
                    static fn (array $criteria): ?ContentItem => is_string($criteria['slug'] ?? null)
                        ? ($contentsBySlug[$criteria['slug']] ?? null)
                        : null,
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
