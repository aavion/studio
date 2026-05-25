<?php

declare(strict_types=1);

namespace App\Content\Routing;

enum ContentRedirectResolveStatus: string
{
    case NotFound = 'not_found';
    case NoRedirect = 'no_redirect';
    case Resolved = 'resolved';
    case InvalidTarget = 'invalid_target';
    case LoopDetected = 'loop_detected';
    case HopLimitExceeded = 'hop_limit_exceeded';
}
