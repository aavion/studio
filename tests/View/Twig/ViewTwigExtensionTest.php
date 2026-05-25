<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ViewTwigExtensionTest extends KernelTestCase
{
    public function testItExposesViewGlobalsFunctionsAndMarkdownFilter(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $globals = $twig->getGlobals();
        $html = $twig->createTemplate(
            '{{ studio_view_context().system_package.name }}|{{ studio_macro_template("core", "ui") }}|{{ "**ok**"|studio_markdown }}',
        )->render();

        self::assertArrayHasKey('studio_view', $globals);
        self::assertSame('System|@root/macros/core/ui.html.twig|<p><strong>ok</strong></p>', $html);
    }

    public function testItRendersNativeProviderNamespaceFallbacks(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '{% include "@frontend/partials/forms/fields/captcha.html.twig" %}|{% include "@backend/editor/fields/richtext.html.twig" with {name: "body", value: "Hello"} only %}',
        )->render();

        self::assertStringContainsString('|', $html);
        self::assertStringContainsString('name="body"', $html);
        self::assertStringContainsString('data-controller="code-editor"', $html);
        self::assertStringContainsString('data-code-editor-language-value="markdown"', $html);
        self::assertStringContainsString('Hello', $html);
    }

    public function testItRendersCodemirrorSyntaxProviderAliases(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '{% include "@provider/editor/json.html.twig" with {name: "payload", value: "{}", attributes: {id: "json-editor"}} only %}',
        )->render();

        self::assertStringContainsString('name="payload"', $html);
        self::assertStringContainsString('id="json-editor"', $html);
        self::assertStringContainsString('data-code-editor-language-value="json"', $html);
        self::assertStringContainsString('data-code-editor-tab-size-value="2"', $html);
    }
}
