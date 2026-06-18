<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

final readonly class AutoBanStoreResult
{
    public function __construct(
        private ActiveAutoBan $ban,
        private bool $created,
    ) {
    }

    public function ban(): ActiveAutoBan
    {
        return $this->ban;
    }

    public function created(): bool
    {
        return $this->created;
    }
}
