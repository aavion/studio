<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Routing\AbsoluteUriGenerator;
use App\Database\DatabaseReadyState;
use App\Entity\AccountToken;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Mail\AccountMailFlow;
use App\Mail\MailLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
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
        private AbsoluteUriGenerator $absoluteUris,
        private AuditLoggerInterface $auditLogger,
        private MessageLoggerInterface $messageLogger,
        private string $projectDir,
        private string $secret,
        private string $environment,
        private ?DatabaseReadyState $databaseReadyState = null,
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
        if (null !== $this->databaseReadyState && !$this->databaseReadyState->isReady()) {
            return;
        }

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
        $resetLinks = $this->issueOwnerPasswordResetLinks();
        $this->storeFingerprint($fingerprints, $environmentKey, $currentFingerprint);

        $this->auditLogger->log(AccessActor::fromAccess(9, [], username: 'system'), 'security.app_secret_rotated', [
            'environment' => $this->environment,
            'api_keys_revoked' => $apiKeysRevoked,
            'password_reset_link_owners' => $resetLinks['owners'],
            'password_reset_links_issued' => $resetLinks['issued'],
            'emergency_recovery_file' => $resetLinks['emergency_recovery_file'],
        ]);

        if ([] !== $resetLinks['failed_submissions']) {
            $this->messageLogger->log(
                Message::warning(
                SecurityMessageCode::ACCOUNT_APP_SECRET_ROTATION_MANUAL_OWNER_RESET_REQUIRED,
                SecurityMessageKey::ACCOUNT_APP_SECRET_ROTATION_MANUAL_OWNER_RESET_REQUIRED,
                    ['%command%' => 'bin/setup --reset-password'],
                ),
                [
                    'component' => self::class,
                    'environment' => $this->environment,
                    'manual_command' => 'bin/setup --reset-password',
                    'emergency_recovery_file' => $resetLinks['emergency_recovery_file'],
                    'failed_submissions' => $resetLinks['failed_submissions'],
                ],
            );
        }
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

    /**
     * @return array{
     *     owners: int,
     *     issued: int,
     *     failed_submissions: list<array{user_uid: string, username: string, email: string, reason: string}>,
     *     emergency_recovery_file: string|null
     * }
     */
    private function issueOwnerPasswordResetLinks(): array
    {
        $owners = 0;
        $issued = 0;
        $failedSubmissions = [];
        $emergencyEntries = [];

        foreach ($this->entityManager->getRepository(UserAccount::class)->findBy(['status' => UserAccountStatus::Active]) as $user) {
            if (!$user instanceof UserAccount || AccessLevel::OWNER !== $user->accessLevel()) {
                continue;
            }

            ++$owners;
            [$token, $plainToken] = $this->tokenIssuer->issue(
                AccountTokenType::PasswordReset,
                $user->email(),
                [],
                $user,
                ttl: UserFlowConfig::PASSWORD_RESET_TTL,
                metadata: ['reason' => 'app_secret_rotation', 'environment' => $this->environment],
            );
            $url = $this->absoluteUris->generateUri(__METHOD__, 'user_password_reset_token', ['token' => $plainToken]);

            if (null === $url) {
                $failedSubmissions[] = $this->failedSubmission($user, 'action_url_unavailable');
                $this->persistEmergencyResetToken($user, $token);
                $emergencyEntries[] = $this->emergencyEntry($user, $token, $plainToken, 'action_url_unavailable');
                continue;
            }

            try {
                $this->linkDelivery->deliver(
                    $token,
                    AccountMailFlow::PasswordResetLink,
                    $url,
                    $this->mailLocaleResolver->forAdminAction($user),
                );
                $this->revokePendingPasswordResetTokens($user);
                $this->entityManager->persist($token);
                ++$issued;
            } catch (Throwable) {
                $failedSubmissions[] = $this->failedSubmission($user, 'delivery_failed');
                $this->persistEmergencyResetToken($user, $token);
                $emergencyEntries[] = $this->emergencyEntry($user, $token, $plainToken, 'delivery_failed', $url);
            }
        }

        $recoveryFile = [] === $emergencyEntries ? null : $this->writeEmergencyRecoveryFile($emergencyEntries);
        $this->entityManager->flush();

        return [
            'owners' => $owners,
            'issued' => $issued,
            'failed_submissions' => $failedSubmissions,
            'emergency_recovery_file' => $recoveryFile,
        ];
    }

    /**
     * @return array{user_uid: string, username: string, email: string, reason: string}
     */
    private function failedSubmission(UserAccount $user, string $reason): array
    {
        return [
            'user_uid' => $user->uid(),
            'username' => $user->username(),
            'email' => $user->email(),
            'reason' => $reason,
        ];
    }

    private function persistEmergencyResetToken(UserAccount $user, AccountToken $token): void
    {
        $this->revokePendingPasswordResetTokens($user);
        $this->entityManager->persist($token);
    }

    /**
     * @return array{
     *     user_uid: string,
     *     username: string,
     *     email: string,
     *     reason: string,
     *     token_uid: string,
     *     expires_at: string,
     *     reset_path: string,
     *     reset_url?: string
     * }
     */
    private function emergencyEntry(UserAccount $user, AccountToken $token, string $plainToken, string $reason, ?string $url = null): array
    {
        $entry = [
            'user_uid' => $user->uid(),
            'username' => $user->username(),
            'email' => $user->email(),
            'reason' => $reason,
            'token_uid' => $token->uid(),
            'expires_at' => $token->expiresAt()->format(DATE_ATOM),
            'reset_path' => '/user/reset-password/'.$plainToken,
        ];

        if (null !== $url) {
            $entry['reset_url'] = $url;
        }

        return $entry;
    }

    /**
     * @param list<array<string, string>> $entries
     */
    private function writeEmergencyRecoveryFile(array $entries): ?string
    {
        $relativePath = sprintf(
            'var/recovery/%s/app-secret-rotation-%s.json',
            $this->environmentKey(),
            gmdate('Ymd-His'),
        );
        $path = rtrim($this->projectDir, '/\\').'/'.$relativePath;
        $directory = dirname($path);

        try {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                return null;
            }

            @chmod($directory, 0700);
            $written = file_put_contents($path, json_encode([
                'created_at' => gmdate(DATE_ATOM),
                'environment' => $this->environment,
                'manual_command' => 'bin/setup --reset-password',
                'entries' => $entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if (false === $written) {
                return null;
            }

            @chmod($path, 0600);

            return $relativePath;
        } catch (Throwable) {
            return null;
        }
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
        return hash_hmac('sha256', 'system.app_secret_rotation.'.$this->environmentKey(), $this->secret);
    }

    private function environmentKey(): string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', $this->environment));

        return '' === $key ? 'default' : $key;
    }
}
