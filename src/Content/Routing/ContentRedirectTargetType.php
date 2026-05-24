<?php

declare(strict_types=1);

namespace App\Content\Routing;

enum ContentRedirectTargetType: string
{
    case InternalRoute = 'internal_route';
    case ExternalUrl = 'external_url';
}
