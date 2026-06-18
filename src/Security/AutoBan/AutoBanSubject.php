<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;

final readonly class AutoBanSubject
{
    public const VISITOR = 'visitor';
    public const IP = 'ip_bucket';

    public static function fromAbuseSubject(AbuseSubject $subject): ?self
    {
        return match ($subject->type()) {
            AbuseSubjectType::Visitor => new self(self::VISITOR, $subject->identifier(), false),
            AbuseSubjectType::IpBucket => new self(self::IP, $subject->identifier(), true),
            default => null,
        };
    }

    public function __construct(
        private string $type,
        private string $identifier,
        private bool $ipDerived = false,
    ) {
    }

    public function type(): string
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

    public function effectiveType(): string
    {
        return self::IP === $this->type ? 'ip' : 'visitor';
    }

    public function key(): string
    {
        return substr(hash('sha256', $this->type.'|'.$this->identifier), 0, 40);
    }
}
