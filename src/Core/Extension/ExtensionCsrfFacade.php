<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

final readonly class ExtensionCsrfFacade
{
    public function __construct(
        private CsrfTokenManagerInterface $tokens,
        private ?RequestStack $requestStack = null,
    ) {
    }

    public function token(string $extensionName, string $intent): string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName) || !$this->validIntent($intent)) {
            return '';
        }

        try {
            return (string) $this->tokens->getToken($this->tokenId($extensionName, $intent));
        } catch (Throwable) {
            return '';
        }
    }

    public function valid(string $extensionName, string $intent, ?string $token = null): bool
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName) || !$this->validIntent($intent)) {
            return false;
        }

        $token ??= $this->requestToken();
        if (!is_string($token) || '' === trim($token)) {
            return false;
        }

        try {
            return $this->tokens->isTokenValid(new CsrfToken($this->tokenId($extensionName, $intent), $token));
        } catch (Throwable) {
            return false;
        }
    }

    private function tokenId(string $extensionName, string $intent): string
    {
        return 'extension:'.$extensionName.':'.trim($intent);
    }

    private function validIntent(string $intent): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/', trim($intent));
    }

    private function requestToken(): ?string
    {
        $request = $this->requestStack?->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        foreach (['_csrf_token', 'csrf_token', '_token'] as $field) {
            $token = $request->request->get($field, $request->query->get($field));
            if (is_string($token) && '' !== trim($token)) {
                return $token;
            }
        }

        return null;
    }
}
