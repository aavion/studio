<?php

declare(strict_types=1);

namespace App\Core\Diff;

interface DiffGeneratorInterface
{
    public function diff(string $label, mixed $before, mixed $after): StructuredDiff;
}
