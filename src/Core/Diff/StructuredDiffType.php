<?php

declare(strict_types=1);

namespace App\Core\Diff;

enum StructuredDiffType: string
{
    case Text = 'text';
    case KeyValue = 'key_value';
}
