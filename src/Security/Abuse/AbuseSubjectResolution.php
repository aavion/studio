<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class AbuseSubjectResolution
{
    /**
     * @param list<AbuseSubject> $subjects
     */
    public function __construct(private array $subjects)
    {
    }

    /**
     * @return list<AbuseSubject>
     */
    public function subjects(): array
    {
        return $this->subjects;
    }

    public function primary(): ?AbuseSubject
    {
        foreach ([AbuseSubjectType::Visitor, AbuseSubjectType::User, AbuseSubjectType::ApiKey, AbuseSubjectType::IpBucket] as $type) {
            $subject = $this->first($type);
            if (null !== $subject) {
                return $subject;
            }
        }

        return $this->subjects[0] ?? null;
    }

    public function first(AbuseSubjectType $type): ?AbuseSubject
    {
        foreach ($this->subjects as $subject) {
            if ($subject->type() === $type) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, identifier: string, ip_derived: bool, context: array<string, scalar|null>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (AbuseSubject $subject): array => $subject->toArray(), $this->subjects);
    }
}
