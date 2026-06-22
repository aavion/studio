<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Operation\Live\LiveOperationPresentationRedactor;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Core\Output\JsonOutputRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LiveOperationController extends AbstractController
{
    public function __construct(
        private readonly LiveOperationRunStore $runStore,
        private readonly LiveOperationStarter $operationStarter,
        private readonly JsonOutputRenderer $json,
        private readonly TranslatorInterface $translator,
        private readonly LiveOperationPresentationRedactor $redactor = new LiveOperationPresentationRedactor(),
    ) {
    }

    #[Route('/api/live/operations/{operationId}', name: 'api_live_operation_status', requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function status(Request $request, string $operationId): Response
    {
        $token = (string) $request->query->get('token', '');
        $cursorValue = $request->query->get('cursor', 0);
        $cursor = is_scalar($cursorValue) && false !== filter_var((string) $cursorValue, FILTER_VALIDATE_INT)
            ? max(0, (int) $cursorValue)
            : 0;
        $payload = $this->runStore->pollingPayload($operationId, $token, $cursor);

        if (null === $payload) {
            return $this->json->render(['status' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json->render($this->localizedPayload($payload, $token));
    }

    #[Route('/api/live/operations/{operationId}/continue', name: 'api_live_operation_continue', requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function continueOperation(Request $request, string $operationId): Response
    {
        $token = (string) $request->query->get('token', '');
        $continuation = $this->runStore->continuation($operationId, $token);

        if (null === $continuation) {
            return $this->json->render(['status' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $result = $this->operationStarter->start(
            $continuation['operation'],
            $continuation['payload'],
            $continuation['label'],
        );
        $payload = $this->redactor->workflowResult($result);

        if ($result->isSuccess() && is_array($result->value())) {
            $value = $result->value();
            $continuedOperationId = (string) ($value['operation_id'] ?? '');
            $continuedToken = (string) ($value['token'] ?? '');

            if ('' !== $continuedOperationId && '' !== $continuedToken) {
                $payload['value']['status_url'] = $this->generateUrl('api_live_operation_status', [
                    'operationId' => $continuedOperationId,
                    'token' => $continuedToken,
                ]);
            }
        }

        return $this->json->render(
            $payload,
            $result->isSuccess() ? Response::HTTP_ACCEPTED : Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function localizedPayload(array $payload, string $token): array
    {
        foreach ($payload['entries'] ?? [] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entry['issues'] = $this->localizedMessages($entry['issues'] ?? []);
            $entry['messages'] = $this->localizedMessages($entry['messages'] ?? []);
            $payload['entries'][$index] = $entry;
        }

        if (isset($payload['result']) && is_array($payload['result'])) {
            $payload['result']['issues'] = $this->localizedMessages($payload['result']['issues'] ?? []);
            $payload['result']['messages'] = $this->localizedMessages($payload['result']['messages'] ?? []);
        }

        if (($payload['can_continue'] ?? false) && is_string($payload['operation_id'] ?? null)) {
            $payload['continue_url'] = $this->generateUrl('api_live_operation_continue', [
                'operationId' => $payload['operation_id'],
                'token' => $token,
            ]);
        }

        return $payload;
    }

    /**
     * @param mixed $messages
     *
     * @return list<array<string, mixed>>
     */
    private function localizedMessages(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $localized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $key = $message['translation_key'] ?? null;
            $parameters = $message['parameters'] ?? [];
            $message['message'] = is_string($key)
                ? $this->translator->trans($key, is_array($parameters) ? $parameters : [])
                : null;
            $localized[] = $message;
        }

        return $localized;
    }
}
