<?php

declare(strict_types=1);

namespace App\Core\Access;

final class AccessMessageKey
{
    public const ACCESS_GRANTED = 'message.access.granted';
    public const ACCESS_DENIED = 'message.access.denied';
    public const ACCESS_LOG_FAILED = 'message.access.log_failed';
    public const ACCESS_LEVEL_INVALID = 'message.access.level.invalid';
    public const ACCESS_GROUP_IDENTIFIER_INVALID = 'message.access.group_identifier.invalid';
    public const ACCESS_GROUP_NAME_INVALID = 'message.access.group_name.invalid';
}
