<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\ExtensionPackageType;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'extension_package')]
#[ORM\UniqueConstraint(name: 'uniq_extension_package_type_name', columns: ['package_type', 'package_name'])]
#[ORM\Index(name: 'idx_extension_package_status', columns: ['status'])]
class ExtensionPackage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(name: 'package_type', enumType: ExtensionPackageType::class)]
    private ExtensionPackageType $type;

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
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        ExtensionPackageType $type,
        string $packageName,
        string $path,
        ExtensionPackageStatus $status = ExtensionPackageStatus::Inactive,
        array $metadata = [],
        ?DateTimeImmutable $modifiedAt = null,
    ) {
        $this->uid = Uid::assert($uid, 'Extension package UID');
        $this->type = $type;
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

    public function type(): ExtensionPackageType
    {
        return $this->type;
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
}
