<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Entity\UserAccount;
use App\Security\UserRole;
use App\View\Alert\UiAlertTopicFactory;
use App\View\Alert\UiAlertUserIdentityResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;

final class UiAlertTopicFactoryTest extends TestCase
{
    public function testItBuildsStableHashedUserAndSessionTopics(): void
    {
        $factory = new UiAlertTopicFactory('secret');
        $user = new UserAccount(
            '71000000-0000-7000-8000-000000000001',
            'admin',
            'admin@example.test',
            'hash',
            role: UserRole::Admin,
        );

        $userTopic = $factory->userTopic($user);
        $sessionTopic = $factory->sessionTopic('session-id');

        self::assertStringStartsWith('urn:system:ui-alerts:user:', $userTopic);
        self::assertStringStartsWith('urn:system:ui-alerts:session:', $sessionTopic);
        self::assertStringNotContainsString($user->uid(), $userTopic);
        self::assertStringNotContainsString('session-id', $sessionTopic);
        self::assertSame($sessionTopic, $factory->sessionTopic('session-id'));
        self::assertSame($userTopic, $factory->userTopic($user->uid()));
        self::assertTrue($factory->isUiAlertTopic($userTopic));
        self::assertTrue($factory->isUiAlertTopic($sessionTopic));
    }

    public function testItResolvesUsernameStringsToAccountUidTopics(): void
    {
        $factory = new UiAlertTopicFactory('secret', new FakeUserAlertIdentityResolver([
            'AdminUser' => '71000000-0000-7000-8000-000000000001',
        ]));

        self::assertSame($factory->userTopic('71000000-0000-7000-8000-000000000001'), $factory->userTopic('AdminUser'));
    }

    public function testItRejectsUnresolvedUsernameTopics(): void
    {
        $factory = new UiAlertTopicFactory('secret');

        $this->expectException(InvalidArgumentException::class);
        $factory->userTopic('admin');
    }

    public function testItAcceptsGenericUserIdentifiersOnlyWhenTheyAreAccountUids(): void
    {
        $factory = new UiAlertTopicFactory('secret');
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return '71000000-0000-7000-8000-000000000001';
            }
        };

        self::assertSame($factory->userTopic('71000000-0000-7000-8000-000000000001'), $factory->userTopic($user));
    }

    public function testItResolvesGenericUserIdentifierUsernamesWithoutChangingCase(): void
    {
        $factory = new UiAlertTopicFactory('secret', new FakeUserAlertIdentityResolver([
            'AdminUser' => '71000000-0000-7000-8000-000000000001',
        ]));
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'AdminUser';
            }
        };

        self::assertSame($factory->userTopic('71000000-0000-7000-8000-000000000001'), $factory->userTopic($user));
    }

    public function testItRejectsNonUiAlertTopics(): void
    {
        $factory = new UiAlertTopicFactory('secret');

        self::assertFalse($factory->isUiAlertTopic('https://example.test/ui-alerts/user/topic'));
        self::assertFalse($factory->isUiAlertTopic('urn:system:ui-alerts:health'));
        self::assertFalse($factory->isUiAlertTopic('urn:system:ui-alerts:user:not-a-hash'));
        self::assertFalse($factory->isUiAlertTopic('urn:other:ui-alerts:user:'.str_repeat('a', 64)));
    }

    public function testItUsesExistingSessionCookieForRequestTopicsWithoutStartingSession(): void
    {
        $factory = new UiAlertTopicFactory('secret');
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

final readonly class FakeUserAlertIdentityResolver implements UiAlertUserIdentityResolverInterface
{
    /**
     * @param array<string, string> $uidsByUsername
     */
    public function __construct(private array $uidsByUsername)
    {
    }

    public function resolveUid(string $identifier): ?string
    {
        return $this->uidsByUsername[$identifier] ?? null;
    }
}
