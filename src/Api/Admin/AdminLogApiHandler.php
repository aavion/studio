<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiListQueryNormalizer;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Log\AdminLogBrowser;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminLogApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminLogBrowser $logs,
        private ApiListQueryNormalizer $listQueries,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_LOGS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.logs', 'listAdminLogs')) {
            return $denied;
        }

        $source = $this->sourceFromPath($request->getPathInfo());
        if (null === $source) {
            $view = $this->logs->browse([]);
            $sources = $this->featureGuard->isMutable($request, 'admin.logs')
                ? $view['sources']
                : $this->visibleSources($view['sources']);

            return $this->responder->data($this->sourceResources($sources), meta: [
                'count' => count($sources),
                'default_source' => $view['selected_source'],
            ]);
        }

        if (!$this->isKnownSource($source)) {
            return $this->notFound($request, $source);
        }
        if ($this->isSensitiveSource($source)) {
            $denied = $this->featureGuard->denyUnlessMutable($request, 'admin.logs', 'readAdminLogSource');
            if (null !== $denied) {
                return $denied;
            }
        }

        $view = $this->logs->browse([
            ...$this->listQueries->backendQuery($request->query->all()),
            'source' => $source,
        ]);
        $entries = array_map(static fn (array $entry): array => [
            'type' => 'log_entry',
            'id' => (string) ($entry['id'] ?? ''),
            'attributes' => $entry,
        ], $view['entries']);

        unset($view['entries']);

        return $this->responder->data($entries, meta: $this->listQueries->apiMeta($view));
    }

    /**
     * @param list<array{key: string, label: string}> $sources
     *
     * @return list<array<string, mixed>>
     */
    private function sourceResources(array $sources): array
    {
        return array_map(fn (array $source): array => [
            'type' => 'log_source',
            'id' => $source['key'],
            'attributes' => [
                'source' => $source['key'],
                'label_key' => $source['label'],
                'path' => '/api/v1/admin/logs/'.$source['key'],
                'filters' => $this->filtersForSource((string) $source['key']),
            ],
        ], $sources);
    }

    /**
     * @return list<string>
     */
    private function filtersForSource(string $source): array
    {
        return match ($source) {
            'application' => ['level', 'q', 'match', 'time_window', 'limit', 'page'],
            'message' => ['level', 'q', 'match', 'time_window', 'limit', 'page'],
            'audit' => ['q', 'match', 'time_window', 'audit_action', 'limit', 'page'],
            'security_signal' => ['level', 'q', 'match', 'time_window', 'audit_action', 'limit', 'page'],
            default => ['q', 'match', 'time_window', 'limit', 'page'],
        };
    }

    /**
     * @param list<array{key: string, label: string}> $sources
     *
     * @return list<array{key: string, label: string}>
     */
    private function visibleSources(array $sources): array
    {
        return array_values(array_filter(
            $sources,
            fn (array $source): bool => !$this->isSensitiveSource((string) ($source['key'] ?? '')),
        ));
    }

    private function isSensitiveSource(string $source): bool
    {
        return in_array($source, ['audit', 'security_signal'], true);
    }

    private function sourceFromPath(string $path): ?string
    {
        $prefix = '/api/v1/admin/logs/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $source = rawurldecode(substr($path, strlen($prefix)));

        return '' === $source ? null : $source;
    }

    private function isKnownSource(string $source): bool
    {
        $view = $this->logs->browse([]);

        foreach ($view['sources'] as $candidate) {
            if (($candidate['key'] ?? null) === $source) {
                return true;
            }
        }

        return false;
    }

    private function notFound(Request $request, string $source): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'source' => $source,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }
}
