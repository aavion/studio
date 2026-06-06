<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\ContentMessageKey;
use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Read\PublishedContentView;
use App\Content\Routing\ContentSystemRoute;
use App\Core\Validation\Identifier;

final readonly class DynamicViewInjectionFilter
{
    /**
     * @param list<string> $schemaIdentifiers
     * @param list<ContentStatus> $statuses
     * @param list<ContentVisibility> $visibilities
     */
    public function __construct(
        private bool $realContentOnly = true,
        private array $schemaIdentifiers = [],
        private array $statuses = [],
        private array $visibilities = [],
    ) {
        foreach ($schemaIdentifiers as $identifier) {
            Identifier::assertSnakeCase($identifier, ContentMessageKey::CONTENT_SCHEMA_IDENTIFIER_INVALID, '%identifier%');
        }
    }

    public static function any(): self
    {
        return new self(realContentOnly: false);
    }

    /**
     * @param list<string> $schemaIdentifiers
     */
    public static function realContent(array $schemaIdentifiers = []): self
    {
        return new self(realContentOnly: true, schemaIdentifiers: $schemaIdentifiers);
    }

    public function matches(PublishedContentView $view): bool
    {
        $content = $view->content();

        if ($this->realContentOnly && ContentSystemRoute::VIRTUAL_PARENT_UID === $content->parentUid()) {
            return false;
        }

        if ([] !== $this->schemaIdentifiers && !in_array($content->schema()?->identifier(), $this->schemaIdentifiers, true)) {
            return false;
        }

        if ([] !== $this->statuses && !in_array($content->status(), $this->statuses, true)) {
            return false;
        }

        if ([] !== $this->visibilities && !in_array($content->visibility(), $this->visibilities, true)) {
            return false;
        }

        return true;
    }

    public function realContentOnly(): bool
    {
        return $this->realContentOnly;
    }

    /**
     * @return list<string>
     */
    public function schemaIdentifiers(): array
    {
        return $this->schemaIdentifiers;
    }
}
