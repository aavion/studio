<?php

declare(strict_types=1);

namespace App\Core\Config;

final class ConfigMessageKey
{
    public const CONFIG_KEY_INVALID = 'message.config.key.invalid';
    public const CONFIG_READ_FAILED = 'message.config.read_failed';
    public const CONFIG_WRITE_FAILED = 'message.config.write_failed';
    public const CONFIG_VALUE_INVALID = 'message.config.value_invalid';
}
