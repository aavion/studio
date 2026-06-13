<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RenderRouteCommand;
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

    public function testItDoesNotOverrideExistingUserRoles(): void
    {
        self::bootKernel();
        $tester = new CommandTester(self::getContainer()->get(RenderRouteCommand::class));

        $exitCode = $tester->execute(['path' => '/admin', '--user' => 'owner', '--role' => 'admin']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('The --role option cannot override an existing --user role.', $tester->getDisplay());
    }
}
