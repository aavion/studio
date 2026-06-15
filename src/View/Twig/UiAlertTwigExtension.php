<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\View\Alert\UiAlertTopicFactory;
use App\View\Alert\MercureAvailability;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mercure\Twig\MercureExtension;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UiAlertTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly UiAlertTopicFactory $topicFactory,
        private readonly MercureAvailability $mercureAvailability,
        private readonly string $secret,
        private readonly ?MercureExtension $mercure = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_alert_stream_topics', $this->streamTopics(...)),
            new TwigFunction('ui_alert_stream_url', $this->streamUrl(...)),
            new TwigFunction('ui_alert_storage_scope', $this->storageScope(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function streamTopics(): array
    {
        $user = $this->security->getUser();

        return $this->topicFactory->topicsFor(
            $this->requestStack->getMainRequest(),
            $user instanceof UserInterface ? $user : null,
        );
    }

    /**
     * @param list<string>|null $topics
     */
    public function streamUrl(?array $topics = null): ?string
    {
        $topics ??= $this->streamTopics();

        if ([] === $topics || null === $this->mercure || !$this->mercureAvailability->available()) {
            return null;
        }

        try {
            return $this->mercure->mercure($topics, $this->authorizationOptions($topics));
        } catch (Throwable) {
            return null;
        }
    }

    public function storageScope(): string
    {
        $request = $this->requestStack->getMainRequest();
        $surface = str_starts_with((string) $request?->getPathInfo(), '/admin') ? 'backend' : 'frontend';
        $user = $this->security->getUser();
        $userScope = $user instanceof UserInterface ? $user->getUserIdentifier() : 'anonymous';
        $sessionScope = 'no-session';

        if ($request?->hasSession()) {
            try {
                $session = $request->getSession();
                if ($session instanceof SessionInterface && $session->isStarted()) {
                    $sessionScope = $session->getId();
                } elseif ($session instanceof SessionInterface) {
                    $cookieValue = $request->cookies->get($session->getName());
                    $sessionScope = is_string($cookieValue) && '' !== trim($cookieValue)
                        ? trim($cookieValue)
                        : $sessionScope;
                }
            } catch (Throwable) {
                $sessionScope = 'no-session';
            }
        }

        return $surface.'.'.substr(hash_hmac('sha256', $surface.'|'.$userScope.'|'.$sessionScope, $this->secret), 0, 32);
    }

    /**
     * @param list<string> $topics
     *
     * @return array{subscribe?: list<string>}
     */
    private function authorizationOptions(array $topics): array
    {
        $request = $this->requestStack->getMainRequest();
        if (null !== $request && [] !== $request->attributes->get('_mercure_authorization_cookies', [])) {
            return [];
        }

        return ['subscribe' => $topics];
    }
}
