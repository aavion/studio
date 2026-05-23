<?php

declare(strict_types=1);

namespace App\Content;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function isPubliclyRenderable(): bool
    {
        return self::Published === $this;
    }
}
