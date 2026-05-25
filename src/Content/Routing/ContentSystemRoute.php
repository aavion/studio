<?php

declare(strict_types=1);

namespace App\Content\Routing;

final class ContentSystemRoute
{
    public const ROOT_PARENT_UID = '/';
    public const PREFIX = 'system';
    public const VIRTUAL_PARENT_UID = self::PREFIX;

    private function __construct()
    {
    }
}
