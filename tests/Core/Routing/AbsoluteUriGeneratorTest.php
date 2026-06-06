<?php

declare(strict_types=1);

namespace App\Tests\Core\Routing;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Core\Routing\RoutingMessageCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AbsoluteUriGeneratorTest extends KernelTestCase
{
    public function testItGeneratesAbsoluteUrisFromConfiguredSiteUrl(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $logger = new RecordingAbsoluteUriMessageLogger();
        $generator = new AbsoluteUriGenerator($config, self::getContainer()->get(UrlGeneratorInterface::class), $logger);

        try {
            $config->set('site.url', 'https://example.test/', ConfigValueType::String, modifiedBy: 'test');

            self::assertSame(
                'https://example.test/user/reset-password/'.str_repeat('a', 64),
                $generator->generateUri('test.absolute_uri', 'user_password_reset_token', ['token' => str_repeat('a', 64)]),
            );
            self::assertSame(
                'https://external.example.test/action?token='.str_repeat('b', 64),
                $generator->generateUri('test.absolute_uri', 'https://external.example.test/action?token='.str_repeat('b', 64)),
            );
            self::assertSame([], $logger->records);
        } finally {
            $config->set('site.url', (string) $originalSiteUrl, ConfigValueType::String, modifiedBy: 'test');
        }
    }

    public function testItLogsInvalidInputsWithoutLeakingRouteParameters(): void
    {
        self::bootKernel();
        $config = self::getContainer()->get(Config::class);
        $originalSiteUrl = $config->get('site.url', 'http://localhost');
        $logger = new RecordingAbsoluteUriMessageLogger();
        $generator = new AbsoluteUriGenerator($config, self::getContainer()->get(UrlGeneratorInterface::class), $logger);
        $secretToken = str_repeat('c', 64);

        try {
            $config->set('site.url', 'not-a-url', ConfigValueType::String, modifiedBy: 'test');

            self::assertNull($generator->generateUri('test.invalid_site_url', 'user_password_reset_token', ['token' => $secretToken]));
            self::assertNull($generator->generateUri('test.invalid_route', 'missing_route_name', ['token' => $secretToken]));
        } finally {
            $config->set('site.url', (string) $originalSiteUrl, ConfigValueType::String, modifiedBy: 'test');
        }

        self::assertCount(2, $logger->records);
        self::assertSame(RoutingMessageCode::ABSOLUTE_URI_GENERATION_FAILED, $logger->records[0]['message']->code());
        self::assertSame('test.invalid_site_url', $logger->records[0]['context']['caller']);
        self::assertFalse($logger->records[0]['context']['route_invalid']);
        self::assertTrue($logger->records[0]['context']['default_uri_invalid']);
        self::assertSame('test.invalid_route', $logger->records[1]['context']['caller']);
        self::assertTrue($logger->records[1]['context']['route_invalid']);
        self::assertTrue($logger->records[1]['context']['default_uri_invalid']);
        self::assertStringNotContainsString($secretToken, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }
}

final class RecordingAbsoluteUriMessageLogger implements MessageLoggerInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(Message $message, array $context = []): void
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            $this->log($record['message'], $record['context'] ?? []);
        }
    }
}
