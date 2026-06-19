<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\ExtensionMacroRegistry;
use PHPUnit\Framework\TestCase;

final class ExtensionMacroRegistryTest extends TestCase
{
    public function testItExposesNamespacedCoreMacroTemplates(): void
    {
        $registry = new ExtensionMacroRegistry();

        self::assertSame('@root/macros/core/ui.html.twig', $registry->template('core', 'ui'));
        self::assertSame('@root/macros/core/form.html.twig', $registry->template('core', 'form'));
        self::assertNull($registry->template('extension', 'missing'));
    }
}
