<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupDatabaseSeeder
{
    public function __construct(
        private SetupConfigSeeder $configSeeder = new SetupConfigSeeder(),
        private SetupAdminAccountSeeder $adminAccountSeeder = new SetupAdminAccountSeeder(),
        private SetupInitialContentSeeder $initialContentSeeder = new SetupInitialContentSeeder(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seedDefaultSettings(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        return $this->configSeeder->seed($projectDir, $input, $databaseUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function seedAdminUser(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        return $this->adminAccountSeeder->seed($projectDir, $input, $databaseUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function seedInitialContent(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        return $this->initialContentSeeder->seed($projectDir, $input, $databaseUrl);
    }
}
