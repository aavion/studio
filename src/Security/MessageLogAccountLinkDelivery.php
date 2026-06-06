<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Validation\EmailAddress;
use App\Entity\AccountToken;
use App\Mail\AccountMailFlow;
use App\Mail\MailDeliveryMessage;
use App\Mail\MailFlowRegistry;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;

final readonly class MessageLogAccountLinkDelivery implements AccountLinkDeliveryInterface
{
    public function __construct(
        private MessageLoggerInterface $messageLogger,
        private MailFlowRegistry $flowRegistry,
    ) {
    }

    public function deliver(AccountToken $token, AccountMailFlow $flow, string $url, string $locale, array $parameters = []): void
    {
        $this->logMailMessage(
            new MailDeliveryMessage(
                $flow,
                $token->email(),
                $locale,
                [
                    ...$this->tokenParameters($token),
                    ...$parameters,
                    'action_url' => $url,
                ],
                actionUrl: $url,
                tokenUid: $token->uid(),
                tokenType: $token->type()->value,
            ),
            true,
        );
    }

    public function notify(AccountToken $token, AccountMailFlow $flow, ?string $recipientEmail = null, string $locale = '', array $parameters = []): void
    {
        $adminFacing = in_array($flow, [
            AccountMailFlow::RegistrationApprovalRequested,
            AccountMailFlow::PasswordChangeDisputed,
        ], true);
        $recipientEmail ??= $adminFacing ? null : $token->email();

        $this->logMailMessage(
            new MailDeliveryMessage(
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
            'email' => EmailAddress::normalize($recipientEmail),
            ...$parameters,
        ];

        $this->logMailMessage(new MailDeliveryMessage($flow, $recipientEmail, $locale, $parameters), true);
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

    private function logMailMessage(MailDeliveryMessage $mailMessage, bool $recipientConfigured): void
    {
        $definition = $this->flowRegistry->definition($mailMessage->flow());
        $recipient = $mailMessage->recipientEmail();

        $this->messageLogger->log(
            Message::debug(SecurityMessageCode::ACCOUNT_MAIL_STUB_QUEUED, SecurityMessageKey::ACCOUNT_MAIL_STUB_QUEUED, [
                '%email%' => $recipient ?? 'configured administrator',
                '%flow%' => $mailMessage->flowKey(),
            ]),
            [
                'component' => self::class,
                'mail_flow_key' => $mailMessage->flowKey(),
                'mail_template_key' => $definition->templateKey(),
                'mail_group_key' => $definition->groupKey(),
                'mail_label_key' => $definition->labelKey(),
                'recipient_email' => $recipient,
                'recipient_configured' => $recipientConfigured,
                'locale' => $mailMessage->locale(),
                'parameters' => $mailMessage->parameters(),
                'available_parameters' => $definition->parameterKeys(),
                'action_url' => $mailMessage->actionUrl(),
                'token_uid' => $mailMessage->tokenUid(),
                'token_type' => $mailMessage->tokenType(),
            ],
        );
    }
}
