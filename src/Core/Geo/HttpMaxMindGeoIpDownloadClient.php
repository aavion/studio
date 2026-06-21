<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class HttpMaxMindGeoIpDownloadClient implements MaxMindGeoIpDownloadClientInterface
{
    public function __construct(private ?HttpClientInterface $httpClient = null)
    {
    }

    public function download(string $url, string $targetPath): WorkflowResult
    {
        $target = @fopen($targetPath, 'w+b');
        if (!is_resource($target)) {
            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_WRITE_FAILED,
                GeoIpMessageKey::GEOIP_DOWNLOAD_WRITE_FAILED,
                ['stage' => 'download'],
            );
        }

        try {
            $client = $this->httpClient();
            $response = $client->request('GET', $url, [
                'timeout' => 60.0,
                'max_duration' => 180.0,
            ]);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300 && !$this->writeResponse($client, $response, $target)) {
                fclose($target);
                @unlink($targetPath);

                return $this->failure(
                    GeoIpMessageCode::GEOIP_DOWNLOAD_WRITE_FAILED,
                    GeoIpMessageKey::GEOIP_DOWNLOAD_WRITE_FAILED,
                    ['stage' => 'download'],
                );
            }
        } catch (TransportExceptionInterface) {
            fclose($target);
            @unlink($targetPath);

            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_SERVER_UNREACHABLE,
                GeoIpMessageKey::GEOIP_DOWNLOAD_SERVER_UNREACHABLE,
                ['stage' => 'download'],
            );
        }

        fclose($target);

        if (401 === $status || 403 === $status) {
            @unlink($targetPath);

            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_INVALID_LICENSE_KEY,
                GeoIpMessageKey::GEOIP_DOWNLOAD_INVALID_LICENSE_KEY,
                ['stage' => 'download', 'http_status' => $status],
            );
        }

        if ($status < 200 || $status >= 300) {
            @unlink($targetPath);

            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_FAILED,
                GeoIpMessageKey::GEOIP_DOWNLOAD_FAILED,
                ['stage' => 'download', 'http_status' => $status],
            );
        }

        if (!is_file($targetPath) || 0 === filesize($targetPath)) {
            return $this->failure(
                GeoIpMessageCode::GEOIP_DOWNLOAD_FAILED,
                GeoIpMessageKey::GEOIP_DOWNLOAD_FAILED,
                ['stage' => 'download'],
            );
        }

        return WorkflowResult::success(null, ['stage' => 'download']);
    }

    /**
     * @param resource $target
     */
    private function writeResponse(HttpClientInterface $client, ResponseInterface $response, mixed $target): bool
    {
        foreach ($client->stream($response) as $chunk) {
            $content = $chunk->getContent();

            if ('' === $content) {
                continue;
            }

            if (!$this->writeAll($target, $content)) {
                return false;
            }
        }

        return fflush($target);
    }

    /**
     * @param resource $target
     */
    private function writeAll(mixed $target, string $content): bool
    {
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $written = @fwrite($target, substr($content, $offset));

            if (!is_int($written) || $written <= 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }

    private function httpClient(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function failure(string $code, string $key, array $context): WorkflowResult
    {
        return WorkflowResult::failed([
            Message::error($code, $key, context: $context),
        ], $context);
    }
}
