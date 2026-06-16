<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Statistics\VisitorIdGenerator;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\PassiveAbuseSignalSubscriber;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SecuritySignalRecorder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class PassiveAbuseSignalSubscriberTest extends TestCase
{
    public function testItRecordsSuspiciousProbeSignalsWithVisitorAndIpBucketContext(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();
        $subscriber = new PassiveAbuseSignalSubscriber(
            new AbuseRequestInspector(
                new AbuseSubjectResolver($visitorIds, new TokenStorage(), 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection)),
            $metadata,
        );
        $request = Request::create('/.env', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Scanner/1.0',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.10',
        ]);
        $metadata->markStarted($request);

        $subscriber->onKernelResponse(new ResponseEvent(
            new PassiveAbuseSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', 400),
        ));

        $row = $connection->fetchAssociative('SELECT * FROM security_signal_event');
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('probe', $row['signal_type']);
        self::assertSame('security.signal.suspicious_probe', $row['reason_code']);
        self::assertSame('visitor', $row['subject_type']);
        self::assertSame($visitorIds->generate($request), $row['visitor_id']);
        self::assertSame($row['visitor_id'], $row['subject_identifier']);
        self::assertIsString($context['ip_bucket'] ?? null);
        self::assertStringNotContainsString('203.0.113.10', json_encode([$row, $context], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('198.51.100.10', json_encode([$row, $context], JSON_THROW_ON_ERROR));
    }

    public function testItDoesNotRecordOrdinaryNavigationSignals(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $subscriber = new PassiveAbuseSignalSubscriber(
            new AbuseRequestInspector(
                new AbuseSubjectResolver($visitorIds, new TokenStorage(), 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection)),
            new AccessRequestMetadata(),
        );

        $subscriber->onKernelResponse(new ResponseEvent(
            new PassiveAbuseSignalTestKernel(),
            Request::create('/docs'),
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', 200),
        ));

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
    }

    public function testItSanitizesTokenizedPathsBeforeRecordingSignals(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();
        $subscriber = new PassiveAbuseSignalSubscriber(
            new AbuseRequestInspector(
                new AbuseSubjectResolver($visitorIds, new TokenStorage(), 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection)),
            $metadata,
        );
        $token = str_repeat('a', 64);
        $request = Request::create('/user/reset-password/'.$token, 'POST', server: [
            'HTTP_SEC_PURPOSE' => 'prefetch',
        ]);
        $request->attributes->set('_route', 'user_password_reset_token');
        $request->attributes->set('token', $token);
        $metadata->markStarted($request);

        $subscriber->onKernelResponse(new ResponseEvent(
            new PassiveAbuseSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', 200),
        ));

        $row = $connection->fetchAssociative('SELECT * FROM security_signal_event');
        self::assertIsArray($row);
        self::assertSame('/user/reset-password/[redacted]', $row['path']);
        self::assertStringNotContainsString($token, json_encode($row, JSON_THROW_ON_ERROR));
    }
}

final class PassiveAbuseSignalTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
