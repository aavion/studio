<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use App\Api\ApiMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\Identifier;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiEndpointDefinition
{
    /**
     * @param list<string> $tags
     * @param array<string, mixed> $parameters
     * @param array<string, mixed>|null $requestSchema
     * @param array<string, mixed>|null $responseSchema
     */
    public function __construct(
        private string $owner,
        private string $method,
        private string $path,
        private string $routeName,
        private string $operationId,
        private string $summary,
        private ?string $handlerKey = null,
        private array $tags = [],
        private array $parameters = [],
        private ?array $requestSchema = null,
        private ?array $responseSchema = null,
        private int $successStatus = 200,
        private bool $allowPublic = false,
        private ?string $pathPattern = null,
    ) {
        $this->assertOwner($owner);
        $this->assertMethod($method);
        $this->assertPath($path);
        $this->assertRouteName($routeName);
        $this->assertOperationId($operationId);
        $this->assertHandlerKey($handlerKey);
        $this->assertSummary($summary);
        $this->assertSuccessStatus($successStatus);
        $this->assertPathPattern($pathPattern);
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function routeName(): string
    {
        return $this->routeName;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function handlerKey(): ?string
    {
        return $this->handlerKey;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function requestSchema(): ?array
    {
        return $this->requestSchema;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function responseSchema(): ?array
    {
        return $this->responseSchema;
    }

    public function successStatus(): int
    {
        return $this->successStatus;
    }

    public function allowsPublic(): bool
    {
        return $this->allowPublic;
    }

    public function matchesPath(string $path): bool
    {
        if ($this->path === $path) {
            return true;
        }

        return null !== $this->pathPattern && 1 === preg_match($this->pathPattern, $path);
    }

    private function assertOwner(string $owner): void
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{1,158}[a-z0-9]$/', $owner)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_OWNER_INVALID, [
                '%owner%' => $owner,
            ]);
        }
    }

    private function assertMethod(string $method): void
    {
        if (!in_array($method, [
            Request::METHOD_GET,
            Request::METHOD_HEAD,
            Request::METHOD_OPTIONS,
            Request::METHOD_POST,
            Request::METHOD_PUT,
            Request::METHOD_PATCH,
            Request::METHOD_DELETE,
        ], true)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_METHOD_INVALID, [
                '%method%' => $method,
            ]);
        }
    }

    private function assertPath(string $path): void
    {
        if (!str_starts_with($path, '/api/v1/')) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $path,
            ]);
        }
    }

    private function assertRouteName(string $routeName): void
    {
        Identifier::assertSnakeCase($routeName, ApiMessageKey::API_ENDPOINT_ROUTE_INVALID, '%route%');
    }

    private function assertOperationId(string $operationId): void
    {
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $operationId)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_OPERATION_INVALID, [
                '%operation%' => $operationId,
            ]);
        }
    }

    private function assertHandlerKey(?string $handlerKey): void
    {
        if (null === $handlerKey) {
            return;
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $handlerKey)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_HANDLER_INVALID, [
                '%handler%' => $handlerKey,
            ]);
        }
    }

    private function assertSummary(string $summary): void
    {
        if ('' === trim($summary)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_SUMMARY_EMPTY);
        }
    }

    private function assertSuccessStatus(int $status): void
    {
        if ($status < 200 || $status > 299) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_SUCCESS_STATUS_INVALID, [
                '%status%' => $status,
            ]);
        }
    }

    private function assertPathPattern(?string $pattern): void
    {
        if (null === $pattern) {
            return;
        }

        if (false === @preg_match($pattern, '')) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $pattern,
            ]);
        }
    }
}
