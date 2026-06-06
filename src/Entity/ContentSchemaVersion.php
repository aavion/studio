<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\ContentMessageKey;
use App\Content\Schema\ContentSchemaField;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessRule;
use App\Core\Message\MessageException;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'content_schema_version')]
#[ORM\UniqueConstraint(name: 'uniq_content_schema_version', columns: ['schema_uid', 'version'])]
#[ORM\Index(name: 'idx_content_schema_version_schema', columns: ['schema_uid'])]
#[ORM\Index(name: 'idx_content_schema_version_hash', columns: ['definition_hash'])]
class ContentSchemaVersion
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\ManyToOne(targetEntity: ContentSchema::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'schema_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'CASCADE')]
    private ContentSchema $schema;

    #[ORM\Column]
    private int $version;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $title;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $description = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $definition;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $customTwig = null;

    #[ORM\Column(length: 64)]
    private string $definitionHash;

    #[ORM\Column(nullable: true)]
    private ?int $useMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $useGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $editMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $editGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $manageMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $manageGroupIdentifiers = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, string> $title
     * @param array<string, string> $description
     * @param array<string, mixed> $definition
     * @param list<string>|null $useGroupIdentifiers
     * @param list<string>|null $editGroupIdentifiers
     * @param list<string>|null $manageGroupIdentifiers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        ContentSchema $schema,
        int $version,
        array $title,
        array $definition,
        array $description = [],
        ?string $customTwig = null,
        ?int $useMinLevel = null,
        ?array $useGroupIdentifiers = null,
        ?int $editMinLevel = null,
        ?array $editGroupIdentifiers = null,
        ?int $manageMinLevel = null,
        ?array $manageGroupIdentifiers = null,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Content schema version UID');
        $this->schema = $schema;
        $this->version = self::assertVersion($version);
        $this->title = $title;
        $this->description = $description;
        $this->definition = self::assertDefinition($definition);
        $this->customTwig = $customTwig;
        $this->definitionHash = hash('sha256', json_encode($this->definition, JSON_THROW_ON_ERROR));
        $this->setUseRule($useMinLevel, $useGroupIdentifiers);
        $this->setEditRule($editMinLevel, $editGroupIdentifiers);
        $this->setManageRule($manageMinLevel, $manageGroupIdentifiers);
        $this->metadata = $metadata;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function schema(): ContentSchema
    {
        return $this->schema;
    }

    public function attachTo(ContentSchema $schema): void
    {
        $this->schema = $schema;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    public function definitionHash(): string
    {
        return $this->definitionHash;
    }

    public function customTwig(): ?string
    {
        return $this->customTwig;
    }

    public function useMinLevel(): ?int
    {
        return $this->useMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function useGroupIdentifiers(): ?array
    {
        return $this->useGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setUseRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->useMinLevel = AccessLevel::assert($minLevel);
        $this->useGroupIdentifiers = AccessRule::normalizeGroupIdentifiersOrNull($groupIdentifiers);
    }

    public function editMinLevel(): ?int
    {
        return $this->editMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function editGroupIdentifiers(): ?array
    {
        return $this->editGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setEditRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->editMinLevel = AccessLevel::assert($minLevel);
        $this->editGroupIdentifiers = AccessRule::normalizeGroupIdentifiersOrNull($groupIdentifiers);
    }

    public function manageMinLevel(): ?int
    {
        return $this->manageMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function manageGroupIdentifiers(): ?array
    {
        return $this->manageGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setManageRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->manageMinLevel = AccessLevel::assert($minLevel);
        $this->manageGroupIdentifiers = AccessRule::normalizeGroupIdentifiersOrNull($groupIdentifiers);
    }

    public function activate(): void
    {
        $this->schema->activateVersion($this);
    }

    private static function assertVersion(int $version): int
    {
        if ($version < 1) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_SCHEMA_VERSION_INVALID, [
                '%version%' => $version,
            ]);
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private static function assertDefinition(array $definition): array
    {
        $fields = $definition['fields'] ?? null;

        if (!is_array($fields)) {
            $fields = [];
        }

        $identifiers = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['identifier']) || !is_string($field['identifier'])) {
                continue;
            }

            Identifier::assertSnakeCase($field['identifier'], ContentMessageKey::CONTENT_FIELD_IDENTIFIER_INVALID, '%field_identifier%');

            if (isset($identifiers[$field['identifier']])) {
                throw MessageException::invalidArgument(ContentMessageKey::CONTENT_SCHEMA_FIELD_DUPLICATE, [
                    '%field_identifier%' => $field['identifier'],
                ]);
            }

            $identifiers[$field['identifier']] = true;
        }

        foreach (ContentSchemaField::requiredBaseIdentifiers() as $requiredIdentifier) {
            if (!isset($identifiers[$requiredIdentifier])) {
                throw MessageException::invalidArgument(ContentMessageKey::CONTENT_SCHEMA_REQUIRED_FIELD_MISSING, [
                    '%field_identifier%' => $requiredIdentifier,
                ]);
            }
        }

        return $definition;
    }

}
