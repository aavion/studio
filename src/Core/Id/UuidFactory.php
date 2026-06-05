<?php

declare(strict_types=1);

namespace App\Core\Id;

use Symfony\Component\Uid\Uuid;

final class UuidFactory
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
