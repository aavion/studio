<?php

declare(strict_types=1);

namespace App\Security\Abuse;

enum AbuseSubjectType: string
{
    case Visitor = 'visitor';
    case IpBucket = 'ip_bucket';
    case User = 'user';
    case ApiKey = 'api_key';
    case ApiKeyPrefix = 'api_key_prefix';
    case SchedulerCredential = 'scheduler_credential';
    case SubmittedAccount = 'submitted_account';
    case Combined = 'combined';
}
