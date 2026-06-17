<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Api\Http\ApiRequestContext;
use App\Core\Statistics\VisitorIdGenerator;
use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use App\Security\AccessLevelAwareUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class AbuseSubjectResolver
{
    private const PLACEHOLDER = 'n/a';

    public function __construct(
        private VisitorIdGenerator $visitorIdGenerator,
        private TokenStorageInterface $tokenStorage,
        private string $secret,
    ) {
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

    private function submittedAccount(Request $request): ?AbuseSubject
    {
        $path = rtrim($request->getPathInfo(), '/') ?: '/';

        if ('/user/login' === $path) {
            return $this->submittedAccountSubject('login', $request->request->get('username'));
        }

        if ('/user/register' === $path) {
            return $this->submittedAccountSubject('registration_email', $request->request->get('email'), email: true);
        }

        if ('/user/reset-password' === $path) {
            return $this->submittedAccountSubject('password_reset_email', $request->request->get('email'), email: true);
        }

        return null;
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

    private function bucket(string $scope, string $value): string
    {
        return substr(hash_hmac('sha256', 'abuse.subject.'.$scope.'|'.$value, $this->secret), 0, 40);
    }
}
