<?php

declare(strict_types=1);

namespace App\Core\Diff;

enum StructuredDiffChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}
