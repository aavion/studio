<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Access\AccessLevel;
use App\Entity\UserAccount;
use App\Localization\TranslationLanguageCatalog;
use App\Security\MaintenanceModeSubscriber;
use App\Security\UserRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MaintenanceModeSubscriberTest extends TestCase
{
    public function testItDoesNothingWhenMaintenanceModeIsDisabled(): void
    {
        $subscriber = $this->subscriber(false);
        $event = $this->event('/about');

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->isMainRequest());
    }

    public function testItBlocksPublicRequestsDuringMaintenanceMode(): void
    {
        $subscriber = $this->subscriber(true);

        $this->expectException(ServiceUnavailableHttpException::class);

        $subscriber->onKernelRequest($this->event('/about'));
    }

    public function testItAllowsOperationalBypassPathsDuringMaintenanceMode(): void
    {
        $subscriber = $this->subscriber(true);

        foreach (['/admin', '/admin/content', '/user/login', '/de/user/login', '/cron/run', '/assets/app.css', '/build/app.js'] as $path) {
            $subscriber->onKernelRequest($this->event($path));
            self::addToAssertionCount(1);
        }
    }

    public function testItAllowsAccessLevelNineUsersDuringMaintenanceMode(): void
    {
        $subscriber = $this->subscriber(true, $this->userWithAccessLevel(AccessLevel::ADMIN));

        $subscriber->onKernelRequest($this->event('/about'));

        self::assertTrue(true);
    }

    public function testItBlocksAuthenticatedUsersBelowAccessLevelNineDuringMaintenanceMode(): void
    {
        $subscriber = $this->subscriber(true, $this->userWithAccessLevel(AccessLevel::MANAGER));

        $this->expectException(ServiceUnavailableHttpException::class);

        $subscriber->onKernelRequest($this->event('/about'));
    }

    private function subscriber(bool $maintenanceEnabled, ?UserAccount $user = null): MaintenanceModeSubscriber
    {
        return new MaintenanceModeSubscriber(
            $maintenanceEnabled,
            self::tokenStorage($user),
            new TranslationLanguageCatalog(dirname(__DIR__, 2)),
        );
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function userWithAccessLevel(int $accessLevel): UserAccount
    {
        $user = new UserAccount(
            '33333333-3333-7333-8333-333333333333',
            'securitytest',
            'security@example.test',
            'hash',
            role: UserRole::fromAccessLevel($accessLevel),
        );

        return $user;
    }

    private static function tokenStorage(?UserAccount $user): TokenStorageInterface
    {
        $tokenStorage = new TokenStorage();

        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
        }

        return $tokenStorage;
    }
}
