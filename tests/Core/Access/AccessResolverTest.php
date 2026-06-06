<?php

declare(strict_types=1);

namespace App\Tests\Core\Access;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessLevel;
use App\Core\Access\AccessMessageCode;
use App\Core\Access\AccessMessageKey;
use App\Core\Access\AccessResolver;
use App\Core\Access\AccessRule;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageReporterInterface;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\UserRole;
use App\Tests\Support\NullMessageReporter;
use PHPUnit\Framework\TestCase;

final class AccessResolverTest extends TestCase
{
    public function testItUsesDefaultCapabilityLevelsWhenAllRulesInherit(): void
    {
        $resolver = new AccessResolver(new NullMessageReporter());
        $actor = AccessActor::anonymous();

        $viewDecision = $resolver->decide($actor, AccessCapability::View, AccessRule::inherit());
        $editDecision = $resolver->decide($actor, AccessCapability::Edit, AccessRule::inherit());

        self::assertTrue($viewDecision->isGranted());
        self::assertSame('default', $viewDecision->ruleSource());
        self::assertSame(AccessMessageCode::ACCESS_GRANTED, $viewDecision->message()->code());
        self::assertSame(AccessMessageKey::ACCESS_GRANTED, $viewDecision->message()->translationKey());
        self::assertSame(MessageLevel::Debug, $viewDecision->message()->level());

        self::assertFalse($editDecision->isGranted());
        self::assertSame(AccessLevel::AUTHOR, $editDecision->rule()->minLevel());
        self::assertSame(AccessMessageCode::ACCESS_DENIED, $editDecision->message()->code());
        self::assertSame(MessageLevel::Warning, $editDecision->message()->level());
    }

    public function testItGrantsAccessByMinimumLevelOrExplicitGroupMembership(): void
    {
        $resolver = new AccessResolver(new NullMessageReporter());
        $editor = AccessActor::fromAccess(AccessLevel::AUTHOR);
        $projectMember = AccessActor::fromAccess(AccessLevel::PUBLIC, ['project_team']);
        $anonymous = AccessActor::anonymous();
        $rule = AccessRule::from(AccessLevel::MANAGER, ['project_team']);

        self::assertFalse($resolver->decide($editor, AccessCapability::Manage, $rule)->isGranted());
        self::assertTrue($resolver->decide($projectMember, AccessCapability::Manage, $rule)->isGranted());
        self::assertFalse($resolver->decide($anonymous, AccessCapability::Manage, $rule)->isGranted());
    }

    public function testNearestExplicitRuleWinsBeforeInheritedFallbacks(): void
    {
        $resolver = new AccessResolver(new NullMessageReporter());
        $manager = AccessActor::fromAccess(AccessLevel::MANAGER);

        $decision = $resolver->decide(
            $manager,
            AccessCapability::View,
            AccessRule::inherit(),
            AccessRule::from(AccessLevel::ADMIN),
            AccessRule::from(AccessLevel::PUBLIC),
        );

        self::assertFalse($decision->isGranted());
        self::assertSame('rule_1', $decision->ruleSource());
        self::assertSame(AccessLevel::ADMIN, $decision->rule()->minLevel());
    }

    public function testEmptyRuleWithoutLevelStillInherits(): void
    {
        $resolver = new AccessResolver(new NullMessageReporter());
        $anonymous = AccessActor::anonymous();

        $decision = $resolver->decide($anonymous, AccessCapability::View, AccessRule::from(null, []));

        self::assertTrue($decision->isGranted());
        self::assertSame('default', $decision->ruleSource());
    }

    public function testItBuildsActorsFromUserAccounts(): void
    {
        $contentAuthorGroup = new AclGroup('11111111-1111-7111-8111-111111111111', 'content_authors', 'Content authors', AccessLevel::AUTHOR);
        $projectGroup = new AclGroup('22222222-2222-7222-8222-222222222222', 'project_team', 'Project', AccessLevel::PUBLIC);
        $adminGroup = new AclGroup('44444444-4444-7444-8444-444444444444', 'admin_room', 'Admin room', AccessLevel::ADMIN);
        $user = new UserAccount('33333333-3333-7333-8333-333333333333', 'dominik', 'dom@example.test', 'hash', role: UserRole::Author);
        $user->addGroup($projectGroup);
        $user->addGroup($contentAuthorGroup);
        $user->addGroup($adminGroup);

        $actor = AccessActor::fromUserAccount($user);

        self::assertSame(AccessLevel::AUTHOR, $actor->accessLevel());
        self::assertSame(['content_authors', 'project_team'], $actor->groupIdentifiers());
        self::assertTrue($actor->hasGroupIdentifier('project_team'));
        self::assertFalse($actor->hasGroupIdentifier('admin_room'));
    }

    public function testItReportsDecisionMessagesWhenReporterIsAvailable(): void
    {
        $reporter = new RecordingAccessMessageReporter();
        $resolver = new AccessResolver($reporter);

        $decision = $resolver->decide(AccessActor::anonymous(), AccessCapability::Edit, AccessRule::inherit());

        self::assertFalse($decision->isGranted());
        self::assertCount(1, $reporter->records);
        self::assertSame($decision->message(), $reporter->records[0]['message']);
        self::assertSame(['source' => 'access_resolver'], $reporter->records[0]['context']);
    }
}

final class RecordingAccessMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $messages[] = $this->report($record['message'], $record['context'] ?? []);
        }

        return $messages;
    }
}
