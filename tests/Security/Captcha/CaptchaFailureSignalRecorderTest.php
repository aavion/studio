<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Statistics\VisitorIdGenerator;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use App\Security\Captcha\CaptchaFailureSignalRecorder;
use App\Security\Captcha\CaptchaInstanceEntry;
use App\Security\Captcha\CaptchaValidationResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class CaptchaFailureSignalRecorderTest extends TestCase
{
    public function testItRecordsProviderBackedCaptchaFailuresAsSecuritySignals(): void
    {
        $connection = $this->connection();
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/user/register', 'POST', ['email' => 'visitor@example.org'], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set('_route', 'user_register');
        $metadata->markStarted($request);
        $recorder = $this->recorder($connection, $metadata);

        $recorder->record($request, $this->entry(), CaptchaValidationResult::recoverableFailure('demo-captcha', [
            'failure_code' => 'wrong_choice',
        ]));

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        $row = $connection->fetchAssociative("SELECT * FROM security_signal_event WHERE subject_type = 'visitor'");
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('captcha', $row['signal_type']);
        self::assertSame(AutoBanScoreCatalogue::SIGNAL_CAPTCHA_FAILURE, $row['reason_code']);
        self::assertSame('NOTICE', $row['severity']);
        self::assertSame(65, (int) $row['confidence']);
        self::assertSame('browser', $row['request_family']);
        self::assertSame('captcha_failure', $row['request_intent']);
        self::assertSame('demo-captcha', $context['captcha_provider']);
        self::assertSame('recoverable_failure', $context['captcha_status']);
        self::assertSame('wrong_choice', $context['captcha_failure_code']);
        self::assertSame('registration', $context['original_request_intent']);
        self::assertSame('captcha_failure', $context['cost_bucket']);
    }

    public function testItDoesNotRecordFallbackOrProviderFaultResults(): void
    {
        $connection = $this->connection();
        $recorder = $this->recorder($connection, new AccessRequestMetadata());
        $request = Request::create('/user/register', 'POST');
        $entry = $this->entry();

        $recorder->record($request, $entry, CaptchaValidationResult::skipped('none'));
        $recorder->record($request, $entry, CaptchaValidationResult::recoverableFailure('none'));
        $recorder->record($request, $entry, CaptchaValidationResult::providerFault('demo-captcha'));
        $recorder->record($request, $entry, CaptchaValidationResult::providerUnavailable('demo-captcha'));

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
    }

    public function testItRecordsVisitorMismatchAgainstExistingInstanceAsLowRiskSignal(): void
    {
        $connection = $this->connection();
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/user/register', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set('_route', 'user_register');
        $metadata->markStarted($request);

        $this->recorder($connection, $metadata)->recordVisitorMismatch($request, $this->entry());

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        $row = $connection->fetchAssociative("SELECT * FROM security_signal_event WHERE subject_type = 'visitor'");
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('captcha', $row['signal_type']);
        self::assertSame(AutoBanScoreCatalogue::SIGNAL_CAPTCHA_VISITOR_MISMATCH, $row['reason_code']);
        self::assertSame('NOTICE', $row['severity']);
        self::assertSame(45, (int) $row['confidence']);
        self::assertSame('captcha_failure', $row['request_intent']);
        self::assertSame('visitor_mismatch', $context['captcha_status']);
        self::assertSame('visitor_mismatch', $context['captcha_failure_code']);
        self::assertNull($context['captcha_provider']);
    }

    private function recorder(Connection $connection, AccessRequestMetadata $metadata): CaptchaFailureSignalRecorder
    {
        $visitorIds = new VisitorIdGenerator('test-secret');

        return new CaptchaFailureSignalRecorder(
            new AbuseRequestInspector(
                new AbuseSubjectResolver($visitorIds, new TokenStorage(), 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection)),
            $metadata,
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');

        return $connection;
    }

    private function entry(): CaptchaInstanceEntry
    {
        return new CaptchaInstanceEntry(
            '10000000-0000-7000-8000-000000000710',
            'visitor-context',
            'user.registration',
            'user-registration-form',
            'captcha',
            time(),
        );
    }
}
