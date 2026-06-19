<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Extension\ExtensionIdentity;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionScope;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'extension')]
#[ORM\UniqueConstraint(name: 'uniq_extension_name', columns: ['extension_name'])]
#[ORM\Index(name: 'idx_extension_status', columns: ['status'])]
class Extension
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'extension_scopes', type: 'json')]
    private array $scopeValues;

    #[ORM\Column(name: 'extension_name', length: 120)]
    private string $extensionName;

    #[ORM\Column(length: 512)]
    private string $path;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $manifestVersion = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $installedVersion = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $availableVersion = null;

    #[ORM\Column(enumType: ExtensionStatus::class)]
    private ExtensionStatus $status;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    /**
     * @param list<ExtensionScope|string> $scopes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        array $scopes,
        string $extensionName,
        string $path,
        ExtensionStatus $status = ExtensionStatus::Inactive,
        array $metadata = [],
        ?DateTimeImmutable $modifiedAt = null,
        ?string $manifestVersion = null,
        ?string $installedVersion = null,
        ?string $availableVersion = null,
    ) {
        $this->uid = Uid::assert($uid, 'Extension UID');
        $this->scopeValues = ExtensionIdentity::normalizeScopes($scopes);
        $this->extensionName = ExtensionIdentity::assertExtensionName($extensionName);
        $this->path = $path;
        $this->manifestVersion = $manifestVersion;
        $this->installedVersion = $installedVersion;
        $this->availableVersion = $availableVersion;
        $this->status = $status;
        $this->metadata = $metadata;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }
    public function uid(): string
    {
        return $this->uid;
    }

    /**
     * @return list<ExtensionScope>
     */
    public function scopes(): array
    {
        return array_map(
            static fn (string $scope): ExtensionScope => ExtensionScope::from($scope),
            $this->scopeValues,
        );
    }

    /**
     * @return list<string>
     */
    public function scopeValues(): array
    {
        return $this->scopeValues;
    }

    public function hasScope(ExtensionScope $scope): bool
    {
        return in_array($scope->value, $this->scopeValues, true);
    }

    public function extensionName(): string
    {
        return $this->extensionName;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function manifestVersion(): ?string
    {
        return $this->manifestVersion;
    }

    public function installedVersion(): ?string
    {
        return $this->installedVersion;
    }

    public function availableVersion(): ?string
    {
        return $this->availableVersion;
    }

    public function status(): ExtensionStatus
    {
        return $this->status;
    }

    public function updateAvailableVersion(?string $availableVersion): bool
    {
        $availableVersion = is_string($availableVersion) && '' !== trim($availableVersion) ? trim($availableVersion) : null;

        if ($this->availableVersion === $availableVersion) {
            return false;
        }

        $this->availableVersion = $availableVersion;
        $this->touch();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, array{key: string, source_key: string, value: mixed, type: string}>
     */
    public function manifestVariables(): array
    {
        $variables = $this->metadata['variables'] ?? [];

        return is_array($variables) ? $variables : [];
    }

    /**
     * @param list<ExtensionScope|string> $scopes
     * @param array<string, mixed> $metadata
     */
    public function syncRegistryState(array $scopes, string $path, ?string $manifestVersion, array $metadata): bool
    {
        $scopeValues = ExtensionIdentity::normalizeScopes($scopes);
        $nextStatus = match ($this->status) {
            ExtensionStatus::Removed, ExtensionStatus::Faulty => ExtensionStatus::Inactive,
            default => $this->status,
        };
        $changed = $this->scopeValues !== $scopeValues
            || $this->path !== $path
            || $this->manifestVersion !== $manifestVersion
            || $this->installedVersion !== $manifestVersion
            || $this->metadata !== $metadata
            || $this->status !== $nextStatus;

        if (!$changed) {
            return false;
        }

        $this->scopeValues = $scopeValues;
        $this->path = $path;
        $this->manifestVersion = $manifestVersion;
        $this->installedVersion = $manifestVersion;
        $this->metadata = $metadata;
        $this->status = $nextStatus;
        $this->touch();

        return true;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markFaulty(string $path, ?string $manifestVersion, array $metadata): bool
    {
        $changed = $this->path !== $path
            || $this->manifestVersion !== $manifestVersion
            || $this->metadata !== $metadata
            || ExtensionStatus::Faulty !== $this->status;

        if (!$changed) {
            return false;
        }

        $this->path = $path;
        $this->manifestVersion = $manifestVersion;
        $this->metadata = $metadata;
        $this->status = ExtensionStatus::Faulty;
        $this->touch();

        return true;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRemoved(array $metadata): bool
    {
        $changed = ExtensionStatus::Removed !== $this->status || $this->metadata !== $metadata;

        if (!$changed) {
            return false;
        }

        $this->status = ExtensionStatus::Removed;
        $this->metadata = $metadata;
        $this->touch();

        return true;
    }

    public function activate(): bool
    {
        if (ExtensionStatus::Active === $this->status) {
            return false;
        }

        $this->status = ExtensionStatus::Active;
        $this->touch();

        return true;
    }

    public function deactivate(): bool
    {
        if (ExtensionStatus::Inactive === $this->status) {
            return false;
        }

        $this->status = ExtensionStatus::Inactive;
        $this->touch();

        return true;
    }

    /**
     * @param array<string, mixed> $failure
     */
    public function recordRuntimeFailure(array $failure): bool
    {
        $metadata = [
            ...$this->metadata,
            'runtime_failure' => $failure,
        ];

        if ($this->metadata === $metadata) {
            return false;
        }

        $this->metadata = $metadata;
        $this->touch();

        return true;
    }

    public function restoreStatus(ExtensionStatus $status): bool
    {
        if ($this->status === $status) {
            return false;
        }

        $this->status = $status;
        $this->touch();

        return true;
    }

    private function touch(): void
    {
        $this->modifiedAt = new DateTimeImmutable();
    }
}
