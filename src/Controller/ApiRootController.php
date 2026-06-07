<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Endpoint\ApiEndpointNavigationBuilder;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiRootController extends AbstractController
{
    public function __construct(
        private readonly ApiEndpointNavigationBuilder $navigation,
        private readonly ApiResponder $responder,
    ) {
    }

    #[Route('/api/v1', name: 'api_v1_root', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = ApiRequestContext::fromRequest($request);

        return $this->responder->data($this->navigation->navigation('/api/v1', includePrivate: $context?->isAuthenticated() ?? false));
    }

    #[Route('/api/v1/status', name: 'api_v1_status', methods: ['GET'])]
    public function status(): Response
    {
        return $this->responder->data([
            'type' => 'api_status',
            'id' => 'status',
            'attributes' => [
                'version' => 'v1',
                'status' => 'ok',
            ],
        ]);
    }
}
