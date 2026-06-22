<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Core\Id\UuidFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CaptchaFieldRenderer
{
    /**
     * @var array<string, true>
     */
    private const POLICIES = [
        'require' => true,
        'require-verified' => true,
    ];

    public function __construct(
        private RequestStack $requestStack,
        private CaptchaInstanceStore $instances,
        private CaptchaProviderBridge $providerBridge,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    /**
     * @return array{template: string, context: array<string, mixed>, instance_id: string, instance_field: string}
     */
    public function render(string $workflow = 'form', string $formId = 'captcha-form', string $fieldName = 'captcha', string $policy = 'require'): array
    {
        $workflow = $this->nonEmpty($workflow, 'form');
        $formId = $this->nonEmpty($formId, 'captcha-form');
        $fieldName = $this->nonEmpty($fieldName, 'captcha');
        if (in_array($fieldName, [CaptchaInstanceStore::INSTANCE_FIELD, CaptchaRequestGuardSubscriber::RESULT_FIELD], true)) {
            $fieldName = 'captcha';
        }
        $policy = isset(self::POLICIES[$policy]) ? $policy : 'require';
        $request = $this->requestStack->getCurrentRequest() ?? $this->requestStack->getMainRequest();
        $entry = null !== $request
            ? $this->instances->create($request, $workflow, $formId, $fieldName)
            : new CaptchaInstanceEntry($this->uuidFactory->generate(), '', $workflow, $formId, $fieldName, time());
        $result = $this->providerBridge->render(new CaptchaRenderContext($workflow, $formId, $fieldName, [
            'captcha_instance_id' => $entry->id(),
            'policy' => $policy,
        ]));
        $context = $result->context();
        $captcha = is_array($context['captcha'] ?? null) ? $context['captcha'] : [];
        $context['captcha'] = [
            ...$captcha,
            'provider' => $result->provider() ?? ($captcha['provider'] ?? 'none'),
            'workflow' => $workflow,
            'form_id' => $formId,
            'field_name' => $fieldName,
            'instance_id' => $entry->id(),
            'policy' => $policy,
        ];
        $context['form_id'] = $formId;
        $context['name'] = $fieldName;

        return [
            'template' => $result->template(),
            'context' => $context,
            'instance_id' => $entry->id(),
            'instance_field' => CaptchaInstanceStore::INSTANCE_FIELD,
        ];
    }

    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return '' === $value ? $fallback : $value;
    }
}
