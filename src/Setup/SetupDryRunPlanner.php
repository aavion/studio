<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;

final readonly class SetupDryRunPlanner
{
    public function __construct(
        private SetupComposerCommandResolver $composerCommandResolver = new SetupComposerCommandResolver(),
        private SetupSensitiveValueMasker $sensitiveValueMasker = new SetupSensitiveValueMasker(),
        private SetupDefaultSeed $defaultSeed = new SetupDefaultSeed(),
    ) {
    }

    /**
     * @param list<string> $migrationCommand
     *
     * @return list<array{0: string, 1: callable(): array<string, mixed>, 2: ActionLogStatus}>
     */
    public function steps(string $projectDir, SetupInput $input, string $appSecret, string $databaseUrl, array $migrationCommand): array
    {
        return [
            ['write_environment', fn (): array => [
                '_messages' => [
                    Message::debug(MessageCode::SETUP_DRY_RUN, MessageKey::SETUP_DRY_RUN),
                ],
                'dry_run' => true,
                'path' => '.env.'.$input->appEnv().'.local',
                'would_write' => [
                    'APP_SECRET' => $appSecret,
                    'DEFAULT_URI' => $input->defaultUri(),
                    'DATABASE_URL' => $this->sensitiveValueMasker->maskDatabaseUrl($databaseUrl),
                    'APP_DATABASE_PREFIX' => $input->databasePrefix() ?? '',
                ],
            ], ActionLogStatus::Skipped],
            ['dump_environment', fn (): array => [
                'dry_run' => true,
                'command' => [...$this->composerCommandResolver->plannedCommand($projectDir), 'dump-env', $input->appEnv()],
            ], ActionLogStatus::Skipped],
            ['run_migrations', fn (): array => [
                'dry_run' => true,
                'command' => $migrationCommand,
            ], ActionLogStatus::Skipped],
            ['seed_default_settings', fn (): array => [
                'dry_run' => true,
                'settings' => $this->defaultSeed->configMap($input),
            ], ActionLogStatus::Skipped],
            ['seed_admin_user', fn (): array => [
                'dry_run' => true,
                'admin_username' => $input->adminUsername(),
                'admin_email' => $input->adminEmail(),
                'admin_password' => '[hidden]',
                'groups' => [],
            ], ActionLogStatus::Skipped],
            ['seed_initial_content', fn (): array => [
                'dry_run' => true,
                'schema' => $this->defaultSeed->contentSchema()['identifier'],
                'path' => $this->defaultSeed->homePath(),
                'title' => $input->siteTitle(),
            ], ActionLogStatus::Skipped],
            ['clear_cache', fn (): array => [
                'dry_run' => true,
                'command' => [PHP_BINARY, $projectDir.'/bin/console', 'cache:clear', '--env='.$input->appEnv()],
            ], ActionLogStatus::Skipped],
            ['run_package_discovery', fn (): array => [
                'dry_run' => true,
                'command' => [PHP_BINARY, $projectDir.'/bin/console', 'studio:packages:discover', '--run-now', '--trigger=setup', '--env='.$input->appEnv()],
            ], ActionLogStatus::Skipped],
            ['run_asset_rebuild', fn (): array => [
                'dry_run' => true,
                'command' => [PHP_BINARY, $projectDir.'/bin/console', 'studio:assets:rebuild', '--trigger=setup', '--env='.$input->appEnv()],
            ], ActionLogStatus::Skipped],
            ['mark_setup_completed', fn (): array => [
                'dry_run' => true,
                'would_write' => [
                    'APP_SETUP_COMPLETED' => '1',
                ],
            ], ActionLogStatus::Skipped],
        ];
    }
}
