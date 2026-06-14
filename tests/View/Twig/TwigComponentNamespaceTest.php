<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\View\Alert\MercureAvailability;
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

    public function testAlertStackUsesPollingOnlyWhenNoMercureStreamIsRendered(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $twig = $container->get(Environment::class);
        $config = $container->get(Config::class);
        $enabled = $config->get(MercureAvailability::ENABLED_KEY, true);
        $available = $config->get(MercureAvailability::AVAILABLE_KEY, false);

        try {
            $config->set(MercureAvailability::ENABLED_KEY, true, ConfigValueType::Boolean);
            $config->set(MercureAvailability::AVAILABLE_KEY, false, ConfigValueType::Boolean);
            $fallback = $this->renderAlertStack($twig);

            self::assertStringContainsString('ui-alert-poll', $fallback);
            self::assertStringContainsString('data-ui-alert-poll-url-value', $fallback);
            self::assertStringNotContainsString('data-ui-alert-stream-url-value', $fallback);

            $config->set(MercureAvailability::AVAILABLE_KEY, true, ConfigValueType::Boolean);
            $stream = $this->renderAlertStack($twig);

            self::assertStringContainsString('ui-alert-stream', $stream);
            self::assertStringContainsString('data-ui-alert-stream-url-value', $stream);
            self::assertStringContainsString('data-ui-alert-stream-catch-up-url-value', $stream);
            self::assertStringNotContainsString('ui-alert-poll', $stream);
            self::assertStringNotContainsString('data-ui-alert-poll-url-value', $stream);
        } finally {
            $config->set(MercureAvailability::ENABLED_KEY, $enabled, ConfigValueType::Boolean);
            $config->set(MercureAvailability::AVAILABLE_KEY, $available, ConfigValueType::Boolean);
        }
    }

    private function renderAlertStack(Environment $twig): string
    {
        return $twig
            ->createTemplate('<twig:root:AlertStack :alerts="[]" :stream_topics="[\'https://example.test/ui-alerts/user/test\']" />')
            ->render();
    }
}
