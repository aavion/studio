<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use Stringable;

final readonly class ContentSlug implements Stringable
{
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $slug): self
    {
        if (!self::isValid($slug)) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_SLUG_INVALID, [
                '%slug%' => $slug,
            ]);
        }

        return new self($slug);
    }

    public static function isValid(string $slug): bool
    {
        return 1 === preg_match(self::PATTERN, $slug);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
