<?php

declare(strict_types=1);

namespace App\Debug;

use App\Api\Http\ApiRequestContext;
use App\Database\DatabaseReadyState;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Repository\UserAccountRepository;
use App\Security\ApiKeyStatus;
use App\Security\UserRole;
use App\Setup\SetupCompletionMarker;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final readonly class RouteRenderer
{
    public function __construct(
        private KernelInterface $kernel,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private SessionFactoryInterface $sessionFactory,
    ) {
    }

    public function render(RouteRenderOptions $options): RouteRenderResult
    {
        $this->applySetupState($options->setupCompleted);
        $request = $this->createRequest($options);
        $user = $this->resolveUser($options);

        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return $this->renderBrowserRequest($request, $options, $user);
        }

        $previousToken = $this->tokenStorage->getToken();

        if ($user instanceof UserAccount) {
            $this->apiContext($user)->attachTo($request);
        }

        try {
            $response = $this->kernel->handle($request);

            return new RouteRenderResult(
                $response->getStatusCode(),
                (string) $response->getContent(),
                $response->headers->allPreserveCaseWithoutCookies(),
            );
        } finally {
            $this->tokenStorage->setToken($previousToken);
            if (isset($response) && method_exists($this->kernel, 'terminate')) {
                $this->kernel->terminate($request, $response);
            }
        }
    }

    private function renderBrowserRequest(Request $request, RouteRenderOptions $options, ?UserAccount $user): RouteRenderResult
    {
        $ownsTemporaryUser = $user instanceof UserAccount && null === $options->username && $options->setupCompleted;
        $transactionStarted = false;

        if ($ownsTemporaryUser) {
            $connection = $this->entityManager->getConnection();
            try {
                $connection->beginTransaction();
                $transactionStarted = true;
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                if ($transactionStarted && $connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }
        }

        $previousToken = $this->tokenStorage->getToken();

        if ($user instanceof UserAccount) {
            $this->authenticateBrowserRequest($request, $user);
        }

        try {
            $response = $this->kernel->handle($request);

            return new RouteRenderResult(
                $response->getStatusCode(),
                (string) $response->getContent(),
                $response->headers->allPreserveCaseWithoutCookies(),
            );
        } finally {
            $this->tokenStorage->setToken($previousToken);
            if (isset($response) && method_exists($this->kernel, 'terminate')) {
                $this->kernel->terminate($request, $response);
            }

            if ($transactionStarted) {
                $connection = $this->entityManager->getConnection();
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $this->entityManager->clear();
            }
        }
    }

    private function createRequest(RouteRenderOptions $options): Request
    {
        return Request::create(
            $this->normalizePath($options->path),
            strtoupper($options->method),
            [],
            [],
            [],
            [
                'HTTP_HOST' => $options->host,
                'HTTPS' => $options->secure ? 'on' : 'off',
            ],
        );
    }

    private function resolveUser(RouteRenderOptions $options): ?UserAccount
    {
        if (UserRole::Public === $options->role) {
            return null;
        }

        if (null !== $options->username && '' !== $options->username) {
            $user = $this->userRepository()->findOneBy(['username' => $options->username]);

            if (!$user instanceof UserAccount) {
                throw new RuntimeException(sprintf('Render user "%s" was not found.', $options->username));
            }

            return $user;
        }

        $username = 'render-debug-'.substr(str_replace('.', '', uniqid('', true)), 0, 16);

        return new UserAccount(
            Uuid::v7()->toRfc4122(),
            $username,
            $username.'@example.test',
            'debug-render',
            role: $options->role ?? UserRole::Owner,
        );
    }

    private function authenticateBrowserRequest(Request $request, UserAccount $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $session = $this->sessionFactory->createSession();
        $session->set('_security_main', serialize($token));
        $session->save();
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());
        $this->tokenStorage->setToken($token);
    }

    private function apiContext(UserAccount $user): ApiRequestContext
    {
        return ApiRequestContext::fromApiKey(new ApiKey(
            '00000000-0000-7000-8000-00000000a001',
            'render',
            str_repeat('0', 64),
            'debug-render-key',
            $user,
            ApiKeyStatus::ReadWrite,
        ));
    }

    private function userRepository(): UserAccountRepository
    {
        $repository = $this->entityManager->getRepository(UserAccount::class);

        if (!$repository instanceof UserAccountRepository) {
            throw new RuntimeException('User account repository is unavailable.');
        }

        return $repository;
    }

    private function applySetupState(bool $setupCompleted): void
    {
        $value = $setupCompleted ? '1' : '0';

        foreach ([SetupCompletionMarker::KEY, DatabaseReadyState::ALLOW_UNREADY_KEY] as $key) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
