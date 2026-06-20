<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

use App\Content\ContentStatus;
use App\Content\Routing\ContentSystemRoute;
use App\Content\Schema\ContentSchemaSource;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ContentItem;
use App\Entity\ContentSchema;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExtensionContentSchemaImpact
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return array{count: int, public_count: int, items: list<array{uid: string, path: string, slug: string, status: string, schema: string, extension: string}>}
     */
    public function impactForExtensions(iterable $extensions): array
    {
        $schemaOwners = [];
        $items = $this->contentItemsForExtensions($extensions, null, $schemaOwners);
        $impact = array_map(fn (ContentItem $item): array => $this->itemImpact($item, $schemaOwners), $items);
        usort($impact, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return [
            'count' => count($impact),
            'public_count' => count(array_filter(
                $impact,
                static fn (array $item): bool => in_array($item['status'], [ContentStatus::Published->value, ContentStatus::Scheduled->value], true),
            )),
            'items' => $impact,
        ];
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return WorkflowResult<array{archived: list<array{uid: string, path: string, slug: string, status: string, schema: string, extension: string}>}>
     */
    public function archivePublicContentForExtensions(iterable $extensions): WorkflowResult
    {
        $archived = [];
        $schemaOwners = [];

        foreach ($this->contentItemsForExtensions($extensions, [ContentStatus::Published, ContentStatus::Scheduled], $schemaOwners) as $item) {
            $before = $this->itemImpact($item, $schemaOwners);
            $item->archive();
            $archived[] = $before;
        }

        return WorkflowResult::success([
            'archived' => $archived,
        ], [
            'archived' => $archived,
            'count' => count($archived),
        ], [] === $archived ? [] : [
            Message::warning(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTENT_ARCHIVED,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTENT_ARCHIVED,
                ['%count%' => count($archived)],
                ['archived' => $archived],
            ),
        ]);
    }

    /**
     * @param iterable<Extension> $extensions
     * @param list<ContentStatus>|null $statuses
     *
     * @return list<ContentItem>
     */
    private function contentItemsForExtensions(iterable $extensions, ?array $statuses = null, array &$schemaOwners = []): array
    {
        $schemas = $this->schemasForExtensions($extensions, $schemaOwners);

        if ([] === $schemas) {
            return [];
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from(ContentItem::class, 'item')
            ->andWhere('item.schema IN (:schemas)')
            ->setParameter('schemas', $schemas);

        if (null !== $statuses) {
            $builder
                ->andWhere('item.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        return array_values(array_filter(
            $builder->getQuery()->getResult(),
            static fn (mixed $item): bool => $item instanceof ContentItem,
        ));
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return list<ContentSchema>
     */
    private function schemasForExtensions(iterable $extensions, array &$schemaOwners = []): array
    {
        $schemas = [];
        $repository = $this->entityManager->getRepository(ContentSchema::class);

        foreach ($extensions as $extension) {
            foreach ($repository->findBy(['source' => ContentSchemaSource::Module]) as $schema) {
                if (!$schema instanceof ContentSchema || !$this->ownedBy($schema, $extension)) {
                    continue;
                }

                $schemas[$schema->uid()] = $schema;
                $schemaOwners[$schema->uid()] = $extension->extensionName();
            }
        }

        return array_values($schemas);
    }

    /**
     * @return array{uid: string, path: string, slug: string, status: string, schema: string, extension: string}
     */
    private function itemImpact(ContentItem $item, array $schemaOwners): array
    {
        $schema = $item->schema();
        $schemaIdentifier = $schema?->identifier() ?? '';
        $schemaUid = $schema?->uid() ?? '';

        return [
            'uid' => $item->uid(),
            'path' => $this->path($item),
            'slug' => $item->slug(),
            'status' => $item->status()->value,
            'schema' => $schemaIdentifier,
            'extension' => $schemaOwners[$schemaUid] ?? '',
        ];
    }

    private function path(ContentItem $item): string
    {
        $segments = [$item->slug()];
        $parentUid = $item->parentUid();
        $seen = [$item->uid() => true];
        $repository = $this->entityManager->getRepository(ContentItem::class);

        while (!in_array($parentUid, [ContentSystemRoute::ROOT_PARENT_UID, ContentSystemRoute::VIRTUAL_PARENT_UID], true)) {
            $parent = $repository->find($parentUid);

            if (!$parent instanceof ContentItem || isset($seen[$parent->uid()])) {
                array_unshift($segments, '[missing-parent]');
                break;
            }

            array_unshift($segments, $parent->slug());
            $seen[$parent->uid()] = true;
            $parentUid = $parent->parentUid();
        }

        $prefix = ContentSystemRoute::VIRTUAL_PARENT_UID === $parentUid ? '/system/' : '/';

        return $prefix.implode('/', $segments);
    }

    private function ownedBy(ContentSchema $schema, Extension $extension): bool
    {
        return ExtensionContentSchemaIdentifier::ownedBy($schema, $extension);
    }
}
