<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLogStatus;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Security\UserFlowConfig;

final readonly class SetupDryRunPlanner
{
    public function __construct(
        private SetupComposerCommandResolver $composerCommandResolver = new SetupComposerCommandResolver(),
        private SetupSensitiveValueMasker $sensitiveValueMasker = new SetupSensitiveValueMasker(),
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
                'settings' => [
                    'site.title' => $input->siteTitle(),
                    'site.url' => $input->defaultUri(),
                    'localization.default_language' => $input->language(),
                    'localization.route_prefixes_enabled' => false,
                    'content.home_path' => '/home',
                    'user.default_acl_group' => 'registered',
                    'user.menu.enabled' => true,
                    'user.menu.sort_order' => 900,
                    UserFlowConfig::REGISTRATION_MODE_KEY => UserFlowConfig::REGISTRATION_DISABLED,
                    ConfigAuditLogPolicy::ENABLED_KEY => true,
                    ConfigAuditLogPolicy::EVENTS_KEY => ConfigAuditLogPolicy::DEFAULT_CATEGORIES,
                    AccessStatisticsPolicy::ENABLED_KEY => true,
                    AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY => true,
                ],
            ], ActionLogStatus::Skipped],
            ['seed_admin_user', fn (): array => [
                'dry_run' => true,
                'admin_username' => $input->adminUsername(),
                'admin_email' => $input->adminEmail(),
                'admin_password' => '[hidden]',
                'groups' => ['admin'],
            ], ActionLogStatus::Skipped],
            ['seed_initial_content', fn (): array => [
                'dry_run' => true,
                'schema' => 'static_page',
                'path' => '/home',
                'title' => $input->siteTitle(),
            ], ActionLogStatus::Skipped],
            ['clear_cache', fn (): array => [
                'dry_run' => true,
                'command' => [PHP_BINARY, $projectDir.'/bin/console', 'cache:clear', '--env='.$input->appEnv()],
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
