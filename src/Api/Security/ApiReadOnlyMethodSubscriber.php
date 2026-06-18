<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use App\Security\ApiKeyStatus;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiReadOnlyMethodSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiResponder $responder,
        private ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        $context = ApiRequestContext::fromRequest($request);

        if (!$context instanceof ApiRequestContext || ApiKeyStatus::ReadOnly !== $context->apiKeyStatus()) {
            return;
        }

        if ($this->methodPolicy->isSafeEffectiveMethod($request)) {
            return;
        }

        $event->setResponse($this->responder->error(
            Message::warning(
                SecurityMessageCode::API_KEY_PERMISSION_WRITE_REQUIRED,
                SecurityMessageKey::API_KEY_PERMISSION_WRITE_REQUIRED,
            ),
            Response::HTTP_FORBIDDEN,
            $request,
            ['method' => $request->getMethod()],
        ));
    }

}
