<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\ContentSchemaVersion;

final readonly class AclGroupSchemaReferenceProvider implements AclGroupReferenceProviderInterface
{
    public function __construct(
        private AclGroupReferenceQuery $references,
        private AclGroupReferenceValues $values,
    ) {
    }

    public function key(): string
    {
        return 'content_schema_versions';
    }

    public function impact(AclGroup $group): array
    {
        $rows = [];
        $identifier = $group->identifier();

        foreach ($this->schemaVersions($identifier) as $version) {
            $fields = [
                ...$this->values->fieldIfContains('use_group_identifiers', $version->useGroupIdentifiers(), $identifier),
                ...$this->values->fieldIfContains('edit_group_identifiers', $version->editGroupIdentifiers(), $identifier),
                ...$this->values->fieldIfContains('manage_group_identifiers', $version->manageGroupIdentifiers(), $identifier),
            ];

            if ([] === $fields) {
                continue;
            }

            $rows[] = [
                'uid' => $version->uid(),
                'label' => $version->schema()->identifier().' v'.$version->version(),
                'fields' => $fields,
            ];
        }

        return $rows;
    }

    public function removeReferences(AclGroup $group): void
    {
        $identifier = $group->identifier();

        foreach ($this->schemaVersions($identifier) as $version) {
            $version->setUseRule($version->useMinLevel(), $this->values->withoutIdentifierOrNull($version->useGroupIdentifiers(), $identifier));
            $version->setEditRule($version->editMinLevel(), $this->values->withoutIdentifierOrNull($version->editGroupIdentifiers(), $identifier));
            $version->setManageRule($version->manageMinLevel(), $this->values->withoutIdentifierOrNull($version->manageGroupIdentifiers(), $identifier));
        }
    }

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int
    {
        return 0;
    }

    /**
     * @return list<ContentSchemaVersion>
     */
    private function schemaVersions(string $identifier): array
    {
        return $this->references->entitiesByJsonColumns(
            ContentSchemaVersion::class,
            'content_schema_version',
            ['use_group_identifiers', 'edit_group_identifiers', 'manage_group_identifiers'],
            $identifier,
        );
    }
}
