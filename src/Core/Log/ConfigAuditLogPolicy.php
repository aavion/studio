<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Config\Config;

final readonly class ConfigAuditLogPolicy implements AuditLogPolicyInterface
{
    public const ENABLED_KEY = 'security.audit.enabled';
    public const EVENTS_KEY = 'security.audit.events';
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_BACKEND_ACTIONS = 'backend_actions';
    public const CATEGORY_OPERATIONS = 'operations';
    public const CATEGORY_EXTENSIONS = 'extensions';
    public const CATEGORY_SETTINGS = 'settings';
    public const CATEGORY_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const DEFAULT_CATEGORIES = [
        self::CATEGORY_AUTHENTICATION,
        self::CATEGORY_BACKEND_ACTIONS,
        self::CATEGORY_OPERATIONS,
        self::CATEGORY_EXTENSIONS,
        self::CATEGORY_SETTINGS,
        self::CATEGORY_OTHER,
    ];

    public function __construct(private Config $config)
    {
    }

    public function allows(string $action): bool
    {
        if (false === ($this->config->get(self::ENABLED_KEY) ?? true)) {
            return false;
        }

        $categories = $this->config->get(self::EVENTS_KEY) ?? self::DEFAULT_CATEGORIES;

        if (!is_array($categories)) {
            $categories = self::DEFAULT_CATEGORIES;
        }

        return in_array($this->categoryFor($action), $categories, true);
    }

    private function categoryFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'auth.') => self::CATEGORY_AUTHENTICATION,
            str_starts_with($action, 'backend.action.') => self::CATEGORY_BACKEND_ACTIONS,
            str_starts_with($action, 'operations.') => self::CATEGORY_OPERATIONS,
            str_starts_with($action, 'extension.') => self::CATEGORY_EXTENSIONS,
            str_starts_with($action, 'settings.') => self::CATEGORY_SETTINGS,
            default => self::CATEGORY_OTHER,
        };
    }
}
