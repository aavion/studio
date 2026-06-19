<?php

declare(strict_types=1);

namespace App\Core\Extension\Content;

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

        foreach ($definitions as $definition) {
            if (!$definition instanceof ExtensionContentSchemaDefinition) {
                return $this->invalid($extension, 'definition_invalid');
            }

            try {
                $result = $this->upsert($extension, $definition);
            } catch (Throwable $error) {
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

            match ($result['action']) {
                'created' => $created[] = $result['identifier'],
                'versioned' => $versioned[] = $result['identifier'],
                default => $unchanged[] = $result['identifier'],
            };
        }

        $this->entityManager->flush();

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
     * @return WorkflowResult<array{deleted: list<string>}>
     */
    public function purge(Extension $extension): WorkflowResult
    {
        $deleted = [];

        foreach ($this->entityManager->getRepository(ContentSchema::class)->findBy(['source' => ContentSchemaSource::Module]) as $schema) {
            if (!$schema instanceof ContentSchema || !$this->ownedBy($schema, $extension)) {
                continue;
            }

            $references = $this->referenceCounts($schema);
            if ($references['content_items'] > 0 || $references['content_revisions'] > 0) {
                return WorkflowResult::failed([
                    Message::create(
                        ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                        ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                        ['%reason%' => 'schema_still_referenced'],
                        ['extension' => $extension->extensionName(), 'schema' => $schema->identifier(), ...$references],
                        MessageLevel::Error,
                    ),
                ]);
            }

            $schema->disable();
            $this->entityManager->remove($schema);
            $deleted[] = $schema->identifier();
        }

        $this->entityManager->flush();

        return WorkflowResult::success([
            'deleted' => $deleted,
        ], [
            'extension' => $extension->extensionName(),
            'deleted' => $deleted,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_PURGE_COMPLETED,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_PURGE_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($deleted)],
                ['extension' => $extension->extensionName(), 'deleted' => $deleted],
            ),
        ]);
    }

    /**
     * @return array{identifier: string, action: string}
     */
    private function upsert(Extension $extension, ExtensionContentSchemaDefinition $definition): array
    {
        $identifier = $definition->identifier($extension->extensionName());
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
            $action = 'created';
        }

        $hash = $this->definitionHash($definition);
        $activeVersion = $schema->activeVersion();

        if ($activeVersion instanceof ContentSchemaVersion && $activeVersion->definitionHash() === $hash) {
            return ['identifier' => $identifier, 'action' => $action];
        }

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

        return ['identifier' => $identifier, 'action' => 'created' === $action ? 'created' : 'versioned'];
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
        return hash('sha256', json_encode($definition->definition(), JSON_THROW_ON_ERROR));
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
        $prefix = str_replace('-', '_', $extension->extensionName()).'_';

        return str_starts_with($schema->identifier(), $prefix);
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
    private function invalid(Extension $extension, string $reason): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID,
                ['%reason%' => $reason],
                ['extension' => $extension->extensionName()],
                MessageLevel::Error,
            ),
        ]);
    }
}
