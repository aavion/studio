<?php

declare(strict_types=1);

namespace App\Security;

final class SecurityMessageCode
{
    public const ACL_GROUP_UPDATED = 'acl.group_updated';
    public const ACL_GROUP_DELETED = 'acl.group_deleted';
    public const ACL_GROUP_APPLY_BLOCKED = 'acl.group_apply_blocked';
    public const USER_EMAIL_DUPLICATE = 'user.email_duplicate';
    public const USER_USERNAME_DUPLICATE = 'user.username_duplicate';
    public const ACCOUNT_LINK_INVALID = 'account.link_invalid';
    public const ACCOUNT_LINK_STALE_GROUPS = 'account.link_stale_groups';
    public const ACCOUNT_LINK_DELIVERED = 'account.link_delivered';
    public const ACCOUNT_NOTIFICATION_DELIVERED = 'account.notification_delivered';
    public const ACCOUNT_MAIL_STUB_QUEUED = 'account.mail_stub_queued';
    public const ACCOUNT_APP_SECRET_ROTATION_MANUAL_OWNER_RESET_REQUIRED = 'account.app_secret_rotation.manual_owner_reset_required';
    public const API_KEY_AUTHENTICATION_FAILED = 'api_key.authentication_failed';
    public const API_KEY_PERMISSION_DENIED = 'api_key.permission_denied';
    public const API_KEY_PERMISSION_WRITE_REQUIRED = 'api_key.permission_write_required';
    public const API_KEY_PERMISSION_REVOKED = 'api_key.permission_revoked';
}
