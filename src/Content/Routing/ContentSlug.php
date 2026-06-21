<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Content\ContentMessageKey;
use App\Core\Validation\IdentifierSpec;
use App\Core\Message\MessageException;
use Stringable;

final readonly class ContentSlug implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $slug): self
    {
        if (!self::isValid($slug)) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_SLUG_INVALID, [
                '%slug%' => $slug,
            ]);
        }

        return new self($slug);
    }

    public static function isValid(string $slug): bool
    {
        return IdentifierSpec::isContentSlug($slug);
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
