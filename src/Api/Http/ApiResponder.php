<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Core\Message\Message;
use App\Core\Output\JsonOutputRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ApiResponder
{
    public function __construct(
        private JsonOutputRenderer $json,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    public function data(mixed $data, int $status = Response::HTTP_OK, array $meta = [], array $links = []): Response
    {
        $payload = ['data' => $data];

        if ([] !== $meta) {
            $payload['meta'] = $meta;
        }

        if ([] !== $links) {
            $payload['links'] = $links;
        }

        return $this->json->render($payload, $status);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function error(
        Message $message,
        int $status,
        ?Request $request = null,
        array $context = [],
        array $headers = [],
    ): Response {
        $error = [
            'status' => $status,
            'code' => $message->code(),
            'message_key' => $message->translationKey(),
            'message' => $this->translator->trans(
                $message->translationKey(),
                $message->parameters(),
                locale: $request?->getLocale(),
            ),
        ];

        if ([] !== $message->parameters()) {
            $error['parameters'] = $message->parameters();
        }

        $context = [...$message->context(), ...$context];
        if ([] !== $context) {
            $error['context'] = $context;
        }

        return $this->json->render(['error' => $error], $status, $headers);
    }
}
