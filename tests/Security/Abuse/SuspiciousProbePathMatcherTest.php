<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SuspiciousProbePathMatcherTest extends TestCase
{
    public function testItMatchesDefaultHighSignalProbePaths(): void
    {
        $matcher = new SuspiciousProbePathMatcher();

        self::assertTrue($matcher->isProbe('/.env'));
        self::assertTrue($matcher->isProbe('/wp-login.php'));
        self::assertTrue($matcher->isProbe('/backup-2026.sql'));
        self::assertFalse($matcher->isProbe('/admin/packages/upload'));
    }

    public function testItUsesConfiguredPatternsWhenProvided(): void
    {
        $matcher = new SuspiciousProbePathMatcher(patterns: [
            '#/custom-probe(?:/|$)#i',
        ]);

        self::assertTrue($matcher->isProbe('/custom-probe'));
        self::assertFalse($matcher->isProbe('/.env'));
    }

    public function testItFallsBackToDefaultsWhenConfiguredPatternsAreInvalid(): void
    {
        $matcher = new SuspiciousProbePathMatcher(patterns: [
            '#unterminated',
            '',
        ]);

        self::assertTrue($matcher->isProbe('/.git/config'));
    }

    public function testItParsesConfiguredPatternTextAsNewlineAndCsvValues(): void
    {
        $config = new Config($this->connection());
        $config->set(SuspiciousProbePathMatcher::PATTERNS_KEY, "#/custom-one(?:/|$)#i\n#/custom-two(?:/|$)#i,#/custom-three(?:/|$)#i", ConfigValueType::String);
        $matcher = new SuspiciousProbePathMatcher($config);

        self::assertTrue($matcher->isProbe('/custom-one'));
        self::assertTrue($matcher->isProbe('/custom-two'));
        self::assertTrue($matcher->isProbe('/custom-three'));
        self::assertFalse($matcher->isProbe('/.env'));
    }

    public function testDefaultPatternTextContainsOneEditablePatternPerLine(): void
    {
        $lines = array_filter(explode("\n", SuspiciousProbePathMatcher::defaultPatternText()));

        self::assertSame(SuspiciousProbePathMatcher::DEFAULT_PATTERNS, array_values($lines));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
