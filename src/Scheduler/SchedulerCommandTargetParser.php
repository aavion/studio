<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Input\StringInput;

final readonly class SchedulerCommandTargetParser
{
    /**
     * @return list<string>
     */
    public function parse(string $target): array
    {
        $input = new StringInput(trim($target));

        return array_values(array_filter(
            $input->getRawTokens(false),
            static fn (string $token): bool => '' !== trim($token),
        ));
    }
}
