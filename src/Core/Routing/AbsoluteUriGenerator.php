<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Config\Config;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class AbsoluteUriGenerator
{
    public function __construct(
        private Config $config,
        private UrlGeneratorInterface $urlGenerator,
        private MessageLoggerInterface $messageLogger,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generateUri(string $caller, string $routeOrUri, array $parameters = []): ?string
    {
        if ($this->isAbsoluteUri($routeOrUri)) {
            return $this->validUri($routeOrUri)
                ? $routeOrUri
                : $this->fail($caller, routeInvalid: true, defaultUriInvalid: false, absoluteInput: true);
        }

        $siteUrl = $this->siteUrl();

        try {
            $path = $this->urlGenerator->generate($routeOrUri, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (Throwable) {
            return $this->fail($caller, routeInvalid: true, defaultUriInvalid: null === $siteUrl, absoluteInput: false);
        }

        if (null === $siteUrl) {
            return $this->fail($caller, routeInvalid: false, defaultUriInvalid: true, absoluteInput: false);
        }

        $uri = rtrim($siteUrl, '/').$path;

        return $this->validUri($uri)
            ? $uri
            : $this->fail($caller, routeInvalid: true, defaultUriInvalid: true, absoluteInput: false);
    }

    private function siteUrl(): ?string
    {
        $siteUrl = $this->config->get('site.url', null);

        if (!is_string($siteUrl)) {
            return null;
        }

        $siteUrl = rtrim(trim($siteUrl), '/');

        return $this->validUri($siteUrl) ? $siteUrl : null;
    }

    private function isAbsoluteUri(string $value): bool
    {
        return null !== parse_url($value, PHP_URL_SCHEME);
    }

    private function validUri(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && '' !== $host
            && false !== filter_var($value, FILTER_VALIDATE_URL);
    }

    private function fail(string $caller, bool $routeInvalid, bool $defaultUriInvalid, bool $absoluteInput): null
    {
        $this->messageLogger->log(
            Message::warning(MessageCode::ABSOLUTE_URI_GENERATION_FAILED, MessageKey::ABSOLUTE_URI_GENERATION_FAILED, [
                '%caller%' => $caller,
            ]),
            [
                'component' => self::class,
                'caller' => $caller,
                'route_invalid' => $routeInvalid,
                'default_uri_invalid' => $defaultUriInvalid,
                'absolute_input' => $absoluteInput,
            ],
        );

        return null;
    }
}
