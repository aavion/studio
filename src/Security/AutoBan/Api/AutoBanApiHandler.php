<?php

declare(strict_types=1);

namespace App\Security\AutoBan\Api;

use App\Api\Admin\AdminFeatureApiGuard;
use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Access\AccessActor;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\AutoBan\ActiveAutoBan;
use App\Security\AutoBan\AutoBanAdminBrowser;
use App\Security\AutoBan\AutoBanResetService;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AutoBanApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AutoBanAdminBrowser $browser,
        private AutoBanResetService $resetService,
        private SecuritySignalRecorder $signals,
        private AccessRequestMetadata $requestMetadata,
        private AuditLoggerInterface $auditLogger,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AutoBanApiEndpointProvider::HANDLER_AUTO_BANS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.settings.security', $endpoint->operationId())) {
            return $denied;
        }

        $key = $this->keyFromPath($request->getPathInfo());
        if ($request->isMethod(Request::METHOD_POST)) {
            return null === $key ? $this->notFound($request, null) : $this->reset($request, $key, $endpoint);
        }

        if (null !== $key) {
            $detail = $this->browser->detail($key);

            return null === $detail
                ? $this->notFound($request, $key)
                : $this->responder->data($this->detailResource($detail));
        }

        $resources = array_map($this->banResource(...), $this->browser->activeList());

        return $this->responder->data($resources, meta: ['count' => count($resources)]);
    }

    private function reset(Request $request, string $key, ApiEndpointDefinition $endpoint): Response
    {
        if ($denied = $this->featureGuard->denyUnlessMutable($request, 'admin.settings.security', $endpoint->operationId())) {
            return $denied;
        }

        $ban = $this->resetService->releaseAndRecord($key, fn (ActiveAutoBan $released): bool => $this->recordResetSignal($request, $endpoint, $key, $released));
        if (!$ban instanceof ActiveAutoBan) {
            return $this->operationUnavailable($request, $endpoint->operationId(), [
                'active_ban_key' => $key,
                'reason' => 'active_ban_reset_failed_or_signal_not_recorded',
            ]);
        }

        $this->auditReset($request, $key, $ban->subjectType());

        return $this->responder->data(
            $this->banResource($ban->toArray()),
            meta: [
                'messages' => [
                    $this->responder->message(Message::create(
                        SecurityMessageCode::AUTO_BAN_RESET_RELEASED,
                        SecurityMessageKey::AUTO_BAN_RESET_RELEASED,
                        level: MessageLevel::Success,
                    ), $request),
                ],
            ],
        );
    }

    private function keyFromPath(string $path): ?string
    {
        if (1 !== preg_match('#^/api/v1/admin/security/auto-bans/([a-f0-9]{40})(?:/reset)?$#', $path, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<string, mixed> $ban
     *
     * @return array<string, mixed>
     */
    private function banResource(array $ban): array
    {
        $key = (string) ($ban['key'] ?? '');

        return [
            'type' => 'security_auto_ban',
            'id' => $key,
            'attributes' => $ban,
            'links' => [
                'self' => '/api/v1/admin/security/auto-bans/'.$key,
                'reset' => '/api/v1/admin/security/auto-bans/'.$key.'/reset',
                'admin' => '/admin/security/auto-bans/'.$key,
            ],
        ];
    }

    /**
     * @param array{ban: array<string, mixed>, trigger_geo: array{request_id: string, country: string, continent: string}, signals: list<array<string, mixed>>} $detail
     *
     * @return array<string, mixed>
     */
    private function detailResource(array $detail): array
    {
        $resource = $this->banResource($detail['ban']);
        $resource['attributes']['trigger_geo'] = $detail['trigger_geo'];
        $resource['relationships'] = [
            'signals' => array_map(static fn (array $signal): array => [
                'type' => 'security_signal',
                'id' => (string) ($signal['uid'] ?? ''),
                'attributes' => $signal,
            ], $detail['signals']),
        ];

        return $resource;
    }

    private function notFound(Request $request, ?string $key): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'active_ban_key' => $key,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function operationUnavailable(Request $request, string $operation, array $context): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_UNAVAILABLE, ApiMessageKey::API_OPERATION_UNAVAILABLE, [
                '%operation%' => $operation,
            ], $context),
            Response::HTTP_CONFLICT,
            $request,
        );
    }

    private function actor(Request $request): AccessActor
    {
        return ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
    }

    private function recordResetSignal(Request $request, ApiEndpointDefinition $endpoint, string $key, ActiveAutoBan $ban): bool
    {
        return $this->signals->record(
            'auto_ban',
            AutoBanScoreCatalogue::SIGNAL_RESET,
            $ban->subjectType(),
            $ban->subjectIdentifier(),
            ipDerived: 'ip_bucket' === $ban->subjectType(),
            severity: 'NOTICE',
            confidence: 100,
            requestFamily: 'api',
            requestIntent: 'settings_mutation',
            requestId: $this->requestMetadata->requestId($request),
            visitorId: 'n/a',
            path: $this->requestMetadata->sanitizedPath($request),
            route: 'api_v1_endpoint_dispatch',
            context: [
                'active_ban_key' => $key,
                'effective_subject_type' => 'ip_bucket' === $ban->subjectType() ? 'ip' : 'visitor',
                'reset_by' => $this->actor($request)->userUid(),
                'api_operation' => $endpoint->operationId(),
            ],
        );
    }

    private function auditReset(Request $request, string $key, string $subjectType): void
    {
        try {
            $this->auditLogger->log($this->actor($request), 'security.auto_ban.reset', [
                'active_ban_key' => $key,
                'subject_type' => $subjectType,
                'surface' => 'api',
            ]);
        } catch (Throwable) {
            return;
        }
    }
}
