<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
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
    ) {
        $this->uid = Uid::assert($uid, 'Extension package UID');
        $this->scopeValues = self::normalizeScopes($scopes);
        $this->packageName = self::assertPackageName($packageName);
        $this->path = $path;
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

    public function status(): ExtensionPackageStatus
    {
        return $this->status;
    }

    private static function assertPackageName(string $packageName): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\/-]*$/', $packageName)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::PACKAGE_IDENTIFIER_INVALID, [
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
                throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::PACKAGE_SCOPE_INVALID, [
                    '%scope%' => is_scalar($scope) ? (string) $scope : get_debug_type($scope),
                ]);
            }

            $normalized[$case->value] = $case->value;
        }

        if ([] === $normalized) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::PACKAGE_SCOPE_INVALID, [
                '%scope%' => '',
            ]);
        }

        return array_values($normalized);
    }
}
