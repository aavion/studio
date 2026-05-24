<?php

declare(strict_types=1);

namespace App\Core\State;

final class StateSubjectType
{
    public const ACL_GROUP = 'acl_group';
    public const CONTENT_ITEM = 'content_item';
    public const CONTENT_REVISION = 'content_revision';
    public const CONTENT_SCHEMA = 'content_schema';
    public const CONTENT_SCHEMA_VERSION = 'content_schema_version';
    public const USER_ACCOUNT = 'user_account';
}
