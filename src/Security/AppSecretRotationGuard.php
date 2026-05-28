<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\AccountToken;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class AppSecretRotationGuard implements EventSubscriberInterface
{
    public const FINGERPRINTS_KEY = 'security.app_secret_fingerprints';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Config $config,
        private AccountTokenIssuer $tokenIssuer,
        private AccountLinkDeliveryInterface $linkDelivery,
        private MailLocaleResolver $mailLocaleResolver,
        private UrlGeneratorInterface $urlGenerator,
        private AuditLoggerInterface $auditLogger,
        private string $secret,
        private string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 240],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->handle();
    }

    public function handle(): void
    {
        if (!$this->schemaReady()) {
            return;
        }

        $fingerprints = $this->fingerprints();
        $environmentKey = $this->environmentKey();
        $currentFingerprint = $this->fingerprint();
        $previousFingerprint = $fingerprints[$environmentKey] ?? null;

        if (!is_string($previousFingerprint) || '' === $previousFingerprint) {
            $this->storeFingerprint($fingerprints, $environmentKey, $currentFingerprint);

            return;
        }

        if (hash_equals($previousFingerprint, $currentFingerprint)) {
            return;
        }

        $apiKeysRevoked = $this->revokeActiveApiKeys();
        $resetLinksIssued = $this->issueOwnerPasswordResetLinks();
        $this->storeFingerprint($fingerprints, $environmentKey, $currentFingerprint);
        $this->auditLogger->log(AccessActor::fromAccess(9, [], username: 'system'), 'security.app_secret_rotated', [
            'environment' => $this->environment,
            'api_keys_revoked' => $apiKeysRevoked,
            'password_reset_links_issued' => $resetLinksIssued,
        ]);
    }

    private function schemaReady(): bool
    {
        try {
            $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM config_entry LIMIT 1');
            $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM api_key LIMIT 1');
            $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM user_account LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fingerprints(): array
    {
        $value = $this->config->get(self::FINGERPRINTS_KEY, []);

        if (!is_array($value)) {
            return [];
        }

        $fingerprints = [];

        foreach ($value as $environment => $fingerprint) {
            if (is_string($environment) && is_string($fingerprint)) {
                $fingerprints[$environment] = $fingerprint;
            }
        }

        return $fingerprints;
    }

    /**
     * @param array<string, string> $fingerprints
     */
    private function storeFingerprint(array $fingerprints, string $environmentKey, string $fingerprint): void
    {
        $fingerprints[$environmentKey] = $fingerprint;
        $this->config->set(self::FINGERPRINTS_KEY, $fingerprints, ConfigValueType::Json, sensitive: true, modifiedBy: 'system');
    }

    private function revokeActiveApiKeys(): int
    {
        $apiKeys = $this->entityManager->getRepository(ApiKey::class)->findBy([
            'status' => [ApiKeyStatus::ReadOnly, ApiKeyStatus::ReadWrite],
        ]);
        $count = 0;

        foreach ($apiKeys as $apiKey) {
            if (!$apiKey instanceof ApiKey) {
                continue;
            }

            $apiKey->revoke();
            ++$count;
        }

        return $count;
    }

    private function issueOwnerPasswordResetLinks(): int
    {
        $count = 0;

        foreach ($this->entityManager->getRepository(UserAccount::class)->findBy(['status' => UserAccountStatus::Active]) as $user) {
            if (!$user instanceof UserAccount || 9 !== $user->maxAccessLevel()) {
                continue;
            }

            $this->revokePendingPasswordResetTokens($user);
            [$token, $plainToken] = $this->tokenIssuer->issue(
                AccountTokenType::PasswordReset,
                $user->email(),
                [],
                $user,
                ttl: UserFlowConfig::PASSWORD_RESET_TTL,
                metadata: ['reason' => 'app_secret_rotation', 'environment' => $this->environment],
            );
            $this->entityManager->persist($token);
            $this->linkDelivery->deliver(
                $token,
                AccountMailFlow::PasswordResetLink,
                $plainToken,
                $this->urlGenerator->generate('user_password_reset_token', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->mailLocaleResolver->forAdminAction($user),
            );
            ++$count;
        }

        $this->entityManager->flush();

        return $count;
    }

    private function revokePendingPasswordResetTokens(UserAccount $user): void
    {
        $tokens = $this->entityManager->getRepository(AccountToken::class)->findBy([
            'user' => $user,
            'type' => AccountTokenType::PasswordReset,
            'status' => AccountTokenStatus::Pending,
        ]);

        foreach ($tokens as $token) {
            if ($token instanceof AccountToken) {
                $token->revoke();
            }
        }
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', 'studio.app_secret_rotation.'.$this->environmentKey(), $this->secret);
    }

    private function environmentKey(): string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $this->environment));

        return '' === $key ? 'default' : $key;
    }
}
