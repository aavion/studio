<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SuspiciousProbePathMatcherTest extends TestCase
{
    public function testItMatchesDefaultHighSignalProbePaths(): void
    {
        $matcher = new SuspiciousProbePathMatcher();

        self::assertTrue($matcher->isProbe('/.env'));
        self::assertTrue($matcher->isProbe('/wp-login.php'));
        self::assertTrue($matcher->isProbe('/backup-2026.sql'));
        self::assertTrue($matcher->isProbe('/package-lock.json'));
        self::assertFalse($matcher->isProbe('/admin/extensions/upload'));
        self::assertFalse($matcher->isProbe('/extension-lock.json'));
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
        $config->set(SuspiciousProbePathMatcher::PATTERNS_KEY, "#/custom-one(?:/|$)#i\n\"#/custom-two(?:/|$)#i\",\"#/custom-three(?:/|$)#i\"", ConfigValueType::String);
        $matcher = new SuspiciousProbePathMatcher($config);

        self::assertTrue($matcher->isProbe('/custom-one'));
        self::assertTrue($matcher->isProbe('/custom-two'));
        self::assertTrue($matcher->isProbe('/custom-three'));
        self::assertFalse($matcher->isProbe('/.env'));
    }

    public function testItPreservesCommasInsideOneLineRegexPatterns(): void
    {
        $config = new Config($this->connection());
        $config->set(SuspiciousProbePathMatcher::PATTERNS_KEY, '#/dump-[0-9]{4,6}\.sql$#', ConfigValueType::String);
        $matcher = new SuspiciousProbePathMatcher($config);

        self::assertTrue($matcher->isProbe('/dump-2026.sql'));
        self::assertFalse($matcher->isProbe('/.env'));
    }

    public function testItCachesConfiguredPatternsForTheServiceLifetime(): void
    {
        $connection = $this->createMock(Connection::class);
        $cache = new ArrayAdapter();
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT value FROM config_entry WHERE config_key = ?', [SuspiciousProbePathMatcher::PATTERNS_KEY])
            ->willReturn(json_encode('#/cached-probe$#', JSON_THROW_ON_ERROR));

        self::assertTrue((new SuspiciousProbePathMatcher(new Config($connection), cache: $cache))->isProbe('/cached-probe'));
        self::assertTrue((new SuspiciousProbePathMatcher(new Config($connection), cache: $cache))->isProbe('/cached-probe'));
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
