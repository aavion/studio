<?php

declare(strict_types=1);

namespace App\Content\Read;

enum PublishedContentResolveStatus: string
{
    case Resolved = 'resolved';
    case NotFound = 'not_found';
    case NotPublished = 'not_published';
    case NotPublic = 'not_public';
    case ContextUnavailable = 'context_unavailable';
    case Denied = 'denied';
}
