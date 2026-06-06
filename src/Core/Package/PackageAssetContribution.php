<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageException;

final readonly class PackageAssetContribution
{
    public const TYPE_CSS = 'css';
    public const TYPE_JAVASCRIPT = 'javascript';
    public const TYPE_STATIC_ASSET = 'static_asset';
    public const TYPE_TAILWIND_SOURCE = 'tailwind_source';

    public function __construct(
        private string $package,
        private PackageScope $scope,
        private string $type,
        private string $path,
    ) {
        if ('' === trim($package)) {
            throw MessageException::forMessage(
                PackageMessageCode::PACKAGE_ASSET_CONTRIBUTION_PACKAGE_INVALID,
                PackageMessageKey::PACKAGE_ASSET_CONTRIBUTION_PACKAGE_INVALID,
                ['%package%' => $package],
                ['package' => $package],
            );
        }

        if (!in_array($type, [self::TYPE_CSS, self::TYPE_JAVASCRIPT, self::TYPE_STATIC_ASSET, self::TYPE_TAILWIND_SOURCE], true)) {
            throw MessageException::forMessage(
                PackageMessageCode::PACKAGE_ASSET_CONTRIBUTION_TYPE_INVALID,
                PackageMessageKey::PACKAGE_ASSET_CONTRIBUTION_TYPE_INVALID,
                ['%type%' => $type],
                ['package' => $package, 'type' => $type],
            );
        }

        self::assertSafePath($path);
    }

    public static function css(string $package, PackageScope $scope, string $path): self
    {
        return new self($package, $scope, self::TYPE_CSS, $path);
    }

    public static function javaScript(string $package, PackageScope $scope, string $path): self
    {
        return new self($package, $scope, self::TYPE_JAVASCRIPT, $path);
    }

    public static function tailwindSource(string $package, PackageScope $scope, string $path): self
    {
        return new self($package, $scope, self::TYPE_TAILWIND_SOURCE, $path);
    }

    public static function staticAsset(string $package, PackageScope $scope, string $path): self
    {
        return new self($package, $scope, self::TYPE_STATIC_ASSET, $path);
    }

    public function package(): string
    {
        return $this->package;
    }

    public function scope(): PackageScope
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
                PackageMessageCode::PACKAGE_ASSET_CONTRIBUTION_PATH_INVALID,
                PackageMessageKey::PACKAGE_ASSET_CONTRIBUTION_PATH_INVALID,
                ['%path%' => $path],
                ['path' => $path],
            );
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('..' === $segment) {
                throw MessageException::forMessage(
                    PackageMessageCode::PACKAGE_ASSET_CONTRIBUTION_PATH_TRAVERSAL,
                    PackageMessageKey::PACKAGE_ASSET_CONTRIBUTION_PATH_TRAVERSAL,
                    ['%path%' => $path],
                    ['path' => $path],
                );
            }
        }
    }
}
