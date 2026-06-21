<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Config\Config;

final readonly class SchedulerSettings
{
    public const ENABLED_KEY = 'scheduler.enabled';
    public const GET_AUTH_ENABLED_KEY = 'scheduler.get_auth_enabled';
    public const EXTENSION_ACTION_QUEUES_ENABLED_KEY = 'scheduler.extension_action_queues_enabled';
    public const WEB_TRIGGER_ENABLED_KEY = 'scheduler.web_trigger_enabled';

    public function __construct(private Config $config)
    {
    }

    public function enabled(): bool
    {
        return true === ($this->config->get(self::ENABLED_KEY) ?? true);
    }

    public function getAuthEnabled(): bool
    {
        return true === ($this->config->get(self::GET_AUTH_ENABLED_KEY) ?? false);
    }

    public function extensionActionQueuesEnabled(): bool
    {
        return true === ($this->config->get(self::EXTENSION_ACTION_QUEUES_ENABLED_KEY) ?? false);
    }

    public function webTriggerEnabled(): bool
    {
        return true === ($this->config->get(self::WEB_TRIGGER_ENABLED_KEY) ?? false);
    }
}
