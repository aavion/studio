<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'package_setting_entry')]
#[ORM\Index(name: 'idx_package_setting_package', columns: ['package_name'])]
class PackageSettingEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'package_name', length: 120)]
    private string $packageName;

    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', length: 160)]
    private string $key;

    /**
     * @var array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    #[ORM\Column(type: 'json')]
    private array|string|int|float|bool|null $value;

    #[ORM\Column(enumType: ConfigValueType::class)]
    private ConfigValueType $valueType;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $modifiedBy = null;

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $packageName,
        string $key,
        array|string|int|float|bool|null $value,
        ConfigValueType $valueType = ConfigValueType::Json,
        array $metadata = [],
        ?string $modifiedBy = null,
        ?DateTimeImmutable $modifiedAt = null,
    ) {
        $this->packageName = self::assertPackageName($packageName);
        $this->key = Identifier::assertConfigKey($key, MessageKey::CONFIG_KEY_INVALID);
        $this->value = $value;
        $this->valueType = $valueType;
        $this->metadata = $metadata;
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    public function value(): array|string|int|float|bool|null
    {
        return $this->value;
    }

    public function valueType(): ConfigValueType
    {
        return $this->valueType;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    private static function assertPackageName(string $packageName): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\/-]*$/', $packageName)) {
            throw MessageException::invalidArgument(MessageKey::PACKAGE_IDENTIFIER_INVALID, [
                '%identifier%' => $packageName,
            ]);
        }

        return $packageName;
    }
}
