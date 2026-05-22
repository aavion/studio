<?php

declare(strict_types=1);

namespace App\Core\DryRun;

enum DryRunDiffType: string
{
    case Text = 'text';
    case KeyValue = 'key_value';
}
