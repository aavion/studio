<?php

declare(strict_types=1);

namespace App\Mail;

use BackedEnum;
use LogicException;

final readonly class MailFlowRegistry
{
    /**
     * @return list<MailFlowDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->definitionMap());
    }

    public function definition(BackedEnum|string $flow): MailFlowDefinition
    {
        $flowKey = $flow instanceof BackedEnum ? (string) $flow->value : $flow;

        return $this->definitionMap()[$flowKey] ?? throw new LogicException(sprintf('Mail flow "%s" is not registered.', $flowKey));
    }

    /**
     * @return array<string, MailFlowDefinition>
     */
    private function definitionMap(): array
    {
        $group = 'admin.mail_templates.groups.users';

        return [
            AccountMailFlow::InvitationLink->value => new MailFlowDefinition(AccountMailFlow::InvitationLink, AccountMailFlow::InvitationLink->value, $group, 'admin.mail_templates.flows.account_invitation_link', ['email', 'action_url', 'expires_at']),
            AccountMailFlow::RegistrationLink->value => new MailFlowDefinition(AccountMailFlow::RegistrationLink, AccountMailFlow::RegistrationLink->value, $group, 'admin.mail_templates.flows.account_registration_link', ['email', 'action_url', 'expires_at']),
            AccountMailFlow::PasswordResetLink->value => new MailFlowDefinition(AccountMailFlow::PasswordResetLink, AccountMailFlow::PasswordResetLink->value, $group, 'admin.mail_templates.flows.account_password_reset_link', ['email', 'username', 'action_url', 'expires_at']),
            AccountMailFlow::RegistrationApprovalRequested->value => new MailFlowDefinition(AccountMailFlow::RegistrationApprovalRequested, AccountMailFlow::RegistrationApprovalRequested->value, $group, 'admin.mail_templates.flows.account_registration_approval_requested', ['email']),
            AccountMailFlow::RegistrationApproved->value => new MailFlowDefinition(AccountMailFlow::RegistrationApproved, AccountMailFlow::RegistrationApproved->value, $group, 'admin.mail_templates.flows.account_registration_approved', ['email']),
            AccountMailFlow::RegistrationRejected->value => new MailFlowDefinition(AccountMailFlow::RegistrationRejected, AccountMailFlow::RegistrationRejected->value, $group, 'admin.mail_templates.flows.account_registration_rejected', ['email']),
            AccountMailFlow::RegistrationExistingAccount->value => new MailFlowDefinition(AccountMailFlow::RegistrationExistingAccount, AccountMailFlow::RegistrationExistingAccount->value, $group, 'admin.mail_templates.flows.account_registration_existing_account', ['email', 'username']),
            AccountMailFlow::PasswordChanged->value => new MailFlowDefinition(AccountMailFlow::PasswordChanged, AccountMailFlow::PasswordChanged->value, $group, 'admin.mail_templates.flows.account_password_changed', ['email', 'username', 'action_url', 'expires_at']),
            AccountMailFlow::PasswordChangeDisputed->value => new MailFlowDefinition(AccountMailFlow::PasswordChangeDisputed, AccountMailFlow::PasswordChangeDisputed->value, $group, 'admin.mail_templates.flows.account_password_change_disputed', ['email', 'username', 'user_uid']),
            AccountMailFlow::PasswordChangeReactivated->value => new MailFlowDefinition(AccountMailFlow::PasswordChangeReactivated, AccountMailFlow::PasswordChangeReactivated->value, $group, 'admin.mail_templates.flows.account_password_change_reactivated', ['email', 'username', 'user_uid']),
        ];
    }
}
