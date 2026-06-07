<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Log\LogFileBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminLogApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private LogFileBrowser $logs,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
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

        $view = $this->logs->browse($request->query->all());
        $entries = array_map(static fn (array $entry): array => [
            'type' => 'log_entry',
            'id' => (string) ($entry['id'] ?? ''),
            'attributes' => $entry,
        ], $view['entries']);

        unset($view['entries']);

        return $this->responder->data($entries, meta: $view);
    }
}
