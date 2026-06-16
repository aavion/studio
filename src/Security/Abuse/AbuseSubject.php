<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class AbuseSubject
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        private AbuseSubjectType $type,
        private string $identifier,
        private bool $ipDerived = false,
        private array $context = [],
    ) {
    }

    public function type(): AbuseSubjectType
    {
        return $this->type;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function ipDerived(): bool
    {
        return $this->ipDerived;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{type: string, identifier: string, ip_derived: bool, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'identifier' => $this->identifier,
            'ip_derived' => $this->ipDerived,
            'context' => $this->context,
        ];
    }
}
