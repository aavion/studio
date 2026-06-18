<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Statistics\VisitorIdGenerator;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\Abuse\SuspiciousPayloadSignalSubscriber;
use App\Security\Abuse\SuspiciousRequestPayloadMatcher;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class SuspiciousPayloadSignalSubscriberTest extends TestCase
{
    public function testItRecordsSuspiciousPayloadSignalsForVisitorAndIpSubjects(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $metadata = new AccessRequestMetadata();
        $request = Request::create('/search', 'GET', [
            'q' => "x' UNION SELECT password FROM users --",
        ], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $metadata->markStarted($request);

        $this->subscriber($connection, $visitorIds, $metadata)->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        $row = $connection->fetchAssociative("SELECT * FROM security_signal_event WHERE subject_type = 'visitor'");
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('payload_probe', $row['signal_type']);
        self::assertSame('security.signal.suspicious_payload', $row['reason_code']);
        self::assertSame($visitorIds->generate($request), $row['visitor_id']);
        self::assertContains('sql_union_select', $context['payload_signatures']);
        self::assertSame('q', $context['payload_parameters'][0]['name']);
        self::assertStringNotContainsString('UNION SELECT', json_encode([$row, $context], JSON_THROW_ON_ERROR));
    }

    public function testItRecordsMalformedSecurityParameterSignals(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $request = Request::create('/user/login', 'POST', [
            'username' => ['owner'],
        ], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set('_route', 'user_login');

        $this->subscriber($connection, $visitorIds, new AccessRequestMetadata())->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        $row = $connection->fetchAssociative('SELECT * FROM security_signal_event');
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('malformed_parameter', $context['payload_signatures']);
        self::assertSame('username', $context['payload_parameters'][0]['name']);
    }

    public function testItRecordsJsonApiPayloadSignals(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $content = json_encode([
            'filter' => [
                'query' => "x' UNION SELECT password FROM users --",
            ],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/v1/search',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($content),
                'REMOTE_ADDR' => '203.0.113.10',
            ],
            content: $content,
        );

        $this->subscriber($connection, $visitorIds, new AccessRequestMetadata())->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
        $row = $connection->fetchAssociative("SELECT * FROM security_signal_event WHERE subject_type = 'visitor'");
        self::assertIsArray($row);
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('sql_union_select', $context['payload_signatures']);
        self::assertSame('json', $context['payload_parameters'][0]['source']);
        self::assertSame('filter.query', $context['payload_parameters'][0]['name']);
        self::assertStringNotContainsString('UNION SELECT', json_encode([$row, $context], JSON_THROW_ON_ERROR));
    }

    public function testItSkipsAutoBanEnforcementRequests(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $request = Request::create('/search', 'GET', [
            'q' => "x' UNION SELECT password FROM users --",
        ], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);

        $this->subscriber($connection, $visitorIds, new AccessRequestMetadata())->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
    }

    public function testItSkipsAdminEditorPayloadsThatMayContainCustomCode(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $request = Request::create('/admin/content/schemas', 'POST', [
            'custom_twig' => '<script type="application/json">{{ schema|json_encode }}</script>',
        ], server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        $this->subscriber($connection, $visitorIds, new AccessRequestMetadata())->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
    }

    public function testItSkipsAdminJsonPayloadsThatMayContainCustomCode(): void
    {
        $connection = $this->connection();
        $visitorIds = new VisitorIdGenerator('test-secret');
        $content = json_encode([
            'custom_twig' => '<script type="application/json">{{ schema|json_encode }}</script>',
        ], JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/admin/content/schemas',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($content),
                'REMOTE_ADDR' => '203.0.113.10',
            ],
            content: $content,
        );

        $this->subscriber($connection, $visitorIds, new AccessRequestMetadata())->onKernelRequest(new RequestEvent(
            new SuspiciousPayloadSignalTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM security_signal_event'));
    }

    private function subscriber(Connection $connection, VisitorIdGenerator $visitorIds, AccessRequestMetadata $metadata): SuspiciousPayloadSignalSubscriber
    {
        return new SuspiciousPayloadSignalSubscriber(
            new SuspiciousRequestPayloadMatcher(),
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
}

final class SuspiciousPayloadSignalTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
