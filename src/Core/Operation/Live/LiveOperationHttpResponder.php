<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Output\JsonOutputRenderer;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class LiveOperationHttpResponder
{
    public function __construct(
        private JsonOutputRenderer $json,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    public function render(WorkflowResult $result): Response
    {
        $payload = $result->toArray();

        if ($result->isSuccess() && is_array($result->value())) {
            $value = $result->value();
            $operationId = (string) ($value['operation_id'] ?? '');
            $token = (string) ($value['token'] ?? '');

            if ('' !== $operationId && '' !== $token) {
                $payload['value']['status_url'] = $this->urlGenerator->generate('api_live_operation_status', [
                    'operationId' => $operationId,
                    'token' => $token,
                ]);
            }
        }

        return $this->json->render($payload, $result->isSuccess() ? Response::HTTP_ACCEPTED : Response::HTTP_BAD_REQUEST);
    }
}
