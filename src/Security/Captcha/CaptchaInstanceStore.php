<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Core\Id\UuidFactory;
use App\Core\Statistics\VisitorIdGenerator;
use App\Core\Validation\IdentifierSpec;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

final readonly class CaptchaInstanceStore
{
    public const INSTANCE_FIELD = '_captcha_instance';
    public const TTL_SECONDS = 3600;

    private const CACHE_PREFIX = 'system.captcha.instance.';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private VisitorIdGenerator $visitorIds,
        private LockFactory $lockFactory,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    public function create(Request $request, string $workflow, string $formId, string $fieldName): CaptchaInstanceEntry
    {
        $entry = new CaptchaInstanceEntry(
            $this->uuidFactory->generate(),
            $this->visitorIds->generate($request),
            $workflow,
            $formId,
            $fieldName,
            time(),
        );

        $item = $this->cache->getItem($this->key($entry->id()));
        $item->expiresAfter(self::TTL_SECONDS);
        $item->set([
            'id' => $entry->id(),
            'visitor_id' => $entry->visitorId(),
            'workflow' => $entry->workflow(),
            'form_id' => $entry->formId(),
            'field_name' => $entry->fieldName(),
            'created_at' => $entry->createdAt(),
        ]);
        $this->cache->save($item);

        return $entry;
    }

    public function consume(?string $instanceId): ?CaptchaInstanceEntry
    {
        if (!is_string($instanceId) || !IdentifierSpec::isCanonicalUuid($instanceId)) {
            return null;
        }

        $key = $this->key($instanceId);
        $lock = $this->lockFactory->createLock($key.'.lock', 5.0);

        try {
            if (!$lock->acquire()) {
                return null;
            }

            $item = $this->cache->getItem($key);
            if (!$item->isHit()) {
                return null;
            }

            $payload = $item->get();
            $this->cache->deleteItem($key);
        } finally {
            $lock->release();
        }

        if (!is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        $visitorId = $payload['visitor_id'] ?? null;
        $workflow = $payload['workflow'] ?? null;
        $formId = $payload['form_id'] ?? null;
        $fieldName = $payload['field_name'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        if (
            !is_string($id)
            || !IdentifierSpec::isCanonicalUuid($id)
            || $id !== $instanceId
            || !is_string($visitorId)
            || '' === $visitorId
            || !is_string($workflow)
            || '' === $workflow
            || !is_string($formId)
            || '' === $formId
            || !is_string($fieldName)
            || '' === $fieldName
            || !is_int($createdAt)
        ) {
            return null;
        }

        return new CaptchaInstanceEntry($id, $visitorId, $workflow, $formId, $fieldName, $createdAt);
    }

    public function matchesVisitor(Request $request, CaptchaInstanceEntry $entry): bool
    {
        return hash_equals($entry->visitorId(), $this->visitorIds->generate($request));
    }

    private function key(string $instanceId): string
    {
        return self::CACHE_PREFIX.$instanceId;
    }
}
