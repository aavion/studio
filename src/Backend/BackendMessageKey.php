<?php

declare(strict_types=1);

namespace App\Backend;

final class BackendMessageKey
{
    public const BACKEND_ROUTE_NOT_FOUND = 'message.backend.route_not_found';
    public const BACKEND_SETUP_LOCKED = 'message.backend.setup_locked';
    public const BACKEND_ACTION_UNKNOWN = 'message.backend.action.unknown';
    public const BACKEND_ACTION_INVALID_CSRF = 'message.backend.action.invalid_csrf';
    public const BACKEND_ACTION_CACHE_CLEAR_COMPLETED = 'message.backend.action.cache_clear_completed';
}
