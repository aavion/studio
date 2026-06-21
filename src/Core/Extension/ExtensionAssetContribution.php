<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\MessageException;

final readonly class ExtensionAssetContribution
{
    public const TYPE_CSS = 'css';
    public const TYPE_JAVASCRIPT = 'javascript';
    public const TYPE_STATIC_ASSET = 'static_asset';
    public const TYPE_TAILWIND_SOURCE = 'tailwind_source';

    public function __construct(
        private string $extension,
        private ExtensionScope $scope,
        private string $type,
        private string $path,
    ) {
        if ('' === trim($extension)) {
            throw MessageException::forMessage(
                ExtensionMessageCode::EXTENSION_ASSET_CONTRIBUTION_EXTENSION_INVALID,
                ExtensionMessageKey::EXTENSION_ASSET_CONTRIBUTION_EXTENSION_INVALID,
                ['%extension%' => $extension],
                ['extension' => $extension],
            );
        }

        if (!in_array($type, [self::TYPE_CSS, self::TYPE_JAVASCRIPT, self::TYPE_STATIC_ASSET, self::TYPE_TAILWIND_SOURCE], true)) {
            throw MessageException::forMessage(
                ExtensionMessageCode::EXTENSION_ASSET_CONTRIBUTION_TYPE_INVALID,
                ExtensionMessageKey::EXTENSION_ASSET_CONTRIBUTION_TYPE_INVALID,
                ['%type%' => $type],
                ['extension' => $extension, 'type' => $type],
            );
        }

        self::assertSafePath($path);
    }

    public static function css(string $extension, ExtensionScope $scope, string $path): self
    {
        return new self($extension, $scope, self::TYPE_CSS, $path);
    }

    public static function javaScript(string $extension, ExtensionScope $scope, string $path): self
    {
        return new self($extension, $scope, self::TYPE_JAVASCRIPT, $path);
    }

    public static function tailwindSource(string $extension, ExtensionScope $scope, string $path): self
    {
        return new self($extension, $scope, self::TYPE_TAILWIND_SOURCE, $path);
    }

    public static function staticAsset(string $extension, ExtensionScope $scope, string $path): self
    {
        return new self($extension, $scope, self::TYPE_STATIC_ASSET, $path);
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function scope(): ExtensionScope
    {
        return $this->scope;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function path(): string
    {
        return $this->path;
    }

    private static function assertSafePath(string $path): void
    {
        if ('' === trim($path) || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw MessageException::forMessage(
                ExtensionMessageCode::EXTENSION_ASSET_CONTRIBUTION_PATH_INVALID,
                ExtensionMessageKey::EXTENSION_ASSET_CONTRIBUTION_PATH_INVALID,
                ['%path%' => $path],
                ['path' => $path],
            );
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('..' === $segment) {
                throw MessageException::forMessage(
                    ExtensionMessageCode::EXTENSION_ASSET_CONTRIBUTION_PATH_TRAVERSAL,
                    ExtensionMessageKey::EXTENSION_ASSET_CONTRIBUTION_PATH_TRAVERSAL,
                    ['%path%' => $path],
                    ['path' => $path],
                );
            }
        }
    }
}
