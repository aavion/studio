<?php

declare(strict_types=1);

namespace App\Security;

use App\Backend\BackendListViewHelper;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminUserReviewViewFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BackendListViewHelper $listViews,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewView(Request $request): array
    {
        $query = AdminUserReviewQuery::fromRequest($request, $this->listViews);
        $items = $this->reviewItems();

        if ('all' !== $query->filter) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => $query->filter === $item['filter'] || ('expired' === $query->filter && true === $item['expired']),
            ));
        }

        if ('' !== $query->search) {
            $needle = mb_strtolower($query->search);
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => str_contains(mb_strtolower((string) $item['email']), $needle)
                    || str_contains(mb_strtolower((string) ($item['username'] ?? '')), $needle),
            ));
        }

        $this->sortReviewItems($items, $query->sort, $query->direction);
        $pagination = $this->listViews->pagination($items, $query->page, $query->perPage);

        return [
            'items' => $pagination['items'],
            'filters' => $query->filters($pagination['page']),
            'pagination' => $pagination,
            'per_page_options' => $this->listViews->perPageOptions('admin.user_reviews.filters.all_entries'),
            'sort_options' => $this->reviewSortOptions(),
            'review_filters' => ['all', 'registrations', 'invitations', 'disputes', 'expired'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewItems(): array
    {
        $items = [];
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy(
            [
                'type' => [AccountTokenType::Invitation, AccountTokenType::Registration, AccountTokenType::SecurityReview],
                'status' => [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval, AccountTokenStatus::Used],
            ],
            ['createdAt' => 'DESC'],
        );

        foreach ($tokens as $token) {
            if (!$token instanceof AccountToken) {
                continue;
            }

            $item = match ($token->type()) {
                AccountTokenType::Invitation, AccountTokenType::Registration => $this->accountLinkReviewItem($token),
                AccountTokenType::SecurityReview => $this->securityReviewItem($token),
                AccountTokenType::PasswordReset => null,
            };

            if (null !== $item) {
                $items[] = $item;
            }
        }

        usort($items, static fn (array $left, array $right): int => $right['requested_at']->getTimestamp() <=> $left['requested_at']->getTimestamp());

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountLinkReviewItem(AccountToken $token): ?array
    {
        if (!in_array($token->status(), [AccountTokenStatus::Pending, AccountTokenStatus::PendingApproval], true)) {
            return null;
        }

        $expired = AccountTokenStatus::Pending === $token->status() && $token->isExpired();
        $approval = AccountTokenStatus::PendingApproval === $token->status();
        $type = $token->type();

        return [
            'kind' => $approval ? 'registration_approval' : $type->value,
            'filter' => $type === AccountTokenType::Invitation ? 'invitations' : 'registrations',
            'status' => $approval ? 'pending_approval' : ($expired ? 'expired' : 'open'),
            'expired' => $expired,
            'token' => $token,
            'user' => null,
            'email' => $token->email(),
            'username' => null,
            'requested_at' => $token->createdAt(),
            'role' => $token->role()->value,
            'groups' => $token->groupIdentifiers(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function securityReviewItem(AccountToken $token): ?array
    {
        $user = $token->user();

        if (AccountTokenStatus::Used !== $token->status() || !$user instanceof UserAccount || UserAccountStatus::Inactive !== $user->status()) {
            return null;
        }

        return [
            'kind' => 'password_dispute',
            'filter' => 'disputes',
            'status' => 'locked',
            'expired' => false,
            'token' => $token,
            'user' => $user,
            'email' => $user->email(),
            'username' => $user->username(),
            'requested_at' => $token->consumedAt() ?? $token->createdAt(),
            'role' => $user->role()->value,
            'groups' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sortReviewItems(array &$items, string $sort, string $direction): void
    {
        usort($items, static function (array $left, array $right) use ($sort, $direction): int {
            $result = match ($sort) {
                'email' => strcasecmp((string) $left['email'], (string) $right['email']),
                'kind' => strcasecmp((string) $left['kind'], (string) $right['kind']),
                'status' => strcasecmp((string) $left['status'], (string) $right['status']),
                default => $left['requested_at']->getTimestamp() <=> $right['requested_at']->getTimestamp(),
            };

            return 'desc' === $direction ? -$result : $result;
        });
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function reviewSortOptions(): array
    {
        return [
            ['key' => 'requested_at', 'label' => 'admin.user_reviews.sort.requested_at'],
            ['key' => 'email', 'label' => 'admin.user_reviews.sort.email'],
            ['key' => 'kind', 'label' => 'admin.user_reviews.sort.kind'],
            ['key' => 'status', 'label' => 'admin.user_reviews.sort.status'],
        ];
    }
}
