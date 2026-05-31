<?php

declare(strict_types=1);

namespace App\Mail;

use BackedEnum;
use InvalidArgumentException;

final readonly class MailFlowDefinition
{
    /**
     * @param list<string> $requiredParameters
     * @param list<string> $optionalParameters
     */
    public function __construct(
        private BackedEnum $flow,
        private string $templateKey,
        private string $groupKey,
        private string $labelKey,
        private array $requiredParameters,
        private array $optionalParameters = [],
    ) {
        self::assertTranslationKey($templateKey, 'Template key');
        self::assertTranslationKey($groupKey, 'Group key');
        self::assertTranslationKey($labelKey, 'Label key');

        foreach ([...$requiredParameters, ...$optionalParameters] as $parameter) {
            self::assertParameter($parameter);
        }
    }

    public function flow(): BackedEnum
    {
        return $this->flow;
    }

    public function flowKey(): string
    {
        return (string) $this->flow->value;
    }

    public function templateKey(): string
    {
        return $this->templateKey;
    }

    public function groupKey(): string
    {
        return $this->groupKey;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    /**
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        return $this->requiredParameters;
    }

    /**
     * @return list<string>
     */
    public function optionalParameters(): array
    {
        return $this->optionalParameters;
    }

    /**
     * @return list<string>
     */
    public function parameterKeys(): array
    {
        return array_values(array_unique([...$this->requiredParameters, ...$this->optionalParameters]));
    }

    private static function assertParameter(string $parameter): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $parameter)) {
            throw new InvalidArgumentException(sprintf('Invalid mail parameter key "%s".', $parameter));
        }
    }

    private static function assertTranslationKey(string $key, string $label): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be a valid translation key.', $label, $key));
        }
    }
}
