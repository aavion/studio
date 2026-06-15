<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Setup\SetupCompletionMarker;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LiveAlertControllerTest extends WebTestCase
{
    public function testItDoesNotPollAlertInboxBeforeSetupIsCompleted(): void
    {
        $setupState = $this->setEnvironment(SetupCompletionMarker::KEY, '0');

        try {
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->request('GET', '/api/live/alerts?cursor=7');

            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(7, $payload['cursor']);
            self::assertSame([], $payload['alerts']);
            self::assertSame(15000, $payload['next_poll_ms']);
        } finally {
            $this->restoreEnvironment(SetupCompletionMarker::KEY, $setupState);
            self::ensureKernelShutdown();
        }
    }

    /**
     * @return array{server_exists: bool, server: mixed, env_exists: bool, env: mixed, getenv: string|false}
     */
    private function setEnvironment(string $key, string $value): array
    {
        $state = [
            'server_exists' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
            'env_exists' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'getenv' => getenv($key),
        ];

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);

        return $state;
    }

    /**
     * @param array{server_exists: bool, server: mixed, env_exists: bool, env: mixed, getenv: string|false} $state
     */
    private function restoreEnvironment(string $key, array $state): void
    {
        if ($state['server_exists']) {
            $_SERVER[$key] = $state['server'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($state['env_exists']) {
            $_ENV[$key] = $state['env'];
        } else {
            unset($_ENV[$key]);
        }

        false === $state['getenv'] ? putenv($key) : putenv($key.'='.$state['getenv']);
    }
}
