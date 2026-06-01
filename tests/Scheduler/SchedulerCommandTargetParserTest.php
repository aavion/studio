<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\SchedulerCommandTargetParser;
use PHPUnit\Framework\TestCase;

final class SchedulerCommandTargetParserTest extends TestCase
{
    public function testItPreservesQuotedCommandArguments(): void
    {
        self::assertSame([
            'app:import',
            '--name=Nightly Import',
            '--path=/tmp/demo file.csv',
            'plain',
        ], (new SchedulerCommandTargetParser())->parse('app:import --name="Nightly Import" --path=\'/tmp/demo file.csv\' plain'));
    }
}
