<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Content\Routing\ContentRouteLocalization;
use App\Core\Statistics\AccessStatisticsPolicy;
use App\Security\UserFlowConfig;

final readonly class SetupDefaultSeed
{
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
            ['key' => 'content.home_path', 'value' => '/home', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::DEFAULT_ACL_GROUP_KEY, 'value' => '', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, 'value' => $this->setting($input, UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, false), 'type' => ConfigValueType::Boolean],
            ['key' => UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, 'value' => UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS, 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, 'value' => '', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, 'value' => '', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY, 'value' => UserFlowConfig::DEFAULT_DELETED_USER_RETENTION_DAYS, 'type' => ConfigValueType::Integer],
            ['key' => 'user.menu.enabled', 'value' => true, 'type' => ConfigValueType::Boolean],
            ['key' => 'user.menu.sort_order', 'value' => 900, 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_MODE_KEY, 'value' => $this->setting($input, UserFlowConfig::REGISTRATION_MODE_KEY, UserFlowConfig::REGISTRATION_DISABLED), 'type' => ConfigValueType::String],
            ['key' => ConfigAuditLogPolicy::ENABLED_KEY, 'value' => true, 'type' => ConfigValueType::Boolean],
            ['key' => ConfigAuditLogPolicy::EVENTS_KEY, 'value' => ConfigAuditLogPolicy::DEFAULT_CATEGORIES, 'type' => ConfigValueType::Json],
            ['key' => AccessStatisticsPolicy::ENABLED_KEY, 'value' => $this->setting($input, AccessStatisticsPolicy::ENABLED_KEY, true), 'type' => ConfigValueType::Boolean],
            ['key' => AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, 'value' => $this->setting($input, AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, true), 'type' => ConfigValueType::Boolean],
        ];
    }

    private function setting(SetupInput $input, string $key, mixed $default): mixed
    {
        return $input->siteSettings()[$key] ?? $default;
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
     * @return list<array{uid: string, identifier: string, name: array<string, string>, min_role: int}>
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
            'uid' => '10000000-0000-0000-0000-000000000001',
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
            'uid' => '10000000-0000-0000-0000-000000000101',
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
     * @return array{uid: string, slug: string, status: string, parent_uid: string, sort_order: int, schema_version: int, version: int, available_languages: list<string>, available_variants: list<string>, visibility: string, view_min_level: int, edit_min_level: int, manage_min_level: int, template_hint: string}
     */
    public function homeContentItem(): array
    {
        return [
            'uid' => '20000000-0000-0000-0000-000000000001',
            'slug' => 'home',
            'status' => 'published',
            'parent_uid' => '/',
            'sort_order' => 10,
            'schema_version' => 1,
            'version' => 1,
            'available_languages' => ['en', 'de'],
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
            'uid' => '20000000-0000-0000-0000-000000000101',
            'version' => 1,
            'change_summary' => 'Seeded setup homepage.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function homeContentFields(SetupInput $input): array
    {
        return [
            'title' => [
                'en' => $input->siteTitle(),
                'de' => $input->siteTitle(),
            ],
            'subtitle' => [
                'en' => 'Your new Studio site is ready.',
                'de' => 'Deine neue Studio-Seite ist bereit.',
            ],
            'body' => [
                'en' => ['html' => '<p>This placeholder page was created during setup and can be replaced in the editor.</p>'],
                'de' => ['html' => '<p>Diese Platzhalterseite wurde waehrend des Setups angelegt und kann im Editor ersetzt werden.</p>'],
            ],
            'seo_title' => [
                'en' => $input->siteTitle(),
                'de' => $input->siteTitle(),
            ],
        ];
    }
}
