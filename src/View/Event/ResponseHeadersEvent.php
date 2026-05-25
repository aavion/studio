<?php

declare(strict_types=1);

namespace App\View\Event;

use App\Core\Event\PublicEventInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

final class ResponseHeadersEvent extends Event implements PublicEventInterface
{
    /**
     * @var list<array{name: string, value: string|list<string>, replace: bool}>
     */
    private array $headers = [];

    /**
     * @var list<string>
     */
    private array $removedHeaders = [];

    public function __construct(
        private readonly Request $request,
        private readonly int $statusCode,
        private readonly ?string $contentType,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * @param string|list<string> $value
     */
    public function setHeader(string $name, string|array $value, bool $replace = true): void
    {
        $this->headers[] = [
            'name' => $name,
            'value' => is_array($value) ? array_values($value) : $value,
            'replace' => $replace,
        ];
    }

    public function removeHeader(string $name): void
    {
        $this->removedHeaders[] = $name;
    }

    /**
     * @return list<array{name: string, value: string|list<string>, replace: bool}>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string>
     */
    public function removedHeaders(): array
    {
        return $this->removedHeaders;
    }
}
