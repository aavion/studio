<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Config\ConfigMessageKey;
use App\Core\Config\ConfigValueType;
use App\Core\Extension\ExtensionIdentity;
use App\Core\Validation\Identifier;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'extension_setting_entry')]
#[ORM\Index(name: 'idx_extension_setting_extension', columns: ['extension_name'])]
class ExtensionSettingEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'extension_name', length: 120)]
    private string $extensionName;

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
        string $extensionName,
        string $key,
        array|string|int|float|bool|null $value,
        ConfigValueType $valueType = ConfigValueType::Json,
        array $metadata = [],
        ?string $modifiedBy = null,
        ?DateTimeImmutable $modifiedAt = null,
    ) {
        $this->extensionName = self::assertExtensionName($extensionName);
        $this->key = Identifier::assertConfigKey($key, ConfigMessageKey::CONFIG_KEY_INVALID);
        $this->value = $value;
        $this->valueType = $valueType;
        $this->metadata = $metadata;
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }

    public function extensionName(): string
    {
        return $this->extensionName;
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

    private static function assertExtensionName(string $extensionName): string
    {
        return ExtensionIdentity::assertExtensionName($extensionName);
    }
}
