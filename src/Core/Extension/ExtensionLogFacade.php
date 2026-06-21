<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use Throwable;

final readonly class ExtensionLogFacade
{
    private const MAX_MESSAGE_LENGTH = 240;

    public function __construct(private MessageLoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $extensionName, string $level, string $message, array $context = []): bool
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return false;
        }

        $messageLevel = $this->level($level);
        if (null === $messageLevel) {
            return false;
        }

        $message = $this->message($message);
        $translationKey = $this->isValidTranslationKey($message) ? $message : ExtensionMessageKey::EXTENSION_RUNTIME_LOG;
        $parameters = $translationKey === $message ? [] : ['%message%' => $message];
        $messageContext = [
            'extension' => $extensionName,
            'source' => 'extension_runtime',
            'extension_context' => $context,
        ];

        try {
            $this->logger->log($this->typedMessage($messageLevel, $translationKey, $parameters, $messageContext));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function level(string $level): ?MessageLevel
    {
        return match (strtolower(trim($level))) {
            'success', 'notice' => MessageLevel::Success,
            'exception', 'critical' => MessageLevel::Exception,
            'error' => MessageLevel::Error,
            'warning', 'warn' => MessageLevel::Warning,
            'info' => MessageLevel::Info,
            'debug' => MessageLevel::Debug,
            default => null,
        };
    }

    private function message(string $message): string
    {
        $message = trim($message);
        if (strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return '' === $message ? 'extension.runtime.log' : $message;
        }

        return substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $context
     */
    private function typedMessage(MessageLevel $level, string $translationKey, array $parameters, array $context): Message
    {
        return match ($level) {
            MessageLevel::Success => Message::success($translationKey, $parameters, $context),
            MessageLevel::Exception => Message::exception(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $translationKey, $parameters, $context),
            MessageLevel::Error => Message::error(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $translationKey, $parameters, $context),
            MessageLevel::Warning => Message::warning(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $translationKey, $parameters, $context),
            MessageLevel::Info => Message::info(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $translationKey, $parameters, $context),
            MessageLevel::Debug => Message::debug(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $translationKey, $parameters, $context),
        };
    }

    private function isValidTranslationKey(string $translationKey): bool
    {
        return 1 === preg_match('/^message(?:\.[a-z][a-z0-9_]*)+$/', $translationKey);
    }
}
