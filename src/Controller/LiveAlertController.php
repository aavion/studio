<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Output\JsonOutputRenderer;
use App\Entity\UserAccount;
use App\Setup\SetupCompletionMarker;
use App\View\Alert\UiAlertInbox;
use App\View\Alert\UiAlertTopicFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LiveAlertController extends AbstractController
{
    private const POLL_INTERVAL_MS = 15000;

    public function __construct(
        private readonly UiAlertInbox $inbox,
        private readonly UiAlertTopicFactory $topicFactory,
        private readonly JsonOutputRenderer $json,
        private readonly SetupCompletionMarker $setupCompletion,
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    #[Route('/api/live/alerts', name: 'api_live_alerts', methods: ['GET'])]
    public function poll(Request $request): Response
    {
        if (!$this->setupCompletion->isComplete($this->projectDir, $this->environment)) {
            return $this->json->render([
                'cursor' => $this->cursor($request),
                'alerts' => [],
                'next_poll_ms' => self::POLL_INTERVAL_MS,
            ]);
        }

        $cursor = $this->cursor($request);
        $user = $this->getUser();
        $topics = $this->topicFactory->topicsFor(
            $request,
            $user instanceof UserAccount ? $user : null,
        );
        $payload = $this->inbox->poll($topics, $cursor);

        return $this->json->render([
            'cursor' => $payload['cursor'],
            'alerts' => $payload['alerts'],
            'has_more' => $payload['has_more'],
            'next_poll_ms' => self::POLL_INTERVAL_MS,
        ]);
    }

    private function cursor(Request $request): int
    {
        $cursorValue = $request->query->get('cursor', 0);
        return is_scalar($cursorValue) && false !== filter_var((string) $cursorValue, FILTER_VALIDATE_INT)
            ? max(0, (int) $cursorValue)
            : 0;
    }
}
