<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageAssetPathRewriter;
use PHPUnit\Framework\TestCase;

final class PackageAssetPathRewriterTest extends TestCase
{
    public function testItRewritesPackageCssAssetReferencesToThePublicMirror(): void
    {
        $rewriter = new PackageAssetPathRewriter();

        $css = $rewriter->rewriteCss(
            <<<'CSS'
.hero { background-image: url("./images/hero.webp"); }
@font-face { src: url('../fonts/display.woff2?v=1#font') format("woff2"); }
.external { background-image: url("https://example.com/image.webp"); }
.data { background-image: url("data:image/svg+xml,%3Csvg%3E"); }
CSS,
            'packages/demo/assets/frontend/app.css',
            'packages/demo/assets',
            'assets/packages/demo',
        );

        self::assertStringContainsString('url("../packages/demo/frontend/images/hero.webp")', $css);
        self::assertStringContainsString('url("../packages/demo/fonts/display.woff2?v=1#font")', $css);
        self::assertStringContainsString('url("https://example.com/image.webp")', $css);
        self::assertStringContainsString('url("data:image/svg+xml,%3Csvg%3E")', $css);
    }

    public function testItRewritesPackageJavaScriptImportsToThePublicMirror(): void
    {
        $rewriter = new PackageAssetPathRewriter();

        $javaScript = $rewriter->rewriteJavaScript(
            <<<'JS'
import helper from "./lib/helper.js";
export { widget } from "../shared/widget.js";
const lazy = () => import("./lib/lazy.js");
const shared = await import('../shared/chunk.mjs?v=1#lazy');
const external = () => import("alpinejs");
const variable = (path) => import(path);
import "alpinejs";
JS,
            'packages/demo/assets/frontend/app.js',
            'packages/demo/assets',
            'assets/packages/demo/app.js',
            'assets/packages/demo',
        );

        self::assertStringContainsString('import helper from "./frontend/lib/helper.js";', $javaScript);
        self::assertStringContainsString('export { widget } from "./shared/widget.js";', $javaScript);
        self::assertStringContainsString('const lazy = () => import("./frontend/lib/lazy.js");', $javaScript);
        self::assertStringContainsString("const shared = await import('./shared/chunk.mjs?v=1#lazy');", $javaScript);
        self::assertStringContainsString('const external = () => import("alpinejs");', $javaScript);
        self::assertStringContainsString('const variable = (path) => import(path);', $javaScript);
        self::assertStringContainsString('import "alpinejs";', $javaScript);
    }

    public function testItDoesNotRewriteVendoredCssOrJavaScript(): void
    {
        $rewriter = new PackageAssetPathRewriter();

        self::assertSame(
            '.icon { background-image: url("./sprite.svg"); }',
            $rewriter->rewriteCss(
                '.icon { background-image: url("./sprite.svg"); }',
                'packages/demo/assets/vendor/icons/icons.css',
                'packages/demo/assets',
                'assets/packages/demo',
            ),
        );

        self::assertSame(
            'import "./chunk.js"; const lazy = () => import("./lazy.js");',
            $rewriter->rewriteJavaScript(
                'import "./chunk.js"; const lazy = () => import("./lazy.js");',
                'packages/demo/assets/vendor/library/index.js',
                'packages/demo/assets',
                'assets/packages/demo/vendor/library/index.js',
                'assets/packages/demo',
            ),
        );
    }
}
