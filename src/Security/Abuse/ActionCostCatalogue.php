<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class ActionCostCatalogue
{
    public function costFor(AbuseRequestProfile $profile): ActionCost
    {
        return match ($profile->intent()) {
            RequestIntent::LiveApi => new ActionCost('live_api', 0, false),
            RequestIntent::TurboPrefetch => new ActionCost('website_prefetch', 0, false),
            RequestIntent::CorsPreflight => new ActionCost('api_preflight', 0, false),
            RequestIntent::SuspiciousProbe => new ActionCost('suspicious_probe', 10),
            RequestIntent::Login => new ActionCost('login', 1),
            RequestIntent::Registration => new ActionCost('registration', 5),
            RequestIntent::PasswordReset => new ActionCost('password_reset', 3),
            RequestIntent::Contact => new ActionCost('contact', 3),
            RequestIntent::SchedulerTrigger => new ActionCost('scheduler', 1),
            RequestIntent::SetupApply => new ActionCost('setup_apply', 8),
            RequestIntent::ApiRead => new ActionCost('api_read', 1),
            RequestIntent::ApiWrite => new ActionCost('api_write', 5),
            RequestIntent::SettingsMutation,
            RequestIntent::UserAclMutation,
            RequestIntent::PackageAdminOperation,
            RequestIntent::BackupRestore,
            RequestIntent::ImportOperation,
            RequestIntent::AdminOperation => new ActionCost('admin_mutation', 8),
            RequestIntent::UploadArchiveValidation => new ActionCost('upload_archive', 8),
            RequestIntent::ExportDownload,
            RequestIntent::DiagnosticsSupport => new ActionCost('download_diagnostics', 4),
            RequestIntent::FormSubmit => new ActionCost('website_form', 2),
            default => new ActionCost($this->defaultBucket($profile->family()), 1),
        };
    }

    private function defaultBucket(RequestFamily $family): string
    {
        return match ($family) {
            RequestFamily::Api => 'api_read',
            RequestFamily::Admin, RequestFamily::Editor => 'admin_navigation',
            RequestFamily::Setup => 'setup',
            default => 'website',
        };
    }
}
