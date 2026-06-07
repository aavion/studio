<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\Http\ApiResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ApiRootController extends AbstractController
{
    public function __construct(private readonly ApiResponder $responder)
    {
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
