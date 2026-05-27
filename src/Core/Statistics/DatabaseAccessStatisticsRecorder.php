<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Log\AccessRequestMetadata;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class DatabaseAccessStatisticsRecorder implements AccessStatisticsRecorderInterface
{
    private const PLACEHOLDER = 'n/a';

    public function __construct(
        private Connection $connection,
        private VisitorIdGenerator $visitorIdGenerator,
        private UserAgentClassifier $userAgentClassifier,
        private AccessRequestMetadata $accessRequestMetadata,
    ) {
    }

    public function record(Request $request, Response $response): void
    {
        try {
            $userAgent = trim((string) $request->headers->get('User-Agent', self::PLACEHOLDER));
            $client = $this->userAgentClassifier->classify($userAgent);

            $this->connection->insert('access_statistic_event', [
                'uid' => $this->uuid(),
                'occurred_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'request_id' => $this->accessRequestMetadata->requestId($request),
                'visitor_id' => $this->visitorIdGenerator->generate($request),
                'method' => substr($request->getMethod(), 0, 16),
                'path' => substr($request->getPathInfo(), 0, 1024),
                'requested_path' => substr($request->getPathInfo(), 0, 1024),
                'route' => $this->accessRequestMetadata->resolvedRoute($request),
                'resolved_route' => $this->accessRequestMetadata->resolvedRoute($request),
                'surface' => $this->accessRequestMetadata->surface($request),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $this->accessRequestMetadata->durationMs($request),
                'browser_family' => $client['browser_family'],
                'device_type' => $client['device_type'],
                'is_bot' => $client['is_bot'],
                'referrer_host' => $this->accessRequestMetadata->referrerHost($request),
                'preferred_language' => $this->accessRequestMetadata->preferredLanguage($request),
                'request_content_type' => $this->accessRequestMetadata->contentType($request->headers->get('Content-Type')),
                'response_content_type' => $this->accessRequestMetadata->contentType($response->headers->get('Content-Type')),
                'response_size' => $this->accessRequestMetadata->responseSize($response),
                'city' => self::PLACEHOLDER,
                'state' => self::PLACEHOLDER,
                'country' => self::PLACEHOLDER,
                'continent' => self::PLACEHOLDER,
                'metadata' => json_encode(['query_present' => null !== $request->getQueryString()], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
