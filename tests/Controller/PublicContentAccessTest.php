<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PublicContentAccessTest extends WebTestCase
{
    public function testItReturnsForbiddenForPrivateContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['visibility' => 'private'], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $connection->update('content_item', ['visibility' => 'public'], ['slug' => 'home']);
        }
    }

    public function testItReturnsNotFoundForUnpublishedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['status' => 'draft'], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection->update('content_item', ['status' => 'published'], ['slug' => 'home']);
        }
    }

    public function testItReturnsUnauthorizedForAclDeniedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['view_min_level' => AccessLevel::EDITOR], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(401);
            self::assertSelectorTextContains('h1', 'Sign in');
            self::assertSelectorTextContains('.studio-auth-notice', 'This content is only available after signing in with sufficient access.');
        } finally {
            $connection->update('content_item', ['view_min_level' => AccessLevel::PUBLIC], ['slug' => 'home']);
        }
    }

    public function testItDoesNotRenderStaticInjectionsOverDeniedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(StaticViewInjectionRegistryEvent::class, static function (StaticViewInjectionRegistryEvent $event): void {
            $event->addInjection(new StaticViewInjection(
                'test-denied-content-conflict',
                ViewSurface::Public,
                'news/first-update',
                'ui.empty_state.title',
                '@frontend/content/injections/static.html.twig',
            ));
        });
        $connection->update('content_item', ['view_min_level' => AccessLevel::EDITOR], ['slug' => 'first-update']);

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseStatusCodeSame(401);
            self::assertSelectorTextContains('h1', 'Sign in');
            self::assertSelectorNotExists('[data-injection="test-denied-content-conflict"]');
        } finally {
            $connection->update('content_item', ['view_min_level' => AccessLevel::PUBLIC], ['slug' => 'first-update']);
        }
    }

    public function testItDoesNotRenderStaticInjectionsOverPrivateContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(StaticViewInjectionRegistryEvent::class, static function (StaticViewInjectionRegistryEvent $event): void {
            $event->addInjection(new StaticViewInjection(
                'test-private-content-conflict',
                ViewSurface::Public,
                'news/first-update',
                'ui.empty_state.title',
                '@frontend/content/injections/static.html.twig',
            ));
        });
        $connection->update('content_item', ['visibility' => 'private'], ['slug' => 'first-update']);

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseStatusCodeSame(403);
            self::assertSelectorNotExists('[data-injection="test-private-content-conflict"]');
        } finally {
            $connection->update('content_item', ['visibility' => 'public'], ['slug' => 'first-update']);
        }
    }

    public function testItReturnsForbiddenForReservedContentPrefixes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/system/footer');

        self::assertResponseStatusCodeSame(403);
    }
}
