<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AccountMailFlow;
use App\Security\AccountMailFlowRegistry;
use PHPUnit\Framework\TestCase;

final class AccountMailFlowRegistryTest extends TestCase
{
    public function testItDefinesEveryAccountMailFlow(): void
    {
        $registry = new AccountMailFlowRegistry();
        $definitions = $registry->definitions();

        self::assertCount(count(AccountMailFlow::cases()), $definitions);

        foreach (AccountMailFlow::cases() as $flow) {
            $definition = $registry->definition($flow);

            self::assertSame($flow, $definition->flow());
            self::assertSame($flow->value, $definition->templateKey());
            self::assertNotSame([], $definition->requiredParameters());

            foreach ($definition->parameterKeys() as $parameter) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $parameter);
            }
        }
    }

    public function testItExposesExpectedTemplateParameters(): void
    {
        $registry = new AccountMailFlowRegistry();

        self::assertSame(
            ['email', 'username'],
            $registry->definition(AccountMailFlow::RegistrationExistingAccount)->parameterKeys(),
        );
        self::assertSame(
            ['email', 'username', 'action_url', 'expires_at'],
            $registry->definition(AccountMailFlow::PasswordChanged)->parameterKeys(),
        );
        self::assertSame(
            ['email', 'username', 'user_uid'],
            $registry->definition(AccountMailFlow::PasswordChangeDisputed)->parameterKeys(),
        );
    }
}
