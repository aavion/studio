<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add global user roles separated from contextual ACL groups.';
    }

    public function up(Schema $schema): void
    {
        $userTable = $schema->getTable('user_account');
        $aclGroupTable = $schema->getTable('acl_group');
        $accountTokenTable = $schema->getTable('account_token');
        $needsUserRoleBackfill = !$userTable->hasColumn('role');

        if ($needsUserRoleBackfill) {
            $this->addSql("ALTER TABLE user_account ADD role VARCHAR(40) DEFAULT 'user' NOT NULL");
        }

        if (!$accountTokenTable->hasColumn('role')) {
            $this->addSql("ALTER TABLE account_token ADD role VARCHAR(40) DEFAULT 'user' NOT NULL");
        }

        if (!$aclGroupTable->hasColumn('min_role') && $aclGroupTable->hasColumn('access_level')) {
            $this->addSql('ALTER TABLE acl_group ADD min_role INTEGER DEFAULT 0 NOT NULL');
            $this->addSql('UPDATE acl_group SET min_role = access_level');
            $this->addSql('CREATE INDEX idx_acl_group_min_role ON acl_group (min_role)');
        }

        if ($aclGroupTable->hasIndex('idx_acl_group_access_level')) {
            $this->addSql('DROP INDEX idx_acl_group_access_level');
        }

        if ($needsUserRoleBackfill) {
            $this->addSql(<<<'SQL'
UPDATE user_account
SET role = (
    CASE
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 9 THEN 'owner'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 8 THEN 'admin'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 7 THEN 'director'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 6 THEN 'manager'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 5 THEN 'curator'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 4 THEN 'publisher'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 3 THEN 'author'
        WHEN (
            SELECT COALESCE(MAX(g.access_level), 0)
            FROM user_acl_group ug
            INNER JOIN acl_group g ON g.uid = ug.group_uid
            WHERE ug.user_uid = user_account.uid
        ) >= 2 THEN 'moderator'
        WHEN status = 'deleted' THEN 'public'
        ELSE 'user'
    END
)
SQL);
        }

        if ($aclGroupTable->hasColumn('access_level')) {
            $this->addSql('ALTER TABLE acl_group DROP COLUMN access_level');
        }

        if ($aclGroupTable->hasColumn('allow_empty')) {
            $this->addSql('ALTER TABLE acl_group DROP COLUMN allow_empty');
        }

        if ($aclGroupTable->hasColumn('locked')) {
            $this->addSql('ALTER TABLE acl_group DROP COLUMN locked');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('user_account')->hasColumn('role')) {
            $this->addSql('ALTER TABLE user_account DROP COLUMN role');
        }

        if ($schema->getTable('account_token')->hasColumn('role')) {
            $this->addSql('ALTER TABLE account_token DROP COLUMN role');
        }
    }
}
