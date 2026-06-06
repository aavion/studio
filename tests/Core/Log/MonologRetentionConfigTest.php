<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MonologRetentionConfigTest extends TestCase
{
    public function testStudioFileChannelsKeepThirtyDailyFiles(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        $handlers = $config['monolog']['handlers'] ?? [];

        foreach (['system_message', 'system_audit', 'system_access'] as $handler) {
            self::assertSame('rotating_file', $handlers[$handler]['type'] ?? null);
            self::assertSame(30, $handlers[$handler]['max_files'] ?? null);
        }
    }
}
