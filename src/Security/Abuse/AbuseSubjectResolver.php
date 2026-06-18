<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Api\Http\ApiRequestContext;
use App\Core\Routing\PathScopeMatcher;
use App\Core\Routing\RequestPathResolver;
use App\Core\Statistics\VisitorIdGenerator;
use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use App\Security\AccessLevelAwareUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class AbuseSubjectResolver
{
    private const PLACEHOLDER = 'n/a';
    private RequestPathResolver $paths;
    private PathScopeMatcher $rawPaths;

    public function __construct(
        private VisitorIdGenerator $visitorIdGenerator,
        private TokenStorageInterface $tokenStorage,
        private string $secret,
        ?RequestPathResolver $paths = null,
        ?PathScopeMatcher $rawPaths = null,
    ) {
        $this->paths = $paths ?? new RequestPathResolver();
        $this->rawPaths = $rawPaths ?? new PathScopeMatcher();
    }

    public function resolve(Request $request): AbuseSubjectResolution
    {
        $subjects = [];
        $visitorId = $this->visitorIdGenerator->generate($request);
        $subjects[] = new AbuseSubject(AbuseSubjectType::Visitor, $visitorId);

        $sourceIp = $this->visitorIdGenerator->sourceIp($request);
        if (self::PLACEHOLDER !== $sourceIp) {
            $subjects[] = new AbuseSubject(AbuseSubjectType::IpBucket, $this->bucket('ip', $sourceIp), true);
            $subjects[] = new AbuseSubject(AbuseSubjectType::Combined, $this->bucket('visitor_ip', $visitorId.'|'.$sourceIp), true, [
                'scope' => 'visitor_ip',
            ]);
        }

        $user = $this->currentUser($request);
        if ($user instanceof UserAccount) {
            $subjects[] = new AbuseSubject(AbuseSubjectType::User, $user->uid(), false, [
                'access_level' => $user->accessLevel(),
            ]);
            $subjects[] = new AbuseSubject(AbuseSubjectType::Combined, $this->bucket('user_visitor', $user->uid().'|'.$visitorId), false, [
                'scope' => 'user_visitor',
            ]);
        }

        $apiContext = ApiRequestContext::fromRequest($request);
        if ($apiContext instanceof ApiRequestContext && null !== $apiContext->apiKeyUid()) {
            $subjects[] = new AbuseSubject(AbuseSubjectType::ApiKey, $apiContext->apiKeyUid(), false, [
                'prefix' => $apiContext->apiKeyPrefix(),
                'status' => $apiContext->apiKeyStatus()?->value,
            ]);
            $subjects[] = new AbuseSubject(AbuseSubjectType::Combined, $this->bucket('api_visitor', $apiContext->apiKeyUid().'|'.$visitorId), false, [
                'scope' => 'api_visitor',
            ]);
        } else {
            $prefix = $this->submittedApiKeyPrefix($request);
            if (null !== $prefix) {
                $subjects[] = new AbuseSubject(AbuseSubjectType::ApiKeyPrefix, $prefix);
            }
        }

        $schedulerCredential = $this->submittedSchedulerCredential($request);
        if ($schedulerCredential instanceof AbuseSubject) {
            $subjects[] = $schedulerCredential;
        }

        $submittedAccount = $this->submittedAccount($request);
        if ($submittedAccount instanceof AbuseSubject) {
            $subjects[] = $submittedAccount;
        }

        return new AbuseSubjectResolution($subjects);
    }

    private function currentUser(Request $request): ?UserAccount
    {
        $apiUser = ApiRequestContext::fromRequest($request)?->user();
        if ($apiUser instanceof UserAccount) {
            return $apiUser;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof UserAccount ? $user : null;
    }

    private function submittedApiKeyPrefix(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');
        if (!is_string($authorization) || 1 !== preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        $dotPosition = strpos($token, '.');
        $prefix = false === $dotPosition ? $token : substr($token, 0, $dotPosition);

        return 1 === preg_match('/^[A-Za-z0-9_-]{4,16}$/', $prefix) ? $prefix : null;
    }

    private function submittedSchedulerCredential(Request $request): ?AbuseSubject
    {
        if (!$this->rawPaths->matchesExactSegments($request->getPathInfo(), 'cron', 'run')) {
            return null;
        }

        $authorization = $request->headers->get('Authorization');
        if (is_string($authorization) && 1 === preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return $this->schedulerCredentialSubject(trim($matches[1]));
        }

        $auth = $this->scalarQueryValue($request, 'auth');

        return is_string($auth) ? $this->schedulerCredentialSubject(trim($auth)) : null;
    }

    private function schedulerCredentialSubject(string $token): ?AbuseSubject
    {
        if ('' === $token) {
            return null;
        }

        return new AbuseSubject(AbuseSubjectType::SchedulerCredential, $this->bucket('scheduler_credential', substr($token, 0, 128)));
    }

    private function submittedAccount(Request $request): ?AbuseSubject
    {
        $segments = $this->paths->segments($request);
        $route = $request->attributes->get('_route');
        $token = $request->attributes->get('token');

        if ('user_invitation_accept' === $route) {
            return $this->submittedTokenSubject('registration_token', $token);
        }

        if ('user_password_reset_token' === $route) {
            return $this->submittedTokenSubject('password_reset_token', $token);
        }

        if ('user_security_review' === $route) {
            return $this->submittedTokenSubject('security_review_token', $token);
        }

        if ($this->matchesExactSegments($segments, 'user', 'login')) {
            return $this->submittedAccountSubject('login', $this->scalarRequestValue($request, 'username'));
        }

        if ($this->matchesExactSegments($segments, 'user', 'register')) {
            return $this->submittedAccountSubject('registration_email', $this->scalarRequestValue($request, 'email'), email: true);
        }

        if ($this->matchesSegments($segments, 'user', 'invitation') && null !== ($submittedToken = $this->tokenSegment($segments, 2))) {
            return $this->submittedAccountSubject('registration_token', $submittedToken);
        }

        if ($this->matchesExactSegments($segments, 'user', 'reset-password')) {
            return $this->submittedAccountSubject('password_reset_email', $this->scalarRequestValue($request, 'email'), email: true);
        }

        if ($this->matchesSegments($segments, 'user', 'reset-password') && null !== ($submittedToken = $this->tokenSegment($segments, 2))) {
            return $this->submittedAccountSubject('password_reset_token', $submittedToken);
        }

        if ($this->matchesSegments($segments, 'user', 'security-review') && null !== ($submittedToken = $this->tokenSegment($segments, 2))) {
            return $this->submittedAccountSubject('security_review_token', $submittedToken);
        }

        return null;
    }

    private function submittedTokenSubject(string $scope, mixed $token): ?AbuseSubject
    {
        if (!is_string($token) || 1 !== preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        return $this->submittedAccountSubject($scope, $token);
    }

    /**
     * @param list<string> $segments
     */
    private function matchesSegments(array $segments, string ...$expected): bool
    {
        foreach ($expected as $index => $segment) {
            if (($segments[$index] ?? null) !== $segment) {
                return false;
            }
        }

        return [] !== $expected;
    }

    /**
     * @param list<string> $segments
     */
    private function matchesExactSegments(array $segments, string ...$expected): bool
    {
        return count($segments) === count($expected) && $this->matchesSegments($segments, ...$expected);
    }

    /**
     * @param list<string> $segments
     */
    private function tokenSegment(array $segments, int $index): ?string
    {
        $token = $segments[$index] ?? null;

        return is_string($token) && count($segments) === $index + 1 && 1 === preg_match('/^[a-f0-9]{64}$/i', $token) ? $token : null;
    }

    private function submittedAccountSubject(string $scope, mixed $value, bool $email = false): ?AbuseSubject
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = $email ? EmailAddress::normalize($normalized) : strtolower($normalized);
        $normalized = substr($normalized, 0, 190);
        if ('' === $normalized) {
            return null;
        }

        return new AbuseSubject(AbuseSubjectType::SubmittedAccount, $this->bucket($scope, $normalized), false, [
            'scope' => $scope,
        ]);
    }

    private function scalarRequestValue(Request $request, string $name): mixed
    {
        $value = $request->request->all()[$name] ?? null;

        return is_scalar($value) ? $value : null;
    }

    private function scalarQueryValue(Request $request, string $name): mixed
    {
        $value = $request->query->all()[$name] ?? null;

        return is_scalar($value) ? $value : null;
    }

    private function bucket(string $scope, string $value): string
    {
        return substr(hash_hmac('sha256', 'abuse.subject.'.$scope.'|'.$value, $this->secret), 0, 40);
    }
}
