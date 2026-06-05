<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Config\Config;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;

final readonly class SetupConfigSeeder
{
    public function __construct(
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
        private SetupDefaultSeed $defaultSeed = new SetupDefaultSeed(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seed(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv());
        $config = new Config($connection);
        $settings = $this->defaultSeed->configEntries($input);

        foreach ($settings as $setting) {
            $key = $setting['key'];

            if (!$config->set($key, $setting['value'], $setting['type'], modifiedBy: 'setup')) {
                throw SetupStepFailedException::fromMessage(Message::error(
                    MessageCode::CONFIG_WRITE_FAILED,
                    MessageKey::CONFIG_WRITE_FAILED,
                    ['%key%' => $key],
                    ['operation' => 'setup.seed_default_settings', 'config_key' => $key],
                ));
            }
        }

        return ['settings' => array_column($settings, 'key')];
    }
}
