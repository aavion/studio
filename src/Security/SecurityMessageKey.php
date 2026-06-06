<?php

declare(strict_types=1);

namespace App\Security;

final class SecurityMessageKey
{
    public const ACL_GROUP_UPDATED = 'message.acl.group_updated';
    public const ACL_GROUP_DELETED = 'message.acl.group_deleted';
    public const ACL_GROUP_APPLY_NOT_FOUND = 'message.acl.group_apply.not_found';
    public const ACL_GROUP_APPLY_ACTION_INVALID = 'message.acl.group_apply.action_invalid';
    public const ACL_GROUP_APPLY_UPDATE_BLOCKED = 'message.acl.group_apply.update_blocked';
    public const ACL_GROUP_APPLY_DELETE_BLOCKED = 'message.acl.group_apply.delete_blocked';
    public const USERNAME_INVALID = 'message.user.username.invalid';
    public const USER_EMAIL_INVALID = 'message.user.email.invalid';
    public const USER_EMAIL_DUPLICATE = 'message.user.email.duplicate';
    public const USER_USERNAME_DUPLICATE = 'message.user.username.duplicate';
    public const ACCOUNT_TOKEN_HASH_INVALID = 'message.account_token.hash.invalid';
    public const ACCOUNT_LINK_INVALID = 'message.account_link.invalid';
    public const ACCOUNT_LINK_STALE_GROUPS = 'message.account_link.stale_groups';
    public const ACCOUNT_LINK_DELIVERED = 'message.account_link.delivered';
    public const ACCOUNT_NOTIFICATION_DELIVERED = 'message.account_link.notification_delivered';
    public const ACCOUNT_MAIL_STUB_QUEUED = 'message.account_mail.stub_queued';
    public const API_KEY_PREFIX_INVALID = 'message.api_key.prefix.invalid';
    public const API_KEY_HMAC_HASH_INVALID = 'message.api_key.hmac_hash.invalid';
    public const API_KEY_ENCRYPTED_KEY_EMPTY = 'message.api_key.encrypted_key.empty';
    public const API_KEY_STATUS_INVALID = 'message.api_key.status.invalid';
    public const API_KEY_STATUS_READ_WRITE = 'message.api_key.status.read_write';
    public const API_KEY_STATUS_READ_ONLY = 'message.api_key.status.read_only';
    public const API_KEY_STATUS_REVOKED = 'message.api_key.status.revoked';
    public const API_KEY_CREATED = 'message.api_key.created';
    public const API_KEY_REVOKED = 'message.api_key.revoked';
    public const API_KEY_REVEALED = 'message.api_key.revealed';
    public const API_KEY_NOT_FOUND = 'message.api_key.not_found';
    public const API_KEY_AUTHENTICATION_FAILED = 'message.api_key.authentication_failed';
    public const API_KEY_REAUTHENTICATION_REQUIRED = 'message.api_key.reauthentication_required';
    public const API_KEY_PERMISSION_WRITE_REQUIRED = 'message.api_key.permission.write_required';
    public const API_KEY_PERMISSION_REVOKED = 'message.api_key.permission.revoked';
}
