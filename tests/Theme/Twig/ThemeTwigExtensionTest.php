<?php

declare(strict_types=1);

namespace App\Tests\Theme\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ThemeTwigExtensionTest extends KernelTestCase
{
    public function testItExposesThemeGlobalsFunctionsAndMarkdownFilter(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $globals = $twig->getGlobals();
        $html = $twig->createTemplate(
            '{{ studio_theme_context().system_theme.name }}|{{ studio_macro_template("core", "ui") }}|{{ "**ok**"|studio_markdown }}',
        )->render();

        self::assertArrayHasKey('studio_theme', $globals);
        self::assertSame('System|macros/core/ui.html.twig|<p><strong>ok</strong></p>', $html);
    }
}
