<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Setup\SetupCompletionMarker;
use Symfony\Component\HttpFoundation\Response;

final readonly class BackendRouteResolver
{
    public function __construct(
        private SetupCompletionMarker $setupCompletionMarker,
        private BackendViewRegistry $viewRegistry,
        private string $projectDir,
        private string $environment,
    ) {
    }

    public function resolve(BackendArea $area, string $path = ''): BackendRouteResult
    {
        $path = trim($path, '/');

        if (BackendArea::Setup === $area && $this->setupCompletionMarker->isComplete($this->projectDir, $this->environment)) {
            return BackendRouteResult::withMessage(
                $area,
                Response::HTTP_NOT_FOUND,
                Message::warning(
                    MessageCode::BACKEND_SETUP_LOCKED,
                    MessageKey::BACKEND_SETUP_LOCKED,
                    context: ['area' => $area->value],
                ),
            );
        }

        if (BackendArea::Setup === $area && '' === $path) {
            return BackendRouteResult::index($area);
        }

        $view = $this->viewRegistry->find($area, $path);

        if ($view instanceof BackendViewDefinition) {
            return BackendRouteResult::fromView($view);
        }

        return BackendRouteResult::withMessage(
            $area,
            Response::HTTP_NOT_FOUND,
            Message::warning(
                MessageCode::BACKEND_ROUTE_NOT_FOUND,
                MessageKey::BACKEND_ROUTE_NOT_FOUND,
                ['%path%' => '/'.$area->value.'/'.$path],
                ['area' => $area->value, 'path' => $path],
            ),
        );
    }
}
