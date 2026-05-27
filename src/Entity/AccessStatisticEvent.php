<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'access_statistic_event')]
#[ORM\Index(name: 'idx_access_statistic_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_visitor_at', columns: ['visitor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_route_at', columns: ['route', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_status_at', columns: ['http_status', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_browser_at', columns: ['browser_family', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_device_at', columns: ['device_type', 'occurred_at'])]
#[ORM\Index(name: 'idx_access_statistic_bot_at', columns: ['is_bot', 'occurred_at'])]
class AccessStatisticEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $visitorId;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(length: 1024)]
    private string $path;

    #[ORM\Column(length: 190)]
    private string $route;

    #[ORM\Column]
    private int $httpStatus;

    #[ORM\Column(length: 40)]
    private string $browserFamily;

    #[ORM\Column(length: 40)]
    private string $deviceType;

    #[ORM\Column]
    private bool $isBot;

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
        string $visitorId,
        string $method,
        string $path,
        string $route,
        int $httpStatus,
        string $browserFamily = 'other',
        string $deviceType = 'other',
        bool $isBot = false,
        string $city = 'n/a',
        string $state = 'n/a',
        string $country = 'n/a',
        string $continent = 'n/a',
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Access statistic event UID');
        $this->occurredAt = $occurredAt;
        $this->visitorId = $visitorId;
        $this->method = substr($method, 0, 16);
        $this->path = substr($path, 0, 1024);
        $this->route = substr($route, 0, 190);
        $this->httpStatus = $httpStatus;
        $this->browserFamily = substr($browserFamily, 0, 40);
        $this->deviceType = substr($deviceType, 0, 40);
        $this->isBot = $isBot;
        $this->city = substr($city, 0, 80);
        $this->state = substr($state, 0, 80);
        $this->country = substr($country, 0, 80);
        $this->continent = substr($continent, 0, 80);
        $this->metadata = $metadata;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
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

    public function route(): string
    {
        return $this->route;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
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

    public function country(): string
    {
        return $this->country;
    }
}
