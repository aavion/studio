<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Access\AccessLevel;
use App\Core\Config\ConfigValueType;
use App\Core\Log\ConfigAuditLogPolicy;
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
            ['key' => 'localization.default_language', 'value' => $input->language(), 'type' => ConfigValueType::String],
            ['key' => 'localization.route_prefixes_enabled', 'value' => false, 'type' => ConfigValueType::Boolean],
            ['key' => 'content.home_path', 'value' => '/home', 'type' => ConfigValueType::String],
            ['key' => 'user.default_acl_group', 'value' => 'registered', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY, 'value' => false, 'type' => ConfigValueType::Boolean],
            ['key' => UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY, 'value' => UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS, 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, 'value' => '', 'type' => ConfigValueType::String],
            ['key' => UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY, 'value' => '', 'type' => ConfigValueType::String],
            ['key' => 'user.menu.enabled', 'value' => true, 'type' => ConfigValueType::Boolean],
            ['key' => 'user.menu.sort_order', 'value' => 900, 'type' => ConfigValueType::Integer],
            ['key' => UserFlowConfig::REGISTRATION_MODE_KEY, 'value' => UserFlowConfig::REGISTRATION_DISABLED, 'type' => ConfigValueType::String],
            ['key' => ConfigAuditLogPolicy::ENABLED_KEY, 'value' => true, 'type' => ConfigValueType::Boolean],
            ['key' => ConfigAuditLogPolicy::EVENTS_KEY, 'value' => ConfigAuditLogPolicy::DEFAULT_CATEGORIES, 'type' => ConfigValueType::Json],
            ['key' => AccessStatisticsPolicy::ENABLED_KEY, 'value' => true, 'type' => ConfigValueType::Boolean],
            ['key' => AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY, 'value' => true, 'type' => ConfigValueType::Boolean],
        ];
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
     * @return list<array{uid: string, identifier: string, name: array<string, string>, access_level: int, locked: bool, allow_empty: bool}>
     */
    public function aclGroups(): array
    {
        return [
            ['uid' => '00000000-0000-0000-0000-000000000102', 'identifier' => 'registered', 'name' => ['en' => 'Registered', 'de' => 'Registriert'], 'access_level' => AccessLevel::REGISTERED, 'locked' => true, 'allow_empty' => true],
            ['uid' => '00000000-0000-0000-0000-000000000103', 'identifier' => 'editor', 'name' => ['en' => 'Editor', 'de' => 'Editor'], 'access_level' => AccessLevel::EDITOR, 'locked' => false, 'allow_empty' => true],
            ['uid' => '00000000-0000-0000-0000-000000000104', 'identifier' => 'manager', 'name' => ['en' => 'Manager', 'de' => 'Manager'], 'access_level' => AccessLevel::MANAGER, 'locked' => false, 'allow_empty' => true],
            ['uid' => '00000000-0000-0000-0000-000000000105', 'identifier' => 'admin', 'name' => ['en' => 'Admin', 'de' => 'Admin'], 'access_level' => AccessLevel::ADMIN, 'locked' => true, 'allow_empty' => false],
        ];
    }

    public function adminGroupIdentifier(): string
    {
        return 'admin';
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
            'edit_min_level' => AccessLevel::EDITOR,
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
