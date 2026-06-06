<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use Doctrine\ORM\Mapping as ORM;

trait ContentItemMetadataStateTrait
{
    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function replaceMetadata(array $metadata): void
    {
        $this->metadata = ContentItemInput::metadata($metadata);
    }

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    public function setMetadataValue(string $key, mixed $value): void
    {
        if ('' === trim($key)) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_METADATA_KEY_EMPTY);
        }

        ContentItemInput::metadataKey($key);

        $this->metadata[$key] = $value;
    }
}
