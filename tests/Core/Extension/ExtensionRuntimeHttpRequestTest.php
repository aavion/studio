<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Extension\ExtensionHttpRequest;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExtensionRuntimeHttpRequestTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/http-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/http-facade');
        ExtensionRuntime::reset();
    }

    public function testItReturnsSafeFailureForNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, httpRequests: new ExtensionHttpRequest(new MockHttpClient())));

        $result = ExtensionRuntime::httpRequest('GET', 'https://93.184.216.34/status');

        self::assertFalse($result['ok']);
        self::assertSame('invalid_extension', $result['error']);
        self::assertNull($result['status']);
    }

    public function testItPerformsBoundedHttpRequestsForExtensionCallers(): void
    {
        $seenOptions = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse('{"accepted":true}', [
                'http_code' => 201,
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, httpRequests: new ExtensionHttpRequest($client)));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_http_request(
                'POST',
                'https://93.184.216.34/submit',
                ['value' => 'demo'],
                ['headers' => ['x-extension' => 'http-facade']]
            );
            PHP);

        $result = require $this->projectDir.'/extensions/http-facade/extension.php';

        self::assertTrue($result['ok']);
        self::assertSame(201, $result['status']);
        self::assertSame('{"accepted":true}', $result['body']);
        self::assertSame(['accepted' => true], $result['json']);
        self::assertSame(['application/json'], $result['headers']['content-type']);
        self::assertNull($result['error']);
        self::assertIsArray($seenOptions);
        self::assertSame(0, $seenOptions['max_redirects']);
        self::assertArrayNotHasKey('allow_private_networks', $seenOptions);
    }

    public function testItRejectsInvalidMethodsSchemesAndDefaultPrivateNetworks(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, httpRequests: new ExtensionHttpRequest(new MockHttpClient())));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_http_request('TRACE', 'https://93.184.216.34/status')['error'],
                extension_http_request('GET', 'ftp://example.com/file')['error'],
                extension_http_request('GET', 'file:///etc/passwd')['error'],
                extension_http_request('GET', 'http://127.0.0.1/status')['error'],
                extension_http_request('GET', 'http://[::1]/status')['error'],
                extension_http_request('GET', 'http://localhost/status')['error'],
            ];
            PHP);

        self::assertSame([
            'invalid_method',
            'invalid_scheme',
            'invalid_url',
            'private_network_blocked',
            'private_network_blocked',
            'private_network_blocked',
        ], require $this->projectDir.'/extensions/http-facade/extension.php');
    }

    public function testItAllowsPrivateNetworksOnlyThroughCoreConfig(): void
    {
        $config = new Config($this->connection());
        $config->set(ExtensionHttpRequest::ALLOW_PRIVATE_NETWORKS_KEY, true, ConfigValueType::Boolean);
        $client = new MockHttpClient(new MockResponse('local-ok'));
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, httpRequests: new ExtensionHttpRequest($client, $config)));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_http_request('GET', 'http://127.0.0.1/status', null, ['allow_private_networks' => false]);
            PHP);

        $result = require $this->projectDir.'/extensions/http-facade/extension.php';

        self::assertTrue($result['ok']);
        self::assertSame('local-ok', $result['body']);
    }

    public function testItCapsResponseAndPayloadSize(): void
    {
        $client = new MockHttpClient(new MockResponse('abcdef'));
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, httpRequests: new ExtensionHttpRequest($client)));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_http_request('GET', 'https://93.184.216.34/large', null, ['max_bytes' => 3]),
                extension_http_request('POST', 'https://93.184.216.34/large', str_repeat('x', 1048577)),
            ];
            PHP);

        [$largeResponse, $largePayload] = require $this->projectDir.'/extensions/http-facade/extension.php';

        self::assertFalse($largeResponse['ok']);
        self::assertSame('response_too_large', $largeResponse['error']);
        self::assertSame('abc', $largeResponse['body']);
        self::assertSame('payload_too_large', $largePayload['error']);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/http-facade/extension.php', $contents);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(255) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(20) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at VARCHAR(32) DEFAULT NULL, modified_by VARCHAR(120) DEFAULT NULL)');

        return $connection;
    }
}
