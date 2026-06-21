<?php

declare(strict_types=1);

namespace App\View;

final class ViewMessageKey
{
    public const VIEW_DYNAMIC_INJECTION_RENDER_FAILED = 'message.view.dynamic_injection.render_failed';
    public const VIEW_TEMPLATE_NAMESPACE_UNSUPPORTED = 'message.view.template_namespace.unsupported';
    public const VIEW_UI_ALERT_TOPIC_USER_INVALID = 'message.view.ui_alert.topic_user_invalid';
    public const VIEW_UI_ALERT_TOPIC_ROLE_INVALID = 'message.view.ui_alert.topic_role_invalid';
    public const VIEW_UI_ALERT_TOPIC_ACL_GROUP_INVALID = 'message.view.ui_alert.topic_acl_group_invalid';
}
