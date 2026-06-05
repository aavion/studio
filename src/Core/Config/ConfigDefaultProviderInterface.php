<?php

declare(strict_types=1);

namespace App\Core\Config;

interface ConfigDefaultProviderInterface
{
    public function hasDefault(string $key): bool;

    public function defaultValue(string $key): mixed;
}
