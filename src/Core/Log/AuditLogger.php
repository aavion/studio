<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Access\AccessActor;
use App\Core\Statistics\VisitorIdGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AuditLogger implements AuditLoggerInterface
{
    private const REDACTED = '[redacted]';

    public function __construct(
        private LoggerInterface $logger,
        private ?AuditLogPolicyInterface $policy = null,
        private ?RequestStack $requestStack = null,
        private ?AccessRequestMetadata $accessRequestMetadata = null,
        private ?VisitorIdGenerator $visitorIdGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(AccessActor $actor, string $action, array $context = []): void
    {
        if (null !== $this->policy && !$this->policy->allows($action)) {
            return;
        }

        $this->logger->info($action, [
            'user' => $actor->username() ?? 'anonymous',
            'user_uid' => $actor->userUid(),
            'user_access_level' => $actor->accessLevel(),
            'action' => $action,
            'context' => $this->normalize($this->withRequestTrace($context)),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function withRequestTrace(array $context): array
    {
        if (null === $this->requestStack || null === $this->accessRequestMetadata || null === $this->visitorIdGenerator) {
            return $context;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return $context;
        }

        return [
            ...$context,
            ...$this->accessRequestMetadata->trace($request, $this->visitorIdGenerator->generate($request)),
        ];
    }

    private function normalize(mixed $value, string $key = ''): mixed
    {
        if ('' !== $key && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, is_string($key) ? $key : '');
            }

            return $normalized;
        }

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $key));

        return 1 === preg_match('/(?:password|secret|token|credential|authorization|cookie|hmac|encrypted|api_key|private_key|license_key)/', $normalized);
    }
}
