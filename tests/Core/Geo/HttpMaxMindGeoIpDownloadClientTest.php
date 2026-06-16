<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Geo\HttpMaxMindGeoIpDownloadClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class HttpMaxMindGeoIpDownloadClientTest extends TestCase
{
    public function testItStreamsSuccessfulDownloadsWithoutMaterializingResponseContent(): void
    {
        $workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'studio-geoip-http-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($workspace, 0775, true));
        $target = $workspace.DIRECTORY_SEPARATOR.'GeoLite2-City.tar.gz';
        $client = new ChunkedGeoIpHttpClient(200, ['archive-', 'chunk']);

        try {
            $result = (new HttpMaxMindGeoIpDownloadClient($client))->download('https://example.test/geoip.tar.gz', $target);

            self::assertTrue($result->isSuccess());
            self::assertSame('archive-chunk', file_get_contents($target));
            self::assertArrayNotHasKey('buffer', $client->lastOptions);
        } finally {
            @unlink($target);
            @rmdir($workspace);
        }
    }
}

final class ChunkedGeoIpHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /**
     * @param list<string> $chunks
     */
    public function __construct(private readonly int $statusCode, private readonly array $chunks)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->lastOptions = $options;

        return new ThrowingContentGeoIpResponse($this->statusCode);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $response = $responses instanceof ResponseInterface ? $responses : iterator_to_array($responses)[0];

        return new ChunkedGeoIpResponseStream($response, array_map(
            static fn (string $content): ChunkInterface => new GeoIpResponseChunk($content),
            $this->chunks,
        ));
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class ThrowingContentGeoIpResponse implements ResponseInterface
{
    public function __construct(private readonly int $statusCode)
    {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        throw new \LogicException('The download client must stream chunks instead of materializing the response body.');
    }

    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null === $type ? ['http_code' => $this->statusCode] : null;
    }
}

final class ChunkedGeoIpResponseStream implements ResponseStreamInterface
{
    private int $position = 0;

    /**
     * @param list<ChunkInterface> $chunks
     */
    public function __construct(private readonly ResponseInterface $response, private readonly array $chunks)
    {
    }

    public function current(): ChunkInterface
    {
        return $this->chunks[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function valid(): bool
    {
        return isset($this->chunks[$this->position]);
    }

    public function rewind(): void
    {
        $this->position = 0;
    }
}

final class GeoIpResponseChunk implements ChunkInterface
{
    public function __construct(private readonly string $content)
    {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return false;
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}
