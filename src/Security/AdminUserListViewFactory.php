<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
        $pagination = $this->userPagination($search, $status, $role, $group, $sort, $direction, $page, $perPage);

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

    private function filteredUsersQuery(string $search, string $status, string $role, string $group): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('account')
            ->from(UserAccount::class, 'account')
            ->andWhere('account.uid != :deletedUserUid')
            ->andWhere('account.status != :deletedStatus')
            ->setParameter('deletedUserUid', DeletedUserCleanup::DELETED_USER_UID)
            ->setParameter('deletedStatus', UserAccountStatus::Deleted);

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('LOWER(account.username) LIKE :search OR LOWER(account.email) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ('all' !== $status) {
            $queryBuilder
                ->andWhere('account.status = :status')
                ->setParameter('status', UserAccountStatus::from($status));
        }

        if ('all' !== $role) {
            $queryBuilder
                ->andWhere('account.role = :role')
                ->setParameter('role', UserRole::from($role));
        }

        if ('' !== $group) {
            $queryBuilder
                ->innerJoin('account.groups', 'filterGroup')
                ->andWhere('filterGroup.identifier = :group')
                ->setParameter('group', $group);
        }

        return $queryBuilder;
    }

    /**
     * @return array{items: list<UserAccount>, page: int, per_page: int|string, total: int, total_pages: int, has_previous: bool, has_next: bool, previous_page: int, next_page: int}
     */
    private function userPagination(
        string $search,
        string $status,
        string $role,
        string $group,
        string $sort,
        string $direction,
        int $page,
        int|string $perPage,
    ): array {
        $queryBuilder = $this->filteredUsersQuery($search, $status, $role, $group);
        $total = $this->countUsers($queryBuilder);
        $this->sortUsers($queryBuilder, $sort, $direction);

        if ('all' === $perPage) {
            return [
                'items' => $this->userResults($queryBuilder),
                'page' => 1,
                'per_page' => 'all',
                'total' => $total,
                'total_pages' => 1,
                'has_previous' => false,
                'has_next' => false,
                'previous_page' => 1,
                'next_page' => 1,
            ];
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        return [
            'items' => $this->userResults($queryBuilder),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ];
    }

    private function countUsers(QueryBuilder $queryBuilder): int
    {
        $countQueryBuilder = clone $queryBuilder;
        $countQueryBuilder
            ->select('COUNT(DISTINCT account.uid)')
            ->resetDQLPart('orderBy');

        return (int) $countQueryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<UserAccount>
     */
    private function userResults(QueryBuilder $queryBuilder): array
    {
        return array_values(array_filter(
            $queryBuilder->getQuery()->getResult(),
            static fn (mixed $user): bool => $user instanceof UserAccount,
        ));
    }

    private function sortUsers(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $direction = 'desc' === $direction ? 'DESC' : 'ASC';

        match ($sort) {
            'email' => $queryBuilder->orderBy('LOWER(account.email)', $direction),
            'status' => $queryBuilder->orderBy('account.status', $direction),
            'role' => $this->sortUsersByRole($queryBuilder, $direction),
            default => $queryBuilder->orderBy('LOWER(account.username)', $direction),
        };

        $queryBuilder->addOrderBy('account.uid', 'ASC');
    }

    private function sortUsersByRole(QueryBuilder $queryBuilder, string $direction): void
    {
        $cases = [];

        foreach (UserRole::cases() as $userRole) {
            $parameter = 'sortRole'.$userRole->name;
            $cases[] = sprintf('WHEN account.role = :%s THEN %d', $parameter, $userRole->accessLevel());
            $queryBuilder->setParameter($parameter, $userRole);
        }

        $queryBuilder
            ->addSelect('(CASE '.implode(' ', $cases).' ELSE 0 END) AS HIDDEN role_sort')
            ->orderBy('role_sort', $direction);
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
