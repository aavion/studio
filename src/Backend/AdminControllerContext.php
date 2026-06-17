<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditLoggerInterface;
use App\Entity\UserAccount;
use App\Navigation\NavigationBuilder;
use App\View\Http\HttpErrorRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AdminControllerContext
{
    public function __construct(
        private BackendAccessGuard $accessGuard,
        private HttpErrorRenderer $httpError,
        private NavigationBuilder $navigationBuilder,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function accessResponse(Request $request, mixed $user): ?Response
    {
        $decision = $this->accessGuard->decide(BackendArea::Admin, $user);

        if ($decision->isGranted()) {
            return null;
        }

        return $this->httpError->resolve(Response::HTTP_UNAUTHORIZED, $request, context: [
            'area' => BackendArea::Admin->value,
            'access_decision' => $decision->toArray(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function navigation(Request $request, mixed $user): array
    {
        return $this->navigationBuilder->build(
            BackendArea::Admin->navigationIdentifier(),
            (string) $request->getLocale(),
            actor: $this->actor($user),
            activeUrl: $request->getPathInfo(),
            activeRoute: (string) $request->attributes->get('_route'),
        );
    }

    public function actor(mixed $user): AccessActor
    {
        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    public function actorName(mixed $user): ?string
    {
        return $this->actor($user)->username();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function audit(mixed $user, string $action, array $context): void
    {
        try {
            $this->auditLogger->log($this->actor($user), $action, $context);
        } catch (Throwable) {
            return;
        }
    }
}
