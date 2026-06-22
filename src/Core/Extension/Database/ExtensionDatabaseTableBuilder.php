<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Entity\Extension;
use Doctrine\DBAL\Schema\Table;

final readonly class ExtensionDatabaseTableBuilder
{
    public function __construct(private ExtensionDatabaseTableNameResolver $names)
    {
    }

    public function table(Extension $extension, string $physicalName, ExtensionDatabaseTable $definition): Table
    {
        $table = new Table($physicalName);

        foreach ($definition->columns() as $column) {
            $table->addColumn($column->name(), $column->type(), $column->options());
        }

        if ([] !== $definition->primaryKey()) {
            $table->setPrimaryKey($definition->primaryKey(), $this->names->shortName('pk_'.$physicalName));
        }

        foreach ($definition->indexes() as $index) {
            $name = $this->names->shortName($physicalName.'_'.$index->name());
            if ($index->unique()) {
                $table->addUniqueIndex($index->columns(), $name);
                continue;
            }

            $table->addIndex($index->columns(), $name);
        }

        foreach ($definition->foreignKeys() as $foreignKey) {
            $table->addForeignKeyConstraint(
                $this->names->referencedTableName($extension, $foreignKey),
                $foreignKey->localColumns(),
                $foreignKey->referencedColumns(),
                $foreignKey->options(),
                $this->names->shortName($physicalName.'_'.$foreignKey->name()),
            );
        }

        return $table;
    }
}
