<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
            $response = $this->httpClient()->request('GET', $url, [
                'timeout' => 60.0,
                'max_duration' => 180.0,
                'buffer' => $target,
            ]);
            $status = $response->getStatusCode();
            $response->getContent(false);
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
