<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigDefaultProviderInterface;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Content\Routing\ContentRouteLocalization;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Localization\LocaleToken;
use App\Scheduler\SchedulerSettings;
use App\Security\UserFlowConfig;

final readonly class SetupDefaultSeed
{
    public function __construct(private ?ConfigDefaultProviderInterface $configDefaults = null)
    {
    }

    /**
     * @return list<array{key: string, value: mixed, type: ConfigValueType}>
     */
    public function configEntries(SetupInput $input): array
    {
        return [
            ['key' => 'site.title', 'value' => $input->siteTitle(), 'type' => ConfigValueType::String],
            ['key' => 'site.url', 'value' => $input->defaultUri(), 'type' => ConfigValueType::String],
            ['key' => ContentRouteLocalization::DEFAULT_LANGUAGE_KEY, 'value' => $input->language(), 'type' => ConfigValueType::String],
            ['key' => ContentRouteLocalization::ENABLED_KEY, 'value' => $this->setting($input, ContentRouteLocalization::ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
            ['key' => 'content.home_path', 'value' => $this->setting($input, 'content.home_path', '/home'), 'type' => ConfigValueType::String],
            ['key' => 'site.footer_copyright', 'value' => $this->setting($input, 'site.footer_copyright', ''), 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::DEFAULT_ACL_GROUP_KEY, 'value' => $this->setting($input, UserFlowConfig::DEFAULT_ACL_GROUP_KEY, ''), 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, 'value' => $this->setting($input, UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
            ['key' => UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, 'value' => $this->setting($input, UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS), 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, 'value' => $this->setting($input, UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, ''), 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, 'value' => $this->setting($input, UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, ''), 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY, 'value' => $this->setting($input, UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY, UserFlowConfig::DEFAULT_DELETED_USER_RETENTION_DAYS), 'type' => ConfigValueType::Integer],
            ['key' => 'user.menu.enabled', 'value' => $this->setting($input, 'user.menu.enabled', true), 'type' => ConfigValueType::Boolean],
            ['key' => 'user.menu.sort_order', 'value' => $this->setting($input, 'user.menu.sort_order', 900), 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_MODE_KEY, 'value' => $this->setting($input, UserFlowConfig::REGISTRATION_MODE_KEY, UserFlowConfig::REGISTRATION_DISABLED), 'type' => ConfigValueType::String],
            ['key' => ConfigAuditLogPolicy::ENABLED_KEY, 'value' => $this->setting($input, ConfigAuditLogPolicy::ENABLED_KEY, true), 'type' => ConfigValueType::Boolean],
            ['key' => ConfigAuditLogPolicy::EVENTS_KEY, 'value' => $this->setting($input, ConfigAuditLogPolicy::EVENTS_KEY, ConfigAuditLogPolicy::DEFAULT_CATEGORIES), 'type' => ConfigValueType::Json],
            ['key' => AccessStatisticsPolicy::ENABLED_KEY, 'value' => $this->setting($input, AccessStatisticsPolicy::ENABLED_KEY, true), 'type' => ConfigValueType::Boolean],
            ['key' => AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, 'value' => $this->setting($input, AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, true), 'type' => ConfigValueType::Boolean],
            ['key' => SchedulerSettings::ENABLED_KEY, 'value' => $this->setting($input, SchedulerSettings::ENABLED_KEY, true), 'type' => ConfigValueType::Boolean],
            ['key' => SchedulerSettings::GET_AUTH_ENABLED_KEY, 'value' => $this->setting($input, SchedulerSettings::GET_AUTH_ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
            ['key' => SchedulerSettings::PACKAGE_ACTION_QUEUES_ENABLED_KEY, 'value' => $this->setting($input, SchedulerSettings::PACKAGE_ACTION_QUEUES_ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
            ['key' => SchedulerSettings::WEB_TRIGGER_ENABLED_KEY, 'value' => $this->setting($input, SchedulerSettings::WEB_TRIGGER_ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
        ];
    }

    private function setting(SetupInput $input, string $key, mixed $default): mixed
    {
        return $input->siteSettings()[$key] ?? $this->default($key, $default);
    }

    private function default(string $key, mixed $fallback): mixed
    {
        if (null !== $this->configDefaults && $this->configDefaults->hasDefault($key)) {
            return $this->configDefaults->defaultValue($key);
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function configMap(SetupInput $input): array
    {
        $settings = [];

        foreach ($this->configEntries($input) as $entry) {
            $settings[$entry['key']] = $entry['value'];
        }

        return $settings;
    }

    /**
     * @return list<array{uid: string, identifier: string, name: string, min_role: int}>
     */
    public function aclGroups(): array
    {
        return [];
    }

    public function homePath(): string
    {
        return '/home';
    }

    /**
     * @return array{uid: string, identifier: string, source: string, locked: bool, labels: array<string, string>, descriptions: array<string, string>}
     */
    public function contentSchema(): array
    {
        return [
            'uid' => '10000000-0000-7000-8000-000000000001',
            'identifier' => 'static_page',
            'source' => 'setup',
            'locked' => true,
            'labels' => ['en' => 'Static page', 'de' => 'Statische Seite'],
            'descriptions' => [
                'en' => 'General pages with a rich text body.',
                'de' => 'Allgemeine Seiten mit Rich-Text-Inhalt.',
            ],
        ];
    }

    /**
     * @return array{uid: string, version: int, title: array<string, string>, definition: array<string, mixed>}
     */
    public function contentSchemaVersion(): array
    {
        return [
            'uid' => '10000000-0000-7000-8000-000000000101',
            'version' => 1,
            'title' => ['en' => 'Static page schema', 'de' => 'Schema fuer statische Seiten'],
            'definition' => [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true, 'localized' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true, 'localized' => true],
                    ['identifier' => 'body', 'type' => 'rich_text', 'required' => true, 'localized' => true],
                    ['identifier' => 'seo_title', 'type' => 'text', 'required' => false, 'localized' => true],
                ],
                'order' => ['title', 'subtitle', 'body', 'seo_title'],
            ],
        ];
    }

    /**
     * @param list<string> $availableLanguages
     *
     * @return array{uid: string, slug: string, status: string, parent_uid: string, sort_order: int, schema_version: int, version: int, available_languages: list<string>, available_variants: list<string>, visibility: string, view_min_level: int, edit_min_level: int, manage_min_level: int, template_hint: string}
     */
    public function homeContentItem(array $availableLanguages): array
    {
        $availableLanguages = array_values(array_unique(array_filter(
            $availableLanguages,
            static fn (string $language): bool => LocaleToken::isValid($language),
        )));

        return [
            'uid' => '20000000-0000-7000-8000-000000000001',
            'slug' => 'home',
            'status' => 'published',
            'parent_uid' => '/',
            'sort_order' => 10,
            'schema_version' => 1,
            'version' => 1,
            'available_languages' => [] === $availableLanguages ? [LocaleToken::systemDefault()] : $availableLanguages,
            'available_variants' => ['default'],
            'visibility' => 'public',
            'view_min_level' => AccessLevel::PUBLIC,
            'edit_min_level' => AccessLevel::AUTHOR,
            'manage_min_level' => AccessLevel::MANAGER,
            'template_hint' => 'home',
        ];
    }

    /**
     * @return array{uid: string, version: int, change_summary: string}
     */
    public function homeContentRevision(): array
    {
        return [
            'uid' => '20000000-0000-7000-8000-000000000101',
            'version' => 1,
            'change_summary' => 'Seeded setup homepage.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function homeContentFields(SetupInput $input): array
    {
        $language = $input->language();

        return [
            'title' => [
                $language => $input->siteTitle(),
            ],
            'subtitle' => [
                $language => 'Your new Studio site is ready.',
            ],
            'body' => [
                $language => ['html' => '<p>This placeholder page was created during setup and can be replaced in the editor.</p>'],
            ],
            'seo_title' => [
                $language => $input->siteTitle(),
            ],
        ];
    }
}
