<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use RuntimeException;

final class SetupStepFailedException extends RuntimeException
{
    public function __construct(string $message = '', private readonly ?Message $messageObject = null)
    {
        parent::__construct($message);
    }

    public static function fromMessage(Message $message): self
    {
        return new self($message->translationKey(), $message);
    }

    public function messageObject(): ?Message
    {
        return $this->messageObject;
    }
}
