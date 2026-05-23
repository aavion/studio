<?php

declare(strict_types=1);

namespace App\Core\Config;

enum ConfigValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Json = 'json';
}
