<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class TwigComponentNamespaceTest extends KernelTestCase
{
    public function testRootAnonymousComponentsRenderFromTwigNamespace(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        self::assertStringContainsString(
            'system-alert-stack',
            $twig->createTemplate('<twig:root:AlertStack :alerts="[]" />')->render(),
        );
        self::assertStringContainsString(
            'system-cookie-consent',
            $twig->createTemplate('<twig:root:CookieConsent />')->render(),
        );
    }
}
