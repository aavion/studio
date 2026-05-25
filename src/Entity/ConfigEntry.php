<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Config\ConfigValueType;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'config_entry')]
class ConfigEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'config_key', length: 160)]
    private string $key;

    /**
     * @var array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    #[ORM\Column(type: 'json')]
    private array|string|int|float|bool|null $value;

    #[ORM\Column(enumType: ConfigValueType::class)]
    private ConfigValueType $valueType;

    #[ORM\Column]
    private bool $sensitive = false;

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $modifiedBy = null;

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     */
    public function __construct(
        string $key,
        array|string|int|float|bool|null $value,
        ConfigValueType $valueType = ConfigValueType::Json,
        bool $sensitive = false,
        ?string $modifiedBy = null,
        ?DateTimeImmutable $modifiedAt = null,
    ) {
        $this->key = Identifier::assertConfigKey($key, MessageKey::CONFIG_KEY_INVALID);
        $this->value = $value;
        $this->valueType = $valueType;
        $this->sensitive = $sensitive;
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
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

    public function sensitive(): bool
    {
        return $this->sensitive;
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $value
     */
    public function replaceValue(array|string|int|float|bool|null $value, ConfigValueType $valueType, ?string $modifiedBy = null): void
    {
        $this->value = $value;
        $this->valueType = $valueType;
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = new DateTimeImmutable();
    }
}
