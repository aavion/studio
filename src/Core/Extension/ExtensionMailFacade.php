<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Validation\EmailAddress;
use App\Core\Validation\IdentifierSpec;
use BackedEnum;
use DateTimeInterface;
use Stringable;
use Throwable;
use UnitEnum;

final readonly class ExtensionMailFacade
{
    private const MAX_PARAMETER_LENGTH = 4096;

    public function __construct(private MessageLoggerInterface $logger)
    {
    }

    /**
     * @param string|list<string> $recipients
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    public function mail(string $extensionName, string $workflow, string|array $recipients, array $parameters = [], array $options = []): bool
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return false;
        }

        $workflow = trim($workflow);
        if (!$this->workflowAllowed($extensionName, $workflow)) {
            return false;
        }

        $normalizedRecipients = $this->recipients($recipients);
        if ([] === $normalizedRecipients) {
            return false;
        }

        $normalizedParameters = $this->parameters($parameters);
        if (null === $normalizedParameters) {
            return false;
        }

        try {
            $this->logger->log(
                Message::debug(
                    ExtensionMessageCode::EXTENSION_RUNTIME_MAIL_STUB,
                    ExtensionMessageKey::EXTENSION_RUNTIME_MAIL_STUB,
                    ['%extension%' => $extensionName, '%workflow%' => $workflow],
                    [
                        'component' => self::class,
                        'source' => 'extension_runtime',
                        'extension' => $extensionName,
                        'mail_workflow' => $workflow,
                        'recipient_emails' => $normalizedRecipients,
                        'recipient_count' => count($normalizedRecipients),
                        'locale' => $this->locale($options),
                        'parameters' => $normalizedParameters,
                        'stub' => true,
                    ],
                ),
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function workflowAllowed(string $extensionName, string $workflow): bool
    {
        return IdentifierSpec::isMachineIdentifier($workflow)
            && str_starts_with($workflow, $extensionName.'.');
    }

    /**
     * @param string|list<string> $recipients
     *
     * @return list<string>
     */
    private function recipients(string|array $recipients): array
    {
        $values = is_string($recipients) ? [$recipients] : $recipients;
        $normalized = [];

        foreach ($values as $recipient) {
            if (!is_string($recipient) || !EmailAddress::isValid($recipient)) {
                return [];
            }

            $normalized[] = EmailAddress::normalize($recipient);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>|null
     */
    private function parameters(array $parameters): ?array
    {
        $normalized = [];

        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !IdentifierSpec::isSnakeIdentifier($key)) {
                return null;
            }

            $stringValue = $this->stringValue($value);
            if (null === $stringValue || strlen($stringValue) > self::MAX_PARAMETER_LENGTH) {
                return null;
            }

            $normalized[$key] = $stringValue;
        }

        ksort($normalized);

        return $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value instanceof Stringable ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function locale(array $options): ?string
    {
        $locale = $options['locale'] ?? null;

        return is_string($locale) && 1 === preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $locale)
            ? $locale
            : null;
    }
}
