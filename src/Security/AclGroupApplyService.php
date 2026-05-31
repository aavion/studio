<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class AclGroupApplyService
{
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AclGroupImpactService $impactService,
        private AdminUserAccessPolicy $policy,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>|null>
     */
    public function apply(string $groupUid, string $action, string $actorUid, array $payload = []): WorkflowResult
    {
        $group = $this->entityManager->find(AclGroup::class, $groupUid);
        $actor = $this->actor($actorUid);

        if (!$group instanceof AclGroup) {
            return WorkflowResult::invalid([$this->message(MessageKey::ACL_GROUP_APPLY_NOT_FOUND, ['%group%' => $groupUid], ['group_uid' => $groupUid])]);
        }

        if (!$actor instanceof AccessActor) {
            return WorkflowResult::blocked([$this->message(MessageKey::ACL_GROUP_APPLY_ACTION_INVALID, ['%group%' => $group->identifier(), '%action%' => $action], ['group_uid' => $groupUid, 'group' => $group->identifier(), 'action' => $action, 'actor_uid' => $actorUid])]);
        }

        try {
            return match ($action) {
                self::ACTION_UPDATE => $this->update($group, $actor, $payload),
                self::ACTION_DELETE => $this->delete($group, $actor),
                default => WorkflowResult::invalid([$this->message(MessageKey::ACL_GROUP_APPLY_ACTION_INVALID, ['%group%' => $group->identifier(), '%action%' => $action], ['group_uid' => $groupUid, 'group' => $group->identifier(), 'action' => $action])]),
            };
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::E_OPERATION_FAILED,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'group_uid' => $groupUid,
                        'action' => $action,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function update(AclGroup $group, AccessActor $actor, array $payload): WorkflowResult
    {
        $nameEn = $this->string($payload['name_en'] ?? null);
        $nameDe = $this->string($payload['name_de'] ?? null) ?: $nameEn;
        $minRole = (int) ($payload['min_role'] ?? -1);

        if ('' === $nameEn || null !== $this->policy->validateGroupUpdate($actor, $group, $minRole)) {
            return WorkflowResult::blocked([$this->message(MessageKey::ACL_GROUP_APPLY_UPDATE_BLOCKED, ['%group%' => $group->identifier()], ['group_uid' => $group->uid(), 'group' => $group->identifier()])]);
        }

        $impact = $this->impactService->impact($group);
        $group->rename(['en' => $nameEn, 'de' => $nameDe]);
        $floorCleanup = $this->impactService->removeBelowMinRoleReferences($group, $minRole);
        $group->changeMinRole($minRole);
        $this->entityManager->flush();

        return WorkflowResult::success([
            'group' => $group->identifier(),
            'action' => self::ACTION_UPDATE,
            'impact' => $impact['summary'],
            'floor_cleanup' => $floorCleanup,
        ], [
            'group_uid' => $group->uid(),
            'group' => $group->identifier(),
            'impact' => $impact['summary'],
            'floor_cleanup' => $floorCleanup,
        ], [
            Message::create(
                MessageCode::ACL_GROUP_UPDATED,
                MessageKey::ACL_GROUP_UPDATED,
                $this->summaryParameters($group->identifier(), $impact['summary']),
                ['group_uid' => $group->uid(), 'impact' => $impact['summary']],
                MessageLevel::Success,
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function delete(AclGroup $group, AccessActor $actor): WorkflowResult
    {
        if (null !== $this->policy->validateGroupDelete($actor, $group)) {
            return WorkflowResult::blocked([$this->message(MessageKey::ACL_GROUP_APPLY_DELETE_BLOCKED, ['%group%' => $group->identifier()], ['group_uid' => $group->uid(), 'group' => $group->identifier()])]);
        }

        $impact = $this->impactService->removeReferences($group);
        $identifier = $group->identifier();
        $groupUid = $group->uid();
        $this->entityManager->remove($group);
        $this->entityManager->flush();

        return WorkflowResult::success([
            'group' => $identifier,
            'action' => self::ACTION_DELETE,
            'impact' => $impact['summary'],
        ], [
            'group_uid' => $groupUid,
            'group' => $identifier,
            'impact' => $impact['summary'],
        ], [
            Message::create(
                MessageCode::ACL_GROUP_DELETED,
                MessageKey::ACL_GROUP_DELETED,
                $this->summaryParameters($identifier, $impact['summary']),
                ['group_uid' => $groupUid, 'impact' => $impact['summary']],
                MessageLevel::Success,
            ),
        ]);
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, mixed>  $context
     */
    private function message(string $translationKey, array $parameters, array $context): Message
    {
        return Message::warning(
            MessageCode::ACL_GROUP_APPLY_BLOCKED,
            $translationKey,
            $parameters,
            $context,
        );
    }

    /**
     * @param array<string, int> $summary
     *
     * @return array<string, string>
     */
    private function summaryParameters(string $group, array $summary): array
    {
        return [
            '%group%' => $group,
            '%users%' => (string) ($summary['users'] ?? 0),
            '%account_tokens%' => (string) ($summary['account_tokens'] ?? 0),
            '%content_items%' => (string) ($summary['content_items'] ?? 0),
            '%content_schema_versions%' => (string) ($summary['content_schema_versions'] ?? 0),
            '%site_menu_items%' => (string) ($summary['site_menu_items'] ?? 0),
        ];
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function actor(string $actorUid): ?AccessActor
    {
        $user = $this->entityManager->find(UserAccount::class, $actorUid);

        return $user instanceof UserAccount && $user->status()->isUsable()
            ? AccessActor::fromUserAccount($user)
            : null;
    }
}
