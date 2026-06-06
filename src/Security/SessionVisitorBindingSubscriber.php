<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\UserAccount;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

final readonly class SessionVisitorBindingSubscriber implements EventSubscriberInterface
{
    public const SESSION_VISITOR_ID = '_system_session_visitor_id';
    public const SESSION_PREVIOUS_VISITOR_ID = '_system_session_previous_visitor_id';
    public const SESSION_VISITOR_CHANGED_AT = '_system_session_visitor_changed_at';
    public const SESSION_VISITOR_CHANGE_COUNT = '_system_session_visitor_change_count';
    private const LOGIN_PATH = '/user/login';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private VisitorIdGenerator $visitorIdGenerator,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $session = $this->session($event->getRequest());

        if (null === $session) {
            return;
        }

        $this->bind($session, $this->visitorIdGenerator->generate($event->getRequest()));
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserInterface) {
            return;
        }

        $request = $event->getRequest();
        $session = $this->session($request);

        if (null === $session) {
            return;
        }

        $currentVisitorId = $this->visitorIdGenerator->generate($request);
        $boundVisitorId = $session->get(self::SESSION_VISITOR_ID);

        if (!is_string($boundVisitorId) || '' === $boundVisitorId) {
            $this->bind($session, $currentVisitorId);

            return;
        }

        if (hash_equals($boundVisitorId, $currentVisitorId)) {
            return;
        }

        $changeCount = max(0, (int) $session->get(self::SESSION_VISITOR_CHANGE_COUNT, 0)) + 1;
        $changedAt = (new DateTimeImmutable())->format(DATE_ATOM);

        $session->set(self::SESSION_PREVIOUS_VISITOR_ID, $boundVisitorId);
        $session->set(self::SESSION_VISITOR_CHANGED_AT, $changedAt);
        $session->set(self::SESSION_VISITOR_CHANGE_COUNT, $changeCount);

        $this->safeAudit($user, [
            'previous_visitor_id' => $boundVisitorId,
            'current_visitor_id' => $currentVisitorId,
            'change_count' => $changeCount,
            'changed_at' => $changedAt,
        ]);
        $this->tokenStorage->setToken(null);
        $session->invalidate();
        $event->setResponse(new RedirectResponse(self::LOGIN_PATH, Response::HTTP_SEE_OTHER));
    }

    private function bind(SessionInterface $session, string $visitorId): void
    {
        $session->set(self::SESSION_VISITOR_ID, $visitorId);
        $session->remove(self::SESSION_PREVIOUS_VISITOR_ID);
        $session->remove(self::SESSION_VISITOR_CHANGED_AT);
        $session->remove(self::SESSION_VISITOR_CHANGE_COUNT);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function safeAudit(UserInterface $user, array $context): void
    {
        try {
            $this->auditLogger->log($this->actorFromUser($user), 'auth.session_visitor_mismatch_terminated', $context);
        } catch (Throwable) {
            return;
        }
    }

    private function actorFromUser(UserInterface $user): AccessActor
    {
        if ($user instanceof UserAccount) {
            return AccessActor::fromUserAccount($user);
        }

        return AccessActor::fromAccess(0, username: $user->getUserIdentifier());
    }

    private function session(Request $request): ?SessionInterface
    {
        try {
            return $request->hasSession() ? $request->getSession() : null;
        } catch (SessionNotFoundException) {
            return null;
        }
    }
}
