<?php

declare(strict_types=1);

namespace App\Live;

use App\Api\ApiMessageKey;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageException;
use App\Core\Validation\Identifier;
use Symfony\Component\HttpFoundation\Request;

final readonly class LiveEndpointDefinition
{
    public function __construct(
        private string $owner,
        private string $method,
        private string $path,
        private string $routeName,
        private string $operationId,
        private string $summary,
        private string $handlerKey,
        private bool $allowPublic = false,
        private ?int $minimumAccessLevel = null,
        private ?string $pathPattern = null,
    ) {
        $this->assertOwner($owner);
        $this->assertMethod($method);
        $this->assertPath($path);
        Identifier::assertSnakeCase($routeName, ApiMessageKey::API_ENDPOINT_ROUTE_INVALID, '%route%');
        $this->assertOperationId($operationId);
        $this->assertHandlerKey($handlerKey);
        $this->assertSummary($summary);
        $this->assertPublicAccess($method, $allowPublic);
        AccessLevel::assert($minimumAccessLevel);
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

    public function handlerKey(): string
    {
        return $this->handlerKey;
    }

    public function allowsPublic(): bool
    {
        return $this->allowPublic;
    }

    public function minimumAccessLevel(): ?int
    {
        return $this->minimumAccessLevel;
    }

    public function pathPattern(): ?string
    {
        return $this->pathPattern;
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
        ], true)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_METHOD_INVALID, [
                '%method%' => $method,
            ]);
        }
    }

    private function assertPath(string $path): void
    {
        if (!str_starts_with($path, '/api/live/')) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_PATH_INVALID, [
                '%path%' => $path,
            ]);
        }
    }

    private function assertOperationId(string $operationId): void
    {
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $operationId)) {
            throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_OPERATION_INVALID, [
                '%operation%' => $operationId,
            ]);
        }
    }

    private function assertHandlerKey(string $handlerKey): void
    {
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

    private function assertPublicAccess(string $method, bool $allowPublic): void
    {
        if (!$allowPublic || in_array($method, [Request::METHOD_GET, Request::METHOD_HEAD, Request::METHOD_OPTIONS], true)) {
            return;
        }

        throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_METHOD_INVALID, [
            '%method%' => $method,
        ]);
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
