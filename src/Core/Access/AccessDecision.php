<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Message\Message;

final readonly class AccessDecision
{
    public function __construct(
        private bool $granted,
        private AccessCapability $capability,
        private AccessRule $rule,
        private string $ruleSource,
        private Message $message,
    ) {
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function capability(): AccessCapability
    {
        return $this->capability;
    }

    public function rule(): AccessRule
    {
        return $this->rule;
    }

    public function ruleSource(): string
    {
        return $this->ruleSource;
    }

    public function message(): Message
    {
        return $this->message;
    }

    /**
     * @return array{granted: bool, capability: string, rule_source: string, rule: array{min_level: int|null, group_identifiers: list<string>, inherited: bool}, message: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'granted' => $this->granted,
            'capability' => $this->capability->value,
            'rule_source' => $this->ruleSource,
            'rule' => $this->rule->toArray(),
            'message' => $this->message->toArray(),
        ];
    }
}
