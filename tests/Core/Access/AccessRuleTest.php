<?php

declare(strict_types=1);

namespace App\Tests\Core\Access;

use App\Core\Access\AccessLevel;
use App\Core\Access\AccessMessageKey;
use App\Core\Access\AccessRule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccessRuleTest extends TestCase
{
    public function testItNormalizesGroupIdentifiersForStoredRules(): void
    {
        self::assertSame(
            ['project_team', 'reviewers'],
            AccessRule::normalizeGroupIdentifiersOrNull(['reviewers', 'project_team', 'project_team']),
        );
    }

    public function testItPreservesNullStoredGroupIdentifiers(): void
    {
        self::assertNull(AccessRule::normalizeGroupIdentifiersOrNull(null));
    }

    public function testItStillBuildsRuntimeRules(): void
    {
        $rule = AccessRule::from(AccessLevel::AUTHOR, ['project_team']);

        self::assertSame(AccessLevel::AUTHOR, $rule->minLevel());
        self::assertSame(['project_team'], $rule->groupIdentifiers());
        self::assertFalse($rule->isInherited());
    }

    public function testItRejectsInvalidStoredGroupIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AccessMessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        AccessRule::normalizeGroupIdentifiersOrNull(['Project Team']);
    }
}
