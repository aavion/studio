<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Entity\UserAccount;
use App\Security\UserRole;
use App\View\Alert\UiAlertTopicFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class UiAlertTopicFactoryTest extends TestCase
{
    public function testItBuildsStableHashedUserAndSessionTopics(): void
    {
        $factory = new UiAlertTopicFactory('https://example.test', 'secret');
        $user = new UserAccount(
            '71000000-0000-7000-8000-000000000001',
            'admin',
            'admin@example.test',
            'hash',
            role: UserRole::Admin,
        );

        $userTopic = $factory->userTopic($user);
        $sessionTopic = $factory->sessionTopic('session-id');

        self::assertStringStartsWith('https://example.test/ui-alerts/user/', $userTopic);
        self::assertStringStartsWith('https://example.test/ui-alerts/session/', $sessionTopic);
        self::assertStringNotContainsString($user->uid(), $userTopic);
        self::assertStringNotContainsString('session-id', $sessionTopic);
        self::assertSame($sessionTopic, $factory->sessionTopic('session-id'));
    }

    public function testItUsesExistingSessionCookieForRequestTopicsWithoutStartingSession(): void
    {
        $factory = new UiAlertTopicFactory('https://example.test', 'secret');
        $request = Request::create('/api/live/alerts');
        $session = new Session(new MockArraySessionStorage());
        $session->setName('PHPSESSID');
        $request->setSession($session);
        $request->cookies->set('PHPSESSID', 'existing-session-id');

        self::assertSame([
            $factory->sessionTopic('existing-session-id'),
        ], $factory->topicsFor($request, null));
        self::assertFalse($session->isStarted());
    }
}
