<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\ThemeMacroRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeMacroRegistryTest extends TestCase
{
    public function testItExposesNamespacedCoreMacroTemplates(): void
    {
        $registry = new ThemeMacroRegistry();

        self::assertSame('macros/core/ui.html.twig', $registry->template('core', 'ui'));
        self::assertSame('macros/core/form.html.twig', $registry->template('core', 'form'));
        self::assertNull($registry->template('theme', 'missing'));
    }
}
