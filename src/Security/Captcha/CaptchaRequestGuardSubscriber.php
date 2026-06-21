<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CaptchaRequestGuardSubscriber implements EventSubscriberInterface
{
    public const RESULT_ATTRIBUTE = '_system_captcha_result';
    public const RESULT_FIELD = '_captcha_result';

    public function __construct(
        private CaptchaInstanceStore $instances,
        private CaptchaProviderBridge $providerBridge,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse() || !$this->isMutatingRequest($event->getRequest())) {
            return;
        }

        $request = $event->getRequest();
        $request->request->remove(self::RESULT_FIELD);
        $result = $this->resolve($request);
        $request->attributes->set(self::RESULT_ATTRIBUTE, $result->value);
        $request->request->set(self::RESULT_FIELD, $result->value);
    }

    private function resolve(Request $request): CaptchaResult
    {
        $instanceId = $request->request->all()[CaptchaInstanceStore::INSTANCE_FIELD] ?? null;
        $entry = is_string($instanceId) ? $this->instances->consume($instanceId) : null;
        if (!$entry instanceof CaptchaInstanceEntry || !$this->instances->matchesVisitor($request, $entry)) {
            return CaptchaResult::Failed;
        }

        $payload = $request->request->all()[$entry->fieldName()] ?? null;
        $validation = $this->providerBridge->validate(new CaptchaValidationRequest(
            $entry->workflow(),
            $entry->formId(),
            $entry->fieldName(),
            $payload,
            [
                'captcha_instance_id' => $entry->id(),
                'path' => $request->getPathInfo(),
                'route' => $request->attributes->get('_route'),
            ],
        ));

        return $validation->captchaResult();
    }

    private function isMutatingRequest(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
