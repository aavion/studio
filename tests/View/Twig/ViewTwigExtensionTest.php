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
        self::assertSame('System|macros/core/ui.html.twig|<p><strong>ok</strong></p>', $html);
    }
}
