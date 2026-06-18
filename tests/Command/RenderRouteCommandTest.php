<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RenderRouteCommand;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Debug\RouteRenderer;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RenderRouteCommandTest extends KernelTestCase
{
    public function testItRendersPublicRoutesFromTheConsole(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute(['path' => '/user/login', '--role' => 'public']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Sign in', $tester->getDisplay());
    }

    public function testItIsBlockedInProductionEnvironment(): void
    {
        self::bootKernel();
        $command = new RenderRouteCommand(self::getContainer()->get(RouteRenderer::class), 'prod');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['path' => '/user/login']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('render:route command is available only', $tester->getDisplay());
        self::assertStringContainsString('environments', $tester->getDisplay());
    }

    public function testItRendersProtectedRoutesWithDebugRoleContext(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute(['path' => '/admin', '--role' => 'owner']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Admin dashboard', $tester->getDisplay());
    }

    public function testItRendersApiRoutesWithDebugApiContext(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute(['path' => '/api/v1/user', '--role' => 'owner']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"type":"user_profile"', $tester->getDisplay());
    }

    public function testItRejectsInvalidSyntheticHeaders(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute([
            'path' => '/user/login',
            '--header' => ["X-Test: ok\nInjected: nope"],
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('unsupported control characters', $tester->getDisplay());
    }

    public function testCronRunRenderEnforcesSchedulerRateLimitForApiKeyContext(): void
    {
        self::bootKernel();
        $this->setRateLimitMode(RateLimitProfile::Standard);
        $command = self::getContainer()->get(RenderRouteCommand::class);

        $first = new CommandTester($command);
        $firstExit = $first->execute($this->cronRenderInput());

        self::assertSame(Command::SUCCESS, $firstExit);
        self::assertStringContainsString('HTTP 200', $first->getDisplay());

        $second = new CommandTester($command);
        $secondExit = $second->execute($this->cronRenderInput());
        $display = $second->getDisplay();

        self::assertSame(Command::SUCCESS, $secondExit);
        self::assertStringContainsString('HTTP 429', $display);
        self::assertStringContainsString('Retry-After:', $display);
        self::assertStringContainsString('Cache-Control:', $display);
        self::assertStringContainsString('no-store', $display);
        self::assertStringContainsString('"code":"rate_limit.exceeded"', $display);
    }

    public function testItDoesNotOverrideExistingUserRoles(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute(['path' => '/admin', '--user' => 'owner', '--role' => 'admin']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('The --role option cannot override an existing --user role.', $tester->getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    private function cronRenderInput(): array
    {
        return [
            'path' => '/cron/run',
            '--role' => 'public',
            '--include-status' => true,
            '--include-headers' => true,
            '--header' => [
                'Authorization: Bearer test_seed_read_write_key',
                'X-Rate-Limit-Testing: 1',
            ],
        ];
    }

    private function setRateLimitMode(RateLimitProfile $profile): void
    {
        $cache = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $config = self::getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        $config->set(RateLimitPolicyCatalogue::MODE_KEY, $profile->value, ConfigValueType::String, modifiedBy: 'test');
    }
}
