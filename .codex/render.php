#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Kernel;
use App\Api\Http\ApiRequestContext;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use App\Database\DatabaseReadyState;
use App\Repository\UserAccountRepository;
use App\Security\ApiKeyStatus;
use App\Security\UserRole;
use App\Setup\SetupCompletionMarker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

(new Dotenv())
    ->usePutenv()
    ->bootEnv($projectRoot . '/.env', 'APP_ENV', ['test']);

if (false !== filter_var($_SERVER['RENDER_SETUP_COMPLETED'] ?? $_ENV['RENDER_SETUP_COMPLETED'] ?? '1', FILTER_VALIDATE_BOOL)) {
    $_SERVER[SetupCompletionMarker::KEY] = '1';
    $_ENV[SetupCompletionMarker::KEY] = '1';
    putenv(SetupCompletionMarker::KEY.'=1');
    $_SERVER[DatabaseReadyState::ALLOW_UNREADY_KEY] = '1';
    $_ENV[DatabaseReadyState::ALLOW_UNREADY_KEY] = '1';
    putenv(DatabaseReadyState::ALLOW_UNREADY_KEY.'=1');
}

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $route = $argv[1] ?? '/';
    $method = $argv[2] ?? 'GET';
} else {
    $route = $_GET['route'] ?? '/';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

$env = $_SERVER['APP_ENV'] ?? 'dev';
$debug = filter_var($_SERVER['APP_DEBUG'] ?? ($env !== 'prod'), FILTER_VALIDATE_BOOL);

$kernel = new Kernel($env, $debug);

$request = Request::create(
    $route,
    $method,
    $isCli ? [] : $_GET,
    [],
    [],
    [
        'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'HTTPS' => ($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'on' : 'off',
    ],
);

$kernel->boot();
$user = renderUser($kernel, $env);
if ($user instanceof UserAccount) {
    if (str_starts_with($request->getPathInfo(), '/api/v1')) {
        renderApiContext($user)->attachTo($request);
    } else {
        authenticateRenderUser($kernel, $user);
    }
}

$response = $kernel->handle($request);

if ($isCli) {
    fwrite(STDOUT, $response->getContent());
} else {
    http_response_code($response->getStatusCode());
    foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    echo $response->getContent();
}

$kernel->terminate($request, $response);

function renderUser(Kernel $kernel, string $env): ?UserAccount
{
    $username = renderUsername();
    $role = renderRole(renderEnv('ROLE'));

    if (null !== $username && '' !== $username) {
        $user = renderUserRepository($kernel)->findOneBy(['username' => $username]);

        if (!$user instanceof UserAccount) {
            fwrite(STDERR, sprintf("Render user \"%s\" was not found.\n", $username));
            exit(1);
        }

        if (null !== $role) {
            $user->changeRole($role);
        }

        return $user;
    }

    return new UserAccount(
        '00000000-0000-7000-8000-00000000d001',
        'render-'.$env,
        'render-'.$env.'@example.test',
        'debug-render',
        role: $role ?? UserRole::Owner,
    );
}

function renderUserRepository(Kernel $kernel): UserAccountRepository
{
    $repository = renderEntityManager($kernel)->getRepository(UserAccount::class);

    if (!$repository instanceof UserAccountRepository) {
        fwrite(STDERR, "User account repository is unavailable.\n");
        exit(1);
    }

    return $repository;
}

function renderEntityManager(Kernel $kernel): EntityManagerInterface
{
    $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

    if (!$entityManager instanceof EntityManagerInterface) {
        fwrite(STDERR, "Doctrine entity manager is unavailable.\n");
        exit(1);
    }

    return $entityManager;
}

function authenticateRenderUser(Kernel $kernel, UserAccount $user): void
{
    try {
        $tokenStorage = $kernel->getContainer()->get(TokenStorageInterface::class);
    } catch (ServiceNotFoundException) {
        fwrite(STDERR, "Security token storage is unavailable in this container; only /api/v1 debug context rendering is supported.\n");
        exit(1);
    }

    if (!$tokenStorage instanceof TokenStorageInterface) {
        fwrite(STDERR, "Security token storage is unavailable.\n");
        exit(1);
    }

    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
}

function renderApiContext(UserAccount $user): ApiRequestContext
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

function renderEnv(string $name): ?string
{
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

    return is_string($value) ? trim($value) : null;
}

function renderUsername(): ?string
{
    $username = renderEnv('RENDER_USER');
    if (null !== $username && '' !== $username) {
        return $username;
    }

    $username = renderEnv('USER');
    if (null === $username || '' === $username) {
        return null;
    }

    return $username === renderOperatingSystemUsername() ? null : $username;
}

function renderOperatingSystemUsername(): ?string
{
    if (!function_exists('posix_getpwuid') || !function_exists('posix_getuid')) {
        return null;
    }

    $user = posix_getpwuid(posix_getuid());

    return is_array($user) && is_string($user['name'] ?? null) ? $user['name'] : null;
}

function renderRole(?string $value): ?UserRole
{
    if (null === $value || '' === $value) {
        return null;
    }

    $normalized = strtolower(trim($value));
    $normalized = str_starts_with($normalized, 'role_') ? substr($normalized, 5) : $normalized;

    foreach (UserRole::cases() as $role) {
        if ($role->value === $normalized) {
            return $role;
        }
    }

    fwrite(STDERR, sprintf("Render role \"%s\" is invalid.\n", $value));
    exit(1);
}
