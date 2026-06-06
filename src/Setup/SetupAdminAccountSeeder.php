<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Id\UuidFactory;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use Doctrine\DBAL\Connection;

final readonly class SetupAdminAccountSeeder
{
    public function __construct(
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
        private SetupDefaultSeed $defaultSeed = new SetupDefaultSeed(),
        private SetupStateMarkerWriter $stateMarkers = new SetupStateMarkerWriter(),
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seed(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv());
        $now = gmdate('Y-m-d H:i:s');

        foreach ($this->defaultSeed->aclGroups() as $group) {
            $groupUid = $this->upsertAclGroup(
                $connection,
                $group['uid'],
                $group['identifier'],
                $group['name'],
                $group['min_role'],
            );
            $this->stateMarkers->upsert($connection, StateSubjectType::ACL_GROUP, $groupUid, StateMarkerKey::CREATED, $now, 'setup', null, ['identifier' => $group['identifier']]);
        }

        $userUid = $this->upsertAdmin($connection, $input, $now);
        $this->stateMarkers->upsert($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::CREATED, $now, 'setup');
        $this->stateMarkers->upsert($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::PASSWORD_CHANGED, $now, 'setup');
        $this->stateMarkers->upsert($connection, StateSubjectType::USER_ACCOUNT, $userUid, StateMarkerKey::STATUS_CHANGED, $now, 'setup', 'active');

        return ['admin_username' => $input->adminUsername(), 'admin_email' => $input->adminEmail()];
    }

    private function upsertAclGroup(
        Connection $connection,
        string $uid,
        string $identifier,
        string $name,
        int $minRole,
    ): string {
        $values = [
            'identifier' => $identifier,
            'name' => $name,
            'min_role' => $minRole,
            'metadata' => json_encode(['seeded_by' => 'setup'], JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne('SELECT uid FROM acl_group WHERE identifier = ?', [$identifier]);

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('acl_group', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $connection->insert('acl_group', ['uid' => $uid, ...$values]);

        return $uid;
    }

    private function upsertAdmin(Connection $connection, SetupInput $input, string $now): string
    {
        $existingUid = $connection->fetchOne('SELECT uid FROM user_account WHERE username = ?', [$input->adminUsername()]);
        $values = [
            'username' => $input->adminUsername(),
            'email' => $input->adminEmail(),
            'password_hash' => password_hash($input->adminPassword(), PASSWORD_DEFAULT),
            'profile' => json_encode(['created_by' => 'setup', 'updated_at' => $now], JSON_THROW_ON_ERROR),
            'settings' => json_encode(['language' => 'default'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'role' => 'owner',
        ];

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('user_account', $values, ['uid' => $existingUid]);

            return $existingUid;
        }

        $uid = $this->uuidFactory->generate();
        $connection->insert('user_account', ['uid' => $uid, ...$values]);

        return $uid;
    }
}
