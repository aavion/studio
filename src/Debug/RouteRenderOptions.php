<?php

declare(strict_types=1);

namespace App\Debug;

use App\Security\UserRole;

final readonly class RouteRenderOptions
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public ?UserRole $role = UserRole::Owner,
        public ?string $username = null,
        public bool $setupCompleted = true,
        public string $host = 'localhost',
        public bool $secure = false,
        /** @var array<string, list<string>> */
        public array $headers = [],
    ) {
    }
}
