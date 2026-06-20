<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

use App\Content\ContentStatus;
use App\Content\Schema\ContentSchemaSource;
use App\Core\Id\UuidFactory;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use App\Entity\Extension;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class ExtensionContentSchemaSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @param iterable<ExtensionContentSchemaDefinition> $definitions
     *
     * @return WorkflowResult<array{created: list<string>, versioned: list<string>, unchanged: list<string>}>
     */
    public function apply(Extension $extension, iterable $definitions): WorkflowResult
    {
        $created = [];
        $versioned = [];
        $unchanged = [];
        $stagedEntities = [];
        $schemaActiveVersionSnapshots = [];

        foreach ($definitions as $definition) {
            if (!$definition instanceof ExtensionContentSchemaDefinition) {
                $this->restoreStagedSchemaState($stagedEntities, $schemaActiveVersionSnapshots);

                return $this->invalid($extension, 'definition_invalid');
            }

            $identifier = $definition->identifier($extension->extensionName());
            if (!ExtensionContentSchemaIdentifier::isPortableIdentifier($identifier)) {
                $this->restoreStagedSchemaState($stagedEntities, $schemaActiveVersionSnapshots);

                return $this->invalid($extension, 'schema_identifier_too_long', [
                    'schema' => $definition->name(),
                    'identifier' => $identifier,
                    'max_length' => ContentSchema::MAX_IDENTIFIER_LENGTH,
                ]);
            }

            try {
                $result = $this->upsert($extension, $definition, $identifier, $stagedEntities, $schemaActiveVersionSnapshots);
            } catch (Throwable $error) {
                return $this->failedSync($extension, $error, $stagedEntities, $schemaActiveVersionSnapshots);
            }

            match ($result['action']) {
                'created' => $created[] = $result['identifier'],
                'versioned' => $versioned[] = $result['identifier'],
                default => $unchanged[] = $result['identifier'],
            };
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable $error) {
            return $this->failedSync($extension, $error, $stagedEntities, $schemaActiveVersionSnapshots);
        }

        return WorkflowResult::success([
            'created' => $created,
            'versioned' => $versioned,
            'unchanged' => $unchanged,
        ], [
            'extension' => $extension->extensionName(),
            'created' => $created,
            'versioned' => $versioned,
            'unchanged' => $unchanged,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_SYNC_COMPLETED,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_SYNC_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($created) + count($versioned)],
                ['extension' => $extension->extensionName(), 'created' => $created, 'versioned' => $versioned, 'unchanged' => $unchanged],
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array{deleted: list<string>, retained: list<array{schema: string, content_items: int, content_revisions: int}>, archived_content: int}>
     */
    public function purge(Extension $extension): WorkflowResult
    {
        $deleted = [];
        $retained = [];
        $archivedContent = 0;

        foreach ($this->entityManager->getRepository(ContentSchema::class)->findBy(['source' => ContentSchemaSource::Module]) as $schema) {
            if (!$schema instanceof ContentSchema || !$this->ownedBy($schema, $extension)) {
                continue;
            }

            $archivedContent += $this->forceArchiveContent($schema);
            $references = $this->referenceCounts($schema);
            if ($references['content_items'] > 0 || $references['content_revisions'] > 0) {
                $schema->disable();
                $retained[] = ['schema' => $schema->identifier(), ...$references];
                continue;
            }

            $schema->disable();
            $this->entityManager->remove($schema);
            $deleted[] = $schema->identifier();
        }

        $this->entityManager->flush();

        return WorkflowResult::success([
            'deleted' => $deleted,
            'retained' => $retained,
            'archived_content' => $archivedContent,
        ], [
            'extension' => $extension->extensionName(),
            'deleted' => $deleted,
            'retained' => $retained,
            'archived_content' => $archivedContent,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_PURGE_COMPLETED,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_PURGE_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($deleted)],
                ['extension' => $extension->extensionName(), 'deleted' => $deleted],
            ),
            ...([] === $retained ? [] : [
                Message::warning(
                    ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_PURGE_RETAINED,
                    ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_PURGE_RETAINED,
                    ['%extension%' => $extension->extensionName(), '%count%' => count($retained)],
                    ['extension' => $extension->extensionName(), 'retained' => $retained, 'archived_content' => $archivedContent],
                ),
            ]),
        ]);
    }

    /**
     * @param list<object> $stagedEntities
     * @param array<int, array{schema: ContentSchema, active_version: ContentSchemaVersion|null}> $schemaActiveVersionSnapshots
     *
     * @return array{identifier: string, action: string}
     */
    private function upsert(
        Extension $extension,
        ExtensionContentSchemaDefinition $definition,
        string $identifier,
        array &$stagedEntities,
        array &$schemaActiveVersionSnapshots,
    ): array
    {
        $schema = $this->entityManager->getRepository(ContentSchema::class)->findOneBy(['identifier' => $identifier]);
        $action = 'unchanged';

        if (!$schema instanceof ContentSchema) {
            $schema = new ContentSchema(
                $this->uuidFactory->generate(),
                $identifier,
                ContentSchemaSource::Module,
                $definition->labels(),
                locked: true,
                descriptions: $definition->descriptions(),
                metadata: $this->metadata($extension, $definition),
            );
            $this->entityManager->persist($schema);
            $stagedEntities[] = $schema;
            $action = 'created';
        }

        $hash = $this->definitionHash($definition);
        $activeVersion = $schema->activeVersion();

        if ($activeVersion instanceof ContentSchemaVersion && $activeVersion->definitionHash() === $hash) {
            return ['identifier' => $identifier, 'action' => $action];
        }

        $objectId = spl_object_id($schema);
        $schemaActiveVersionSnapshots[$objectId] ??= [
            'schema' => $schema,
            'active_version' => $activeVersion,
        ];

        $version = new ContentSchemaVersion(
            $this->uuidFactory->generate(),
            $schema,
            $this->nextVersion($schema),
            $definition->labels(),
            $definition->definition(),
            $definition->descriptions(),
            $definition->customTwig(),
            metadata: $this->metadata($extension, $definition),
        );
        $schema->activateVersion($version);
        $this->entityManager->persist($version);
        $stagedEntities[] = $version;

        return ['identifier' => $identifier, 'action' => 'created' === $action ? 'created' : 'versioned'];
    }

    /**
     * @param list<object> $stagedEntities
     * @param array<int, array{schema: ContentSchema, active_version: ContentSchemaVersion|null}> $schemaActiveVersionSnapshots
     */
    private function failedSync(
        Extension $extension,
        Throwable $error,
        array $stagedEntities,
        array $schemaActiveVersionSnapshots,
    ): WorkflowResult {
        $this->restoreStagedSchemaState($stagedEntities, $schemaActiveVersionSnapshots);

        return WorkflowResult::failed([
            Message::create(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ['%reason%' => 'schema_sync_failed'],
                ['extension' => $extension->extensionName(), 'exception' => $error::class, 'message' => $error->getMessage()],
                MessageLevel::Exception,
            ),
        ]);
    }

    /**
     * @param list<object> $stagedEntities
     * @param array<int, array{schema: ContentSchema, active_version: ContentSchemaVersion|null}> $schemaActiveVersionSnapshots
     */
    private function restoreStagedSchemaState(array $stagedEntities, array $schemaActiveVersionSnapshots): void
    {
        foreach ($schemaActiveVersionSnapshots as $snapshot) {
            null === $snapshot['active_version']
                ? $snapshot['schema']->disable()
                : $snapshot['schema']->activateVersion($snapshot['active_version']);
        }

        foreach (array_reverse($stagedEntities) as $entity) {
            $this->entityManager->detach($entity);
        }

        foreach ($schemaActiveVersionSnapshots as $snapshot) {
            $this->entityManager->detach($snapshot['schema']);
        }
    }

    private function nextVersion(ContentSchema $schema): int
    {
        $version = 0;

        foreach ($schema->versions() as $existing) {
            $version = max($version, $existing->version());
        }

        return $version + 1;
    }

    private function definitionHash(ExtensionContentSchemaDefinition $definition): string
    {
        return hash('sha256', json_encode([
            'title' => $definition->labels(),
            'description' => $definition->descriptions(),
            'definition' => $definition->definition(),
            'custom_twig' => $definition->customTwig(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Extension $extension, ExtensionContentSchemaDefinition $definition): array
    {
        return [
            ...$definition->metadata(),
            'extension' => $extension->extensionName(),
            'extension_schema' => $definition->name(),
            'immutable_preset' => true,
        ];
    }

    private function ownedBy(ContentSchema $schema, Extension $extension): bool
    {
        return ExtensionContentSchemaIdentifier::ownedBy($schema, $extension);
    }

    private function forceArchiveContent(ContentSchema $schema): int
    {
        $archived = 0;

        foreach ($this->entityManager->getRepository(ContentItem::class)->findBy(['schema' => $schema]) as $item) {
            if (!$item instanceof ContentItem || in_array($item->status(), [ContentStatus::Archived, ContentStatus::Deleted], true)) {
                continue;
            }

            $item->archive();
            ++$archived;
        }

        return $archived;
    }

    /**
     * @return array{content_items: int, content_revisions: int}
     */
    private function referenceCounts(ContentSchema $schema): array
    {
        return [
            'content_items' => $this->entityManager->getRepository(ContentItem::class)->count(['schema' => $schema]),
            'content_revisions' => $this->entityManager->getRepository(ContentRevision::class)->count(['schema' => $schema]),
        ];
    }

    /**
     * @return WorkflowResult<null>
     */
    private function invalid(Extension $extension, string $reason, array $context = []): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ['%reason%' => $reason],
                ['extension' => $extension->extensionName(), ...$context],
                MessageLevel::Error,
            ),
        ]);
    }
}
