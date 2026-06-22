<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final readonly class ExtensionTranslationFacade
{
    private const MAX_PARAMETER_COUNT = 50;
    private const MAX_PARAMETER_KEY_LENGTH = 80;
    private const MAX_PARAMETER_VALUE_LENGTH = 4096;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $extensionName, string $key, array $parameters = [], ?string $locale = null): string
    {
        if (!ExtensionTranslationKey::isOwnedBy($extensionName, $key)) {
            return '';
        }

        try {
            return $this->translator->trans($key, $this->parameters($parameters), 'messages', $this->locale($locale));
        } catch (Throwable) {
            return '';
        }
    }

    private function locale(?string $locale): ?string
    {
        $locale = is_string($locale) ? trim($locale) : '';

        return '' !== $locale && strlen($locale) <= 20 ? $locale : null;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function parameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (count($normalized) >= self::MAX_PARAMETER_COUNT || !is_string($key)) {
                continue;
            }

            $key = trim($key);
            if ('' === $key || strlen($key) > self::MAX_PARAMETER_KEY_LENGTH) {
                continue;
            }

            if (null === $value || is_scalar($value) || $value instanceof Stringable) {
                $normalized[$key] = substr((string) $value, 0, self::MAX_PARAMETER_VALUE_LENGTH);
            }
        }

        return $normalized;
    }
}
