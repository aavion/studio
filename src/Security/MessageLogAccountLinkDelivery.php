<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Entity\AccountToken;

final readonly class MessageLogAccountLinkDelivery implements AccountLinkDeliveryInterface
{
    public function __construct(
        private MessageLoggerInterface $messageLogger,
        private AccountMailFlowRegistry $flowRegistry,
    ) {
    }

    public function deliver(AccountToken $token, AccountMailFlow $flow, string $plainToken, string $url, string $locale, array $parameters = []): void
    {
        $this->logMailMessage(
            new AccountMailMessage(
                $flow,
                $token->email(),
                $locale,
                [
                    ...$this->tokenParameters($token),
                    ...$parameters,
                    'action_url' => $url,
                ],
                actionUrl: $url,
                debugPlainToken: $plainToken,
                tokenUid: $token->uid(),
                tokenType: $token->type()->value,
            ),
            true,
        );
    }

    public function notify(AccountToken $token, AccountMailFlow $flow, ?string $recipientEmail = null, string $locale = 'en', array $parameters = []): void
    {
        $adminFacing = in_array($flow, [
            AccountMailFlow::RegistrationApprovalRequested,
            AccountMailFlow::PasswordChangeDisputed,
        ], true);
        $recipientEmail ??= $adminFacing ? null : $token->email();

        $this->logMailMessage(
            new AccountMailMessage(
                $flow,
                $recipientEmail,
                $locale,
                [
                    ...$this->tokenParameters($token),
                    ...$parameters,
                ],
                tokenUid: $token->uid(),
                tokenType: $token->type()->value,
            ),
            null !== $recipientEmail,
        );
    }

    public function notifyAddress(string $recipientEmail, AccountMailFlow $flow, string $locale, array $parameters = []): void
    {
        $parameters = [
            'email' => strtolower($recipientEmail),
            ...$parameters,
        ];

        $this->logMailMessage(new AccountMailMessage($flow, $recipientEmail, $locale, $parameters), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenParameters(AccountToken $token): array
    {
        $user = $token->user();

        return [
            'email' => $token->email(),
            'username' => $user?->username(),
            'expires_at' => $token->expiresAt()->format(DATE_ATOM),
        ];
    }

    private function logMailMessage(AccountMailMessage $mailMessage, bool $recipientConfigured): void
    {
        $definition = $this->flowRegistry->definition($mailMessage->flow());
        $recipient = $mailMessage->recipientEmail();

        $this->messageLogger->log(
            Message::debug(MessageCode::ACCOUNT_MAIL_STUB_QUEUED, MessageKey::ACCOUNT_MAIL_STUB_QUEUED, [
                '%email%' => $recipient ?? 'configured administrator',
                '%flow%' => $mailMessage->flow()->value,
            ]),
            [
                'component' => self::class,
                'mail_flow_key' => $mailMessage->flow()->value,
                'mail_template_key' => $definition->templateKey(),
                'mail_group_key' => $definition->groupKey(),
                'mail_label_key' => $definition->labelKey(),
                'recipient_email' => $recipient,
                'recipient_configured' => $recipientConfigured,
                'locale' => $mailMessage->locale(),
                'parameters' => $mailMessage->parameters(),
                'available_parameters' => $definition->parameterKeys(),
                'action_url' => $mailMessage->actionUrl(),
                'debug_plain_token' => $mailMessage->debugPlainToken(),
                'token_uid' => $mailMessage->tokenUid(),
                'token_type' => $mailMessage->tokenType(),
            ],
        );
    }
}
