<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiJsonRequestParser;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SettingsApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SettingsApiReadModel $readModel,
        private CoreSettingsFormHandler $formHandler,
        private ApiJsonRequestParser $jsonRequests,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return SettingsApiEndpointProvider::HANDLER_SETTINGS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $section = $this->sectionFromPath($request->getPathInfo());
        if ($request->isMethod(Request::METHOD_PATCH)) {
            return null === $section
                ? $this->notFound($request, null)
                : $this->updateSection($request, $section);
        }

        $settings = null === $section
            ? $this->readModel->sections()
            : $this->readModel->settings($section);
        if (null !== $section && [] === $settings) {
            return $this->notFound($request, $section);
        }

        return $this->responder->data($settings, meta: [
            'count' => count($settings),
            'section' => $section,
        ]);
    }

    private function sectionFromPath(string $path): ?string
    {
        $prefix = '/api/v1/admin/settings/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $section = rawurldecode(substr($path, strlen($prefix)));

        return '' === $section ? null : $section;
    }

    private function updateSection(Request $request, string $section): Response
    {
        $currentValues = $this->readModel->values($section);
        if ([] === $currentValues) {
            return $this->notFound($request, $section);
        }

        try {
            $payload = $this->jsonRequests->object($request);
        } catch (JsonException $error) {
            return $this->invalidRequest($request, $error->getMessage());
        }

        $values = $payload['values'] ?? null;
        if (!is_array($values) || array_is_list($values)) {
            return $this->invalidRequest($request, 'Expected object property "values".');
        }

        $unknown = array_values(array_diff(array_keys($values), array_keys($currentValues)));
        if ([] !== $unknown) {
            return $this->validationFailed($request, ['__unknown' => $unknown], ['section' => $section]);
        }

        $context = ApiRequestContext::fromRequest($request);
        $result = $this->formHandler->submit($section, [
            ...$currentValues,
            ...$values,
        ], $context?->actor()->username());

        if (!$result->isValid()) {
            return $this->validationFailed($request, $result->errors(), ['section' => $section]);
        }

        $settings = $this->readModel->settings($section);

        return $this->responder->data($settings, meta: [
            'count' => count($settings),
            'section' => $section,
            'updated_keys' => array_keys($values),
        ]);
    }

    private function notFound(Request $request, ?string $section): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                'path' => $request->getPathInfo(),
                'section' => $section,
            ]),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    private function invalidRequest(Request $request, string $reason): Response
    {
        return $this->responder->error(
            Message::warning(CommonMessageCode::E_INVALID_ARGUMENT, ApiMessageKey::API_REQUEST_INVALID, context: [
                'path' => $request->getPathInfo(),
                'reason' => $reason,
            ]),
            Response::HTTP_BAD_REQUEST,
            $request,
        );
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $context
     */
    private function validationFailed(Request $request, array $errors, array $context = []): Response
    {
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_VALIDATION_FAILED, ApiMessageKey::API_VALIDATION_FAILED, context: [
                ...$context,
                'path' => $request->getPathInfo(),
                'errors' => $errors,
            ]),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $request,
        );
    }
}
