<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageMessageKey;
use App\Core\Package\PackageScope;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'extension_package')]
#[ORM\UniqueConstraint(name: 'uniq_extension_package_name', columns: ['package_name'])]
#[ORM\Index(name: 'idx_extension_package_status', columns: ['status'])]
class ExtensionPackage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'package_scopes', type: 'json')]
    private array $scopeValues;

    #[ORM\Column(name: 'package_name', length: 120)]
    private string $packageName;

    #[ORM\Column(length: 512)]
    private string $path;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $manifestVersion = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $installedVersion = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $availableVersion = null;

    #[ORM\Column(enumType: ExtensionPackageStatus::class)]
    private ExtensionPackageStatus $status;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    /**
     * @param list<PackageScope|string> $scopes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        array $scopes,
        string $packageName,
        string $path,
        ExtensionPackageStatus $status = ExtensionPackageStatus::Inactive,
        array $metadata = [],
        ?DateTimeImmutable $modifiedAt = null,
        ?string $manifestVersion = null,
        ?string $installedVersion = null,
        ?string $availableVersion = null,
    ) {
        $this->uid = Uid::assert($uid, 'Extension package UID');
        $this->scopeValues = self::normalizeScopes($scopes);
        $this->packageName = self::assertPackageName($packageName);
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
     * @return list<PackageScope>
     */
    public function scopes(): array
    {
        return array_map(
            static fn (string $scope): PackageScope => PackageScope::from($scope),
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

    public function hasScope(PackageScope $scope): bool
    {
        return in_array($scope->value, $this->scopeValues, true);
    }

    public function packageName(): string
    {
        return $this->packageName;
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

    public function status(): ExtensionPackageStatus
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
     * @param list<PackageScope|string> $scopes
     * @param array<string, mixed> $metadata
     */
    public function syncRegistryState(array $scopes, string $path, ?string $manifestVersion, array $metadata): bool
    {
        $scopeValues = self::normalizeScopes($scopes);
        $nextStatus = match ($this->status) {
            ExtensionPackageStatus::Removed, ExtensionPackageStatus::Faulty => ExtensionPackageStatus::Inactive,
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
            || ExtensionPackageStatus::Faulty !== $this->status;

        if (!$changed) {
            return false;
        }

        $this->path = $path;
        $this->manifestVersion = $manifestVersion;
        $this->metadata = $metadata;
        $this->status = ExtensionPackageStatus::Faulty;
        $this->touch();

        return true;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRemoved(array $metadata): bool
    {
        $changed = ExtensionPackageStatus::Removed !== $this->status || $this->metadata !== $metadata;

        if (!$changed) {
            return false;
        }

        $this->status = ExtensionPackageStatus::Removed;
        $this->metadata = $metadata;
        $this->touch();

        return true;
    }

    public function activate(): bool
    {
        if (ExtensionPackageStatus::Active === $this->status) {
            return false;
        }

        $this->status = ExtensionPackageStatus::Active;
        $this->touch();

        return true;
    }

    public function deactivate(): bool
    {
        if (ExtensionPackageStatus::Inactive === $this->status) {
            return false;
        }

        $this->status = ExtensionPackageStatus::Inactive;
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

    public function restoreStatus(ExtensionPackageStatus $status): bool
    {
        if ($this->status === $status) {
            return false;
        }

        $this->status = $status;
        $this->touch();

        return true;
    }

    private static function assertPackageName(string $packageName): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\/-]*$/', $packageName)) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_IDENTIFIER_INVALID, [
                '%identifier%' => $packageName,
            ]);
        }

        return $packageName;
    }

    /**
     * @param list<PackageScope|string> $scopes
     *
     * @return list<string>
     */
    private static function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $case = $scope instanceof PackageScope ? $scope : PackageScope::tryFrom($scope);
            if (null === $case) {
                throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCOPE_INVALID, [
                    '%scope%' => is_scalar($scope) ? (string) $scope : get_debug_type($scope),
                ]);
            }

            $normalized[$case->value] = $case->value;
        }

        if ([] === $normalized) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCOPE_INVALID, [
                '%scope%' => '',
            ]);
        }

        return array_values($normalized);
    }

    private function touch(): void
    {
        $this->modifiedAt = new DateTimeImmutable();
    }
}
