<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminUserListViewFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BackendListViewHelper $listViews,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function usersView(Request $request): array
    {
        $search = $this->listViews->queryString($request, 'q');
        $status = $this->listViews->queryChoice($request, 'status', ['all', 'active', 'inactive'], 'all');
        $role = $this->listViews->queryChoice($request, 'role', ['all', ...array_map(static fn (UserRole $role): string => $role->value, UserRole::assignable())], 'all');
        $group = $this->listViews->queryString($request, 'group');
        $sort = $this->listViews->queryChoice($request, 'sort', ['username', 'email', 'status', 'role'], 'username');
        $direction = $this->listViews->queryChoice($request, 'direction', ['asc', 'desc'], 'asc');
        $perPage = $this->listViews->perPage($request->query->get('per_page'));
        $page = $this->listViews->page($request->query->get('page'));
        $users = array_values(array_filter(
            $this->entityManager->getRepository(UserAccount::class)->findAll(),
            static fn (mixed $user): bool => $user instanceof UserAccount
                && DeletedUserCleanup::DELETED_USER_UID !== $user->uid()
                && UserAccountStatus::Deleted !== $user->status(),
        ));

        if ('' !== $search) {
            $needle = mb_strtolower($search);
            $users = array_values(array_filter(
                $users,
                static fn (UserAccount $user): bool => str_contains(mb_strtolower($user->username()), $needle)
                    || str_contains(mb_strtolower($user->email()), $needle),
            ));
        }

        if ('all' !== $status) {
            $users = array_values(array_filter(
                $users,
                static fn (UserAccount $user): bool => $status === $user->status()->value,
            ));
        }

        if ('all' !== $role) {
            $users = array_values(array_filter(
                $users,
                static fn (UserAccount $user): bool => $role === $user->role()->value,
            ));
        }

        if ('' !== $group) {
            $users = array_values(array_filter(
                $users,
                static fn (UserAccount $user): bool => in_array($group, array_map(static fn (AclGroup $aclGroup): string => $aclGroup->identifier(), $user->groups()->toArray()), true),
            ));
        }

        $this->sortUsers($users, $sort, $direction);
        $pagination = $this->listViews->pagination($users, $page, $perPage);

        return [
            'items' => $pagination['items'],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'role' => $role,
                'group' => $group,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
                'page' => $pagination['page'],
            ],
            'pagination' => $pagination,
            'per_page_options' => $this->listViews->perPageOptions('admin.users.filters.all_entries'),
            'sort_options' => $this->userSortOptions(),
            'status_options' => ['all', 'active', 'inactive'],
            'role_options' => UserRole::assignable(),
            'group_options' => $this->entityManager->getRepository(AclGroup::class)->findBy([], ['identifier' => 'ASC']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function groupsView(Request $request): array
    {
        $search = $this->listViews->queryString($request, 'q');
        $sort = $this->listViews->queryChoice($request, 'sort', ['identifier', 'name', 'min_role'], 'min_role');
        $direction = $this->listViews->queryChoice($request, 'direction', ['asc', 'desc'], 'asc');
        $perPage = $this->listViews->perPage($request->query->get('per_page'));
        $page = $this->listViews->page($request->query->get('page'));
        $groups = array_values(array_filter(
            $this->entityManager->getRepository(AclGroup::class)->findAll(),
            static fn (mixed $group): bool => $group instanceof AclGroup,
        ));

        if ('' !== $search) {
            $needle = mb_strtolower($search);
            $groups = array_values(array_filter(
                $groups,
                static fn (AclGroup $group): bool => str_contains(mb_strtolower($group->identifier()), $needle)
                    || str_contains(mb_strtolower((string) ($group->name()['en'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($group->name()['de'] ?? '')), $needle),
            ));
        }

        $this->sortGroups($groups, $sort, $direction);
        $pagination = $this->listViews->pagination($groups, $page, $perPage);

        return [
            'items' => $pagination['items'],
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
                'page' => $pagination['page'],
            ],
            'pagination' => $pagination,
            'per_page_options' => $this->listViews->perPageOptions('admin.groups.filters.all_entries'),
            'sort_options' => $this->groupSortOptions(),
        ];
    }

    /**
     * @param list<UserAccount> $users
     */
    private function sortUsers(array &$users, string $sort, string $direction): void
    {
        usort($users, static function (UserAccount $left, UserAccount $right) use ($sort, $direction): int {
            $result = match ($sort) {
                'email' => strcasecmp($left->email(), $right->email()),
                'status' => $left->status()->value <=> $right->status()->value,
                'role' => $left->accessLevel() <=> $right->accessLevel(),
                default => strcasecmp($left->username(), $right->username()),
            };

            return 'desc' === $direction ? -$result : $result;
        });
    }

    /**
     * @param list<AclGroup> $groups
     */
    private function sortGroups(array &$groups, string $sort, string $direction): void
    {
        usort($groups, static function (AclGroup $left, AclGroup $right) use ($sort, $direction): int {
            $result = match ($sort) {
                'name' => strcasecmp((string) ($left->name()['en'] ?? $left->identifier()), (string) ($right->name()['en'] ?? $right->identifier())),
                'identifier' => strcasecmp($left->identifier(), $right->identifier()),
                default => [$left->minRole(), $left->identifier()] <=> [$right->minRole(), $right->identifier()],
            };

            return 'desc' === $direction ? -$result : $result;
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function userSortOptions(): array
    {
        return [
            ['key' => 'username', 'label' => 'admin.users.sort.username'],
            ['key' => 'email', 'label' => 'admin.users.sort.email'],
            ['key' => 'status', 'label' => 'admin.users.sort.status'],
            ['key' => 'role', 'label' => 'admin.users.sort.role'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function groupSortOptions(): array
    {
        return [
            ['key' => 'min_role', 'label' => 'admin.groups.sort.min_role'],
            ['key' => 'identifier', 'label' => 'admin.groups.sort.identifier'],
            ['key' => 'name', 'label' => 'admin.groups.sort.name'],
        ];
    }
}
