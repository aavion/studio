<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/demo/frontend', name: 'demo_frontend', methods: ['GET'])]
    public function frontend(): Response
    {
        return $this->render('@frontend/demo/shell.html.twig');
    }

    #[Route('/demo/backend', name: 'demo_backend', methods: ['GET'])]
    public function backend(): Response
    {
        return $this->render('@backend/demo/shell.html.twig');
    }
}
