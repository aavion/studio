<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\PackageMacroRegistry;
use PHPUnit\Framework\TestCase;

final class PackageMacroRegistryTest extends TestCase
{
    public function testItExposesNamespacedCoreMacroTemplates(): void
    {
        $registry = new PackageMacroRegistry();

        self::assertSame('@root/macros/core/ui.html.twig', $registry->template('core', 'ui'));
        self::assertSame('@root/macros/core/form.html.twig', $registry->template('core', 'form'));
        self::assertNull($registry->template('package', 'missing'));
    }
}
