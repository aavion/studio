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
     * @return array{level: string, code: string, translation_key: string, message: string, parameters: array<string, mixed>, context: array<string, mixed>}
     */
    public function message(Message $message, ?Request $request = null): array
    {
        return [
            ...$message->toArray(),
            'message' => $this->translatedMessage($message, $request),
        ];
    }

    /**
     * @param iterable<Message> $messages
     *
     * @return list<array{level: string, code: string, translation_key: string, message: string, parameters: array<string, mixed>, context: array<string, mixed>}>
     */
    public function messages(iterable $messages, ?Request $request = null): array
    {
        $payload = [];

        foreach ($messages as $message) {
            $payload[] = $this->message($message, $request);
        }

        return $payload;
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
            'message' => $this->translatedMessage($message, $request),
        ];

        if ([] !== $message->parameters()) {
            $error['parameters'] = $message->parameters();
        }

        $context = [...$message->context(), ...$context];
        if ([] !== $context) {
            $error['context'] = $context;
        }

        $details = $this->errorDetails($context);
        if ([] !== $details) {
            $error['details'] = $details;
        }

        return $this->json->render(['error' => $error], $status, $headers);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function errorDetails(array $context): array
    {
        $errors = $context['errors'] ?? null;

        if (!is_array($errors)) {
            return [];
        }

        return ['fields' => $errors];
    }

    private function translatedMessage(Message $message, ?Request $request): string
    {
        return $this->translator->trans(
            $message->translationKey(),
            $message->parameters(),
            locale: $this->responseLocale($request),
        );
    }

    private function responseLocale(?Request $request): ?string
    {
        $language = $request?->query->get('language');

        if (is_string($language) && '' !== trim($language)) {
            return trim($language);
        }

        return $request?->getLocale();
    }
}
