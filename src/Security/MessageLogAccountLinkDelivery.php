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
    public function __construct(private MessageLoggerInterface $messageLogger)
    {
    }

    public function deliver(AccountToken $token, string $plainToken, string $url): void
    {
        $this->messageLogger->log(
            Message::info(MessageCode::ACCOUNT_LINK_DELIVERED, MessageKey::ACCOUNT_LINK_DELIVERED, [
                '%email%' => $token->email(),
                '%type%' => $token->type()->value,
            ]),
            [
                'component' => self::class,
                'account_link' => $url,
                'plain_account_token' => $plainToken,
                'token_uid' => $token->uid(),
                'token_type' => $token->type()->value,
                'recipient_email' => $token->email(),
            ],
        );
    }
}
