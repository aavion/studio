<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Message\MessageCode;
use App\Core\Operation\Process\PhpCliUnavailableAction;
use PHPUnit\Framework\TestCase;

final class PhpCliUnavailableActionTest extends TestCase
{
    public function testItFailsWithDiagnosticContext(): void
    {
        $action = new PhpCliUnavailableAction('cache:clear', 'binary_not_found', ['environment' => 'test']);

        $result = $action->execute();

        self::assertFalse($result->isSuccess());
        self::assertSame('php_cli_unavailable', $action->type());
        self::assertSame('Resolve PHP CLI for cache:clear', $action->label());
        self::assertSame('binary_not_found', $result->context()['php_cli_reason']);
        self::assertSame('test', $result->context()['environment']);
        self::assertSame(MessageCode::PROCESS_PHP_CLI_UNAVAILABLE, $result->issues()[0]->code());
    }
}
