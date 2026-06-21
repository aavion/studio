<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAssetPathRewriter;
use PHPUnit\Framework\TestCase;

final class ExtensionAssetPathRewriterTest extends TestCase
{
    public function testItRewritesExtensionCssAssetReferencesToThePublicMirror(): void
    {
        $rewriter = new ExtensionAssetPathRewriter();

        $css = $rewriter->rewriteCss(
            <<<'CSS'
.hero { background-image: url("./images/hero.webp"); }
@font-face { src: url('../fonts/display.woff2?v=1#font') format("woff2"); }
.external { background-image: url("https://example.com/image.webp"); }
.data { background-image: url("data:image/svg+xml,%3Csvg%3E"); }
CSS,
            'extensions/demo/assets/frontend/app.css',
            'extensions/demo/assets',
            'assets/extensions/demo',
        );

        self::assertStringContainsString('url("../extensions/demo/frontend/images/hero.webp")', $css);
        self::assertStringContainsString('url("../extensions/demo/fonts/display.woff2?v=1#font")', $css);
        self::assertStringContainsString('url("https://example.com/image.webp")', $css);
        self::assertStringContainsString('url("data:image/svg+xml,%3Csvg%3E")', $css);
    }

    public function testItRewritesExtensionJavaScriptImportsToThePublicMirror(): void
    {
        $rewriter = new ExtensionAssetPathRewriter();

        $javaScript = $rewriter->rewriteJavaScript(
            <<<'JS'
import helper from "./lib/helper.js";
export { widget } from "../shared/widget.js";
const lazy = () => import("./lib/lazy.js");
const shared = await import('../shared/chunk.mjs?v=1#lazy');
const external = () => import("external-library");
const variable = (path) => import(path);
import "external-library";
JS,
            'extensions/demo/assets/frontend/app.js',
            'extensions/demo/assets',
            'assets/extensions/demo/app.js',
            'assets/extensions/demo',
        );

        self::assertStringContainsString('import helper from "./frontend/lib/helper.js";', $javaScript);
        self::assertStringContainsString('export { widget } from "./shared/widget.js";', $javaScript);
        self::assertStringContainsString('const lazy = () => import("./frontend/lib/lazy.js");', $javaScript);
        self::assertStringContainsString("const shared = await import('./shared/chunk.mjs?v=1#lazy');", $javaScript);
        self::assertStringContainsString('const external = () => import("external-library");', $javaScript);
        self::assertStringContainsString('const variable = (path) => import(path);', $javaScript);
        self::assertStringContainsString('import "external-library";', $javaScript);
    }

    public function testItDoesNotRewriteVendoredCssOrJavaScript(): void
    {
        $rewriter = new ExtensionAssetPathRewriter();

        self::assertSame(
            '.icon { background-image: url("./sprite.svg"); }',
            $rewriter->rewriteCss(
                '.icon { background-image: url("./sprite.svg"); }',
                'extensions/demo/assets/vendor/icons/icons.css',
                'extensions/demo/assets',
                'assets/extensions/demo',
            ),
        );

        self::assertSame(
            'import "./chunk.js"; const lazy = () => import("./lazy.js");',
            $rewriter->rewriteJavaScript(
                'import "./chunk.js"; const lazy = () => import("./lazy.js");',
                'extensions/demo/assets/vendor/library/index.js',
                'extensions/demo/assets',
                'assets/extensions/demo/vendor/library/index.js',
                'assets/extensions/demo',
            ),
        );
    }
}
