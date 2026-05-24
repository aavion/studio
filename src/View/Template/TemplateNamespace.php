<?php

declare(strict_types=1);

namespace App\View\Template;

use App\Core\Package\PackageScope;
use InvalidArgumentException;

enum TemplateNamespace: string
{
    case Frontend = 'frontend';
    case Backend = 'backend';
    case Root = 'root';

    public static function fromName(string $name): self
    {
        $namespace = self::tryFrom(ltrim($name, '@'));

        if (null === $namespace) {
            throw new InvalidArgumentException(sprintf('Unsupported template namespace "%s".', $name));
        }

        return $namespace;
    }

    public function relativeDirectory(): string
    {
        return match ($this) {
            self::Frontend => 'templates/frontend',
            self::Backend => 'templates/backend',
            self::Root => 'templates',
        };
    }

    public function overrideScope(): PackageScope
    {
        return match ($this) {
            self::Frontend => PackageScope::FrontendTheme,
            self::Backend => PackageScope::BackendTheme,
            self::Root => PackageScope::SystemTemplate,
        };
    }

    public function packageRelativeDirectory(): string
    {
        return match ($this) {
            self::Frontend => 'templates/frontend',
            self::Backend => 'templates/backend',
            self::Root => 'templates',
        };
    }
}
