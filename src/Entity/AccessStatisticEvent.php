<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Statistics\StatisticsMessageKey;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'access_statistic_event')]
#[ORM\Index(name: 'idx_access_statistic_request_id', columns: ['request_id'])]
#[ORM\Index(name: 'idx_access_statistic_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_visitor_at', columns: ['visitor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_route_at', columns: ['route', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_resolved_at', columns: ['resolved_route', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_surface_at', columns: ['surface', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_status_at', columns: ['http_status', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_method_at', columns: ['method', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_browser_at', columns: ['browser_family', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_device_at', columns: ['device_type', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_bot_at', columns: ['is_bot', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_dnt_at', columns: ['do_not_track', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_referrer_at', columns: ['referrer_host', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_language_at', columns: ['preferred_language', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_country_at', columns: ['country', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_continent_at', columns: ['continent', 'occurred_at'])]
class AccessStatisticEvent
{
    private const TRACE_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,63}\z/';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $requestId;

    #[ORM\Column(length: 64)]
    private string $visitorId;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(length: 1024)]
    private string $path;

    #[ORM\Column(length: 1024)]
    private string $requestedPath;

    #[ORM\Column(length: 190)]
    private string $route;

    #[ORM\Column(length: 190)]
    private string $resolvedRoute;

    #[ORM\Column(length: 40)]
    private string $surface;

    #[ORM\Column]
    private int $httpStatus;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs;

    #[ORM\Column(length: 40)]
    private string $browserFamily;

    #[ORM\Column(length: 40)]
    private string $deviceType;

    #[ORM\Column]
    private bool $isBot;

    #[ORM\Column]
    private bool $doNotTrack;

    #[ORM\Column(length: 255)]
    private string $referrerHost;

    #[ORM\Column(length: 20)]
    private string $preferredLanguage;

    #[ORM\Column(length: 120)]
    private string $requestContentType;

    #[ORM\Column(length: 120)]
    private string $responseContentType;

    #[ORM\Column(nullable: true)]
    private ?int $responseSize;

    #[ORM\Column(length: 80)]
    private string $city;

    #[ORM\Column(length: 80)]
    private string $state;

    #[ORM\Column(length: 80)]
    private string $country;

    #[ORM\Column(length: 80)]
    private string $continent;

    /**
     * @param array<string, mixed> $metadata
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        DateTimeImmutable $occurredAt,
        string $requestId,
        string $visitorId,
        string $method,
        string $path,
        string $requestedPath,
        string $route,
        string $resolvedRoute,
        string $surface,
        int $httpStatus,
        ?int $durationMs = null,
        string $browserFamily = 'other',
        string $deviceType = 'other',
        bool $isBot = false,
        bool $doNotTrack = false,
        string $referrerHost = 'n/a',
        string $preferredLanguage = 'n/a',
        string $requestContentType = 'n/a',
        string $responseContentType = 'n/a',
        ?int $responseSize = null,
        string $city = 'n/a',
        string $state = 'n/a',
        string $country = 'n/a',
        string $continent = 'n/a',
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Access statistic event UID');
        $this->occurredAt = $occurredAt;
        $this->requestId = self::assertTraceId($requestId, 'request_id');
        $this->visitorId = self::assertTraceId($visitorId, 'visitor_id');
        $this->method = substr($method, 0, 16);
        $this->path = substr($path, 0, 1024);
        $this->requestedPath = substr($requestedPath, 0, 1024);
        $this->route = substr($route, 0, 190);
        $this->resolvedRoute = substr($resolvedRoute, 0, 190);
        $this->surface = substr($surface, 0, 40);
        $this->httpStatus = $httpStatus;
        $this->durationMs = null === $durationMs ? null : max(0, $durationMs);
        $this->browserFamily = substr($browserFamily, 0, 40);
        $this->deviceType = substr($deviceType, 0, 40);
        $this->isBot = $isBot;
        $this->doNotTrack = $doNotTrack;
        $this->referrerHost = substr($referrerHost, 0, 255);
        $this->preferredLanguage = substr($preferredLanguage, 0, 20);
        $this->requestContentType = substr($requestContentType, 0, 120);
        $this->responseContentType = substr($responseContentType, 0, 120);
        $this->responseSize = null === $responseSize ? null : max(0, $responseSize);
        $this->city = substr($city, 0, 80);
        $this->state = substr($state, 0, 80);
        $this->country = substr($country, 0, 80);
        $this->continent = substr($continent, 0, 80);
        $this->metadata = $metadata;
    }

    private static function assertTraceId(string $value, string $label): string
    {
        if (1 === preg_match(self::TRACE_ID_PATTERN, $value)) {
            return $value;
        }

        throw MessageException::invalidArgument(
            StatisticsMessageKey::STATISTICS_TRACE_ID_INVALID,
            ['%label%' => $label],
            ['label' => $label, 'length' => strlen($value)],
        );
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function visitorId(): string
    {
        return $this->visitorId;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function requestedPath(): string
    {
        return $this->requestedPath;
    }

    public function route(): string
    {
        return $this->route;
    }

    public function resolvedRoute(): string
    {
        return $this->resolvedRoute;
    }

    public function surface(): string
    {
        return $this->surface;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function browserFamily(): string
    {
        return $this->browserFamily;
    }

    public function deviceType(): string
    {
        return $this->deviceType;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function doNotTrack(): bool
    {
        return $this->doNotTrack;
    }

    public function referrerHost(): string
    {
        return $this->referrerHost;
    }

    public function preferredLanguage(): string
    {
        return $this->preferredLanguage;
    }

    public function responseSize(): ?int
    {
        return $this->responseSize;
    }

    public function country(): string
    {
        return $this->country;
    }
}
