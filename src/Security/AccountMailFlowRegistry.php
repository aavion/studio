<?php

declare(strict_types=1);

namespace App\Security;

use LogicException;

final readonly class AccountMailFlowRegistry
{
    /**
     * @return list<AccountMailFlowDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->definitionMap());
    }

    public function definition(AccountMailFlow $flow): AccountMailFlowDefinition
    {
        return $this->definitionMap()[$flow->value] ?? throw new LogicException(sprintf('Account mail flow "%s" is not registered.', $flow->value));
    }

    /**
     * @return array<string, AccountMailFlowDefinition>
     */
    private function definitionMap(): array
    {
        $group = 'admin.mail_templates.groups.users';

        return [
            AccountMailFlow::InvitationLink->value => new AccountMailFlowDefinition(AccountMailFlow::InvitationLink, AccountMailFlow::InvitationLink->value, $group, 'admin.mail_templates.flows.account_invitation_link', ['email', 'action_url', 'expires_at']),
            AccountMailFlow::RegistrationLink->value => new AccountMailFlowDefinition(AccountMailFlow::RegistrationLink, AccountMailFlow::RegistrationLink->value, $group, 'admin.mail_templates.flows.account_registration_link', ['email', 'action_url', 'expires_at']),
            AccountMailFlow::PasswordResetLink->value => new AccountMailFlowDefinition(AccountMailFlow::PasswordResetLink, AccountMailFlow::PasswordResetLink->value, $group, 'admin.mail_templates.flows.account_password_reset_link', ['email', 'username', 'action_url', 'expires_at']),
            AccountMailFlow::RegistrationApprovalRequested->value => new AccountMailFlowDefinition(AccountMailFlow::RegistrationApprovalRequested, AccountMailFlow::RegistrationApprovalRequested->value, $group, 'admin.mail_templates.flows.account_registration_approval_requested', ['email']),
            AccountMailFlow::RegistrationApproved->value => new AccountMailFlowDefinition(AccountMailFlow::RegistrationApproved, AccountMailFlow::RegistrationApproved->value, $group, 'admin.mail_templates.flows.account_registration_approved', ['email']),
            AccountMailFlow::RegistrationRejected->value => new AccountMailFlowDefinition(AccountMailFlow::RegistrationRejected, AccountMailFlow::RegistrationRejected->value, $group, 'admin.mail_templates.flows.account_registration_rejected', ['email']),
            AccountMailFlow::RegistrationExistingAccount->value => new AccountMailFlowDefinition(AccountMailFlow::RegistrationExistingAccount, AccountMailFlow::RegistrationExistingAccount->value, $group, 'admin.mail_templates.flows.account_registration_existing_account', ['email', 'username']),
            AccountMailFlow::PasswordChanged->value => new AccountMailFlowDefinition(AccountMailFlow::PasswordChanged, AccountMailFlow::PasswordChanged->value, $group, 'admin.mail_templates.flows.account_password_changed', ['email', 'username', 'action_url', 'expires_at']),
            AccountMailFlow::PasswordChangeDisputed->value => new AccountMailFlowDefinition(AccountMailFlow::PasswordChangeDisputed, AccountMailFlow::PasswordChangeDisputed->value, $group, 'admin.mail_templates.flows.account_password_change_disputed', ['email', 'username', 'user_uid']),
            AccountMailFlow::PasswordChangeReactivated->value => new AccountMailFlowDefinition(AccountMailFlow::PasswordChangeReactivated, AccountMailFlow::PasswordChangeReactivated->value, $group, 'admin.mail_templates.flows.account_password_change_reactivated', ['email', 'username', 'user_uid']),
        ];
    }
}
