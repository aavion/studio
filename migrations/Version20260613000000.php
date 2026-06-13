<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Database\TablePrefix;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the UI alert inbox used by live polling and Mercure fallback delivery.';
    }

    public function up(Schema $schema): void
    {
        $inbox = $schema->createTable('ui_alert_inbox');
        $inbox->addColumn('id', 'bigint', ['autoincrement' => true]);
        $inbox->addColumn('topic', 'string', ['length' => 255]);
        $inbox->addColumn('payload', 'json');
        $inbox->addColumn('created_at', 'datetime_immutable');
        $inbox->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $this->addPrimaryKey($inbox, 'id');
        $this->addIndex($inbox, ['topic', 'id'], 'idx_ui_alert_inbox_topic_cursor');
        $this->addIndex($inbox, ['expires_at'], 'idx_ui_alert_inbox_expires_at');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ui_alert_inbox');
    }

    private function schemaObjectName(string $name): string
    {
        return TablePrefix::apply($name);
    }

    private function addPrimaryKey(Table $table, string ...$columns): void
    {
        $constraint = PrimaryKeyConstraint::editor()
            ->setUnquotedName($this->schemaObjectName('pk_'.$table->getName()))
            ->setUnquotedColumnNames(...$columns)
            ->create();

        $table->addPrimaryKeyConstraint($constraint);
    }

    /**
     * @param list<string> $columns
     */
    private function addIndex(Table $table, array $columns, string $name): void
    {
        $table->addIndex($columns, $this->schemaObjectName($name));
    }
}
