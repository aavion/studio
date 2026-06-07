<?php

declare(strict_types=1);

namespace App\Tests\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiMaintenanceModeSubscriber;
use App\Api\Security\ApiUnavailableResponder;
use App\Core\Access\AccessLevel;
use App\Core\Output\JsonOutputRenderer;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Security\ApiKeyStatus;
use App\Security\UserRole;
use App\Tests\Support\IdentityTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiMaintenanceModeSubscriberTest extends TestCase
{
    public function testItBlocksPublicApiRequestsDuringMaintenance(): void
    {
        $event = $this->event('/api/v1/status');

        $this->subscriber(true)->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
        self::assertSame('60', $event->getResponse()->headers->get('Retry-After'));
        $payload = json_decode((string) $event->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api.unavailable_maintenance', $payload['error']['code']);
        self::assertSame('maintenance', $payload['error']['context']['reason']);
    }

    public function testItAllowsAdminApiContextDuringMaintenance(): void
    {
        $event = $this->event('/api/v1/status');
        ApiRequestContext::fromApiKey($this->apiKeyForAccessLevel(AccessLevel::ADMIN))->attachTo($event->getRequest());

        $this->subscriber(true)->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItBlocksNonAdminApiContextDuringMaintenance(): void
    {
        $event = $this->event('/api/v1/status');
        ApiRequestContext::fromApiKey($this->apiKeyForAccessLevel(AccessLevel::MANAGER))->attachTo($event->getRequest());

        $this->subscriber(true)->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
    }

    public function testItIgnoresRequestsWhenMaintenanceIsDisabled(): void
    {
        $event = $this->event('/api/v1/status');

        $this->subscriber(false)->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresNonApiRequests(): void
    {
        $event = $this->event('/about');

        $this->subscriber(true)->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriber(bool $maintenanceEnabled): ApiMaintenanceModeSubscriber
    {
        return new ApiMaintenanceModeSubscriber(
            $maintenanceEnabled,
            new ApiUnavailableResponder(new ApiResponder(new JsonOutputRenderer(), new IdentityTranslator())),
        );
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->kernel(),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function apiKeyForAccessLevel(int $accessLevel): ApiKey
    {
        return new ApiKey(
            '65000000-0000-7000-8000-'.substr(md5((string) $accessLevel), 0, 12),
            'maint'.(string) $accessLevel,
            str_repeat('a', 64),
            'encrypted',
            new UserAccount(
                '66000000-0000-7000-8000-'.substr(md5('user'.(string) $accessLevel), 0, 12),
                'apiuser'.(string) $accessLevel,
                'apiuser'.(string) $accessLevel.'@example.test',
                'hash',
                role: UserRole::fromAccessLevel($accessLevel),
            ),
            ApiKeyStatus::ReadWrite,
        );
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
