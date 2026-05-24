<?php

declare(strict_types=1);

namespace App\Core\State;

final class StateMarkerKey
{
    public const CREATED = 'created';
    public const MODIFIED = 'modified';
    public const ACTIVATED = 'activated';
    public const DISABLED = 'disabled';
    public const PUBLISHED = 'published';
    public const UNPUBLISHED = 'unpublished';
    public const ARCHIVED = 'archived';
    public const RESTORED = 'restored';
    public const DELETED = 'deleted';
    public const LOCKED = 'locked';
    public const UNLOCKED = 'unlocked';
    public const LAST_LOGIN = 'last_login';
    public const PASSWORD_CHANGED = 'password_changed';
    public const STATUS_CHANGED = 'status_changed';
}
