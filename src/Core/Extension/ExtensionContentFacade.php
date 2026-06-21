<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Read\ContentReadAccessPolicy;
use App\Content\Read\PublishedContentResolver;
use App\Core\Access\AccessActor;
use App\Entity\ContentItem;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;

final readonly class ExtensionContentFacade
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PublishedContentResolver $contentResolver = null,
        private ?ContentReadAccessPolicy $contentAccess = null,
        private ?Security $security = null,
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function query(string $extensionName, array $criteria = [], array $options = []): array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return [];
        }

        try {
            $items = $this->entityManager->getRepository(ContentItem::class)->findBy(
                $this->criteria($criteria),
                $this->order($options),
                $this->limit($options),
            );

            $results = [];
            foreach ($items as $item) {
                $reference = $this->reference($item);
                if (null !== $reference) {
                    $results[] = $reference;
                }
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function get(string $extensionName, string $identifier, array $options = []): ?array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        $identifier = trim($identifier);
        if ('' === $identifier) {
            return null;
        }

        try {
            if ($this->isUuid($identifier)) {
                return $this->reference($this->entityManager->find(ContentItem::class, strtolower($identifier)));
            }

            if (str_starts_with($identifier, '/')) {
                return $this->routeReference($identifier, $options);
            }

            return $this->reference($this->entityManager->getRepository(ContentItem::class)->findOneBy([
                'slug' => $identifier,
                'status' => ContentStatus::Published,
            ]));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function routeReference(string $path, array $options): ?array
    {
        if (!$this->contentResolver instanceof PublishedContentResolver) {
            return null;
        }

        $view = $this->contentResolver->findByPath(
            $path,
            $this->actor(),
            is_string($options['language'] ?? null) ? (string) $options['language'] : '',
            is_string($options['variant'] ?? null) ? (string) $options['variant'] : 'default',
        );

        return null !== $view ? $this->reference($view->content()) : null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function criteria(array $criteria): array
    {
        $safe = [
            'status' => ContentStatus::Published,
        ];

        foreach (['slug', 'parent_uid', 'custom_url', 'schema_uid'] as $field) {
            $value = $criteria[$field] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                if (in_array($field, ['parent_uid', 'schema_uid'], true) && !$this->isUuid($value)) {
                    continue;
                }

                $safe[$this->property($field)] = trim($value);
            }
        }

        $visibility = ContentVisibility::tryFrom((string) ($criteria['visibility'] ?? ''));
        if ($visibility instanceof ContentVisibility) {
            $safe['visibility'] = $visibility;
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function order(array $options): array
    {
        $field = is_string($options['sort'] ?? null) ? (string) $options['sort'] : 'sort_order';
        $field = in_array($field, ['slug', 'sort_order'], true) ? $field : 'sort_order';
        $direction = 'desc' === strtolower((string) ($options['direction'] ?? 'asc')) ? 'DESC' : 'ASC';

        if ('slug' === $field) {
            return ['slug' => $direction];
        }

        return [$this->property($field) => $direction, 'slug' => 'ASC'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function limit(array $options): int
    {
        $limit = $options['limit'] ?? 20;
        $limit = is_numeric($limit) ? (int) $limit : 20;

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reference(mixed $content): ?array
    {
        if (!$content instanceof ContentItem || !$this->canView($content)) {
            return null;
        }

        return [
            'type' => 'content',
            'uid' => $content->uid(),
            'slug' => $content->slug(),
            'parent_uid' => $content->parentUid(),
            'custom_url' => $content->customUrl(),
            'sort_order' => $content->sortOrder(),
            'status' => $content->status()->value,
            'visibility' => $content->visibility()->value,
            'schema_uid' => $content->schemaUid(),
            'schema_version' => $content->schemaVersion(),
            'available_languages' => $content->availableLanguages(),
            'available_variants' => $content->availableVariants(),
        ];
    }

    private function canView(ContentItem $content): bool
    {
        if (ContentStatus::Published !== $content->status() || ContentVisibility::Public !== $content->visibility()) {
            return false;
        }

        return !$this->contentAccess instanceof ContentReadAccessPolicy
            || $this->contentAccess->allowsView($content, $this->actor());
    }

    private function actor(): AccessActor
    {
        $user = $this->security?->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    private function property(string $field): string
    {
        return match ($field) {
            'parent_uid' => 'parentUid',
            'custom_url' => 'customUrl',
            'schema_uid' => 'schema',
            'sort_order' => 'sortOrder',
            default => $field,
        };
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }
}
