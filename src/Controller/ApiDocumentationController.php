<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Documentation\OpenApiDocumentFactory;
use App\Core\Output\JsonOutputRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiDocumentationController
{
    public function __construct(
        private readonly OpenApiDocumentFactory $documentFactory,
        private readonly JsonOutputRenderer $json,
    ) {
    }

    #[Route('/api/v1/openapi.json', name: 'api_v1_openapi', methods: ['GET'])]
    public function document(): Response
    {
        return $this->json->render($this->documentFactory->create());
    }
}
