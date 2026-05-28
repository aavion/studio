<?php

declare(strict_types=1);

namespace App\Mail;

enum AccountMailFlow: string
{
    case InvitationLink = 'account.invitation.link';
    case RegistrationLink = 'account.registration.link';
    case PasswordResetLink = 'account.password_reset.link';
    case RegistrationApprovalRequested = 'account.registration.approval_requested';
    case RegistrationApproved = 'account.registration.approved';
    case RegistrationRejected = 'account.registration.rejected';
    case RegistrationExistingAccount = 'account.registration.existing_account';
    case PasswordChanged = 'account.password.changed';
    case PasswordChangeDisputed = 'account.password_change.disputed';
    case PasswordChangeReactivated = 'account.password_change.reactivated';
    case AccountClosed = 'account.closed';
}
