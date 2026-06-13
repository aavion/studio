<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\View\Alert\UiAlertTopicFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private readonly ?MercureExtension $mercure = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_alert_stream_topics', $this->streamTopics(...)),
            new TwigFunction('ui_alert_stream_url', $this->streamUrl(...)),
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

        if ([] === $topics || null === $this->mercure) {
            return null;
        }

        try {
            return $this->mercure->mercure($topics, ['subscribe' => $topics]);
        } catch (Throwable) {
            return null;
        }
    }
}
