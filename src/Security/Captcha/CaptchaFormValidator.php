<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use Symfony\Component\HttpFoundation\Request;

final readonly class CaptchaFormValidator
{
    public function __construct(private CaptchaProviderBridge $providerBridge)
    {
    }

    public function validateRequired(Request $request, string $workflow, string $formId, string $fieldName = 'captcha'): CaptchaValidationResult
    {
        $submittedFields = $request->request->all();
        $payloadPresent = array_key_exists($fieldName, $submittedFields);
        $payload = $payloadPresent ? $submittedFields[$fieldName] : null;
        $activeProvider = $this->providerBridge->activeProvider();

        if (null !== $activeProvider) {
            if (!$payloadPresent) {
                return CaptchaValidationResult::recoverableFailure($activeProvider, [
                    'reason' => 'missing_payload',
                    'workflow' => $workflow,
                    'form_id' => $formId,
                    'field_name' => $fieldName,
                ]);
            }

            if ($this->looksLikeNativeFallbackPayload($payload)) {
                return CaptchaValidationResult::recoverableFailure($activeProvider, [
                    'reason' => 'fallback_payload_for_active_provider',
                    'workflow' => $workflow,
                    'form_id' => $formId,
                    'field_name' => $fieldName,
                ]);
            }
        }

        return $this->providerBridge->validate(new CaptchaValidationRequest($workflow, $formId, $fieldName, $payload, [
            'path' => $request->getPathInfo(),
            'route' => $request->attributes->get('_route'),
        ]));
    }

    private function looksLikeNativeFallbackPayload(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $provider = $payload['provider'] ?? null;

        return is_string($provider)
            && 'none' === strtolower($provider)
            && array_key_exists('fallback_rendered', $payload);
    }
}
