<?php

declare(strict_types=1);

namespace App\Api\Documentation;

final readonly class OpenApiComponentsFactory
{
    /**
     * @return array<string, mixed>
     */
    public function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                ],
            ],
            'headers' => $this->headers(),
            'schemas' => $this->schemas(),
            'responses' => $this->responses(),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function traceHeaders(): array
    {
        return [
            'X-Request-ID' => ['$ref' => '#/components/headers/RequestId'],
            'X-Correlation-ID' => ['$ref' => '#/components/headers/CorrelationId'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function standardErrorResponses(): array
    {
        return [
            '400' => ['$ref' => '#/components/responses/BadRequest'],
            '401' => ['$ref' => '#/components/responses/Unauthorized'],
            '403' => ['$ref' => '#/components/responses/Forbidden'],
            '404' => ['$ref' => '#/components/responses/NotFound'],
            '409' => ['$ref' => '#/components/responses/Conflict'],
            '415' => ['$ref' => '#/components/responses/UnsupportedMediaType'],
            '422' => ['$ref' => '#/components/responses/ValidationFailed'],
            '503' => ['$ref' => '#/components/responses/ServiceUnavailable'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(): array
    {
        return [
            'RequestId' => [
                'description' => 'System-generated request identifier for support and log correlation.',
                'schema' => ['type' => 'string'],
            ],
            'CorrelationId' => [
                'description' => 'Validated inbound X-Correlation-ID or X-Request-ID value when supplied by the client.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'ApiDataEnvelope' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => true,
                    'meta' => ['$ref' => '#/components/schemas/ApiMeta'],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
            'ApiErrorEnvelope' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => ['$ref' => '#/components/schemas/ApiError'],
                ],
                'additionalProperties' => false,
            ],
            'ApiError' => [
                'type' => 'object',
                'required' => ['status', 'code', 'message_key', 'message'],
                'properties' => [
                    'status' => ['type' => 'integer', 'minimum' => 400, 'maximum' => 599],
                    'code' => ['type' => 'string'],
                    'message_key' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    'context' => ['type' => 'object', 'additionalProperties' => true],
                    'details' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'additionalProperties' => false,
            ],
            'ApiMessage' => [
                'type' => 'object',
                'required' => ['level', 'code', 'translation_key', 'message', 'parameters', 'context'],
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['debug', 'info', 'success', 'warning', 'error']],
                    'code' => ['type' => 'string'],
                    'translation_key' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                    'context' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'additionalProperties' => false,
            ],
            'ApiMeta' => [
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => [
                    'pagination' => ['$ref' => '#/components/schemas/ApiPagination'],
                    'messages' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ApiMessage'],
                    ],
                ],
            ],
            'ApiPagination' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'limit' => [
                        'oneOf' => [
                            ['type' => 'integer', 'minimum' => 1],
                            ['type' => 'string', 'enum' => ['all']],
                        ],
                    ],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'page_count' => ['type' => 'integer', 'minimum' => 0],
                ],
                'additionalProperties' => false,
            ],
            'ApiLinks' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
            ],
            'ApiMutationReview' => [
                'type' => 'object',
                'required' => ['type', 'id', 'attributes'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'attributes' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['ok', 'warn', 'fail', 'requires_confirmation']],
                            'confirm_parameter' => ['type' => 'string'],
                            'impact' => ['type' => 'object', 'additionalProperties' => true],
                            'diff' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'additionalProperties' => true,
                    ],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
            'ApiOperationStart' => [
                'type' => 'object',
                'required' => ['type', 'id', 'attributes', 'links'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'attributes' => [
                        'type' => 'object',
                        'properties' => [
                            'operation_id' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                        ],
                        'additionalProperties' => true,
                    ],
                    'links' => ['$ref' => '#/components/schemas/ApiLinks'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(): array
    {
        return [
            'BadRequest' => $this->errorResponse('The request body or parameters are invalid.'),
            'Unauthorized' => $this->errorResponse('API key authentication failed.'),
            'Forbidden' => $this->errorResponse('The authenticated actor is not allowed to use this operation.'),
            'NotFound' => $this->errorResponse('The requested API resource does not exist.'),
            'Conflict' => $this->errorResponse('The requested operation conflicts with the current resource state.'),
            'UnsupportedMediaType' => $this->errorResponse('The request body media type is not supported. Use application/json.'),
            'ValidationFailed' => $this->errorResponse('The request did not pass validation.'),
            'ServiceUnavailable' => $this->errorResponse('The API is temporarily unavailable.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'headers' => $this->traceHeaders(),
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiErrorEnvelope'],
                ],
            ],
        ];
    }
}
