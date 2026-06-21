<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use Throwable;

final readonly class CaptchaProviderBridge
{
    public function __construct(private ExtensionRuntimeContributionRegistry $runtimeContributions)
    {
    }

    public function activeProvider(): ?string
    {
        return $this->runtimeContributions->provider(ExtensionScope::CaptchaProvider)?->extensionName();
    }

    public function render(CaptchaRenderContext $context): CaptchaRenderResult
    {
        $provider = $this->runtimeContributions->provider(ExtensionScope::CaptchaProvider);
        if (null === $provider) {
            return CaptchaRenderResult::fallback($this->fallbackContext($context));
        }

        try {
            $result = ($provider->provider())($context);
        } catch (Throwable $error) {
            return CaptchaRenderResult::providerFault($provider->extensionName(), [
                'exception' => $error::class,
            ]);
        }

        if ($result instanceof CaptchaRenderResult) {
            return $result->withProvider($provider->extensionName());
        }

        return CaptchaRenderResult::providerFault($provider->extensionName(), [
            'reason' => 'invalid_render_result',
            'type' => get_debug_type($result),
        ]);
    }

    public function validate(CaptchaValidationRequest $request): CaptchaValidationResult
    {
        $provider = $this->runtimeContributions->provider(ExtensionScope::CaptchaProvider);
        if (null === $provider) {
            return CaptchaValidationResult::skipped('none', [
                'reason' => 'provider_missing',
            ]);
        }

        try {
            $result = ($provider->provider())($request);
        } catch (Throwable $error) {
            return CaptchaValidationResult::providerFault($provider->extensionName(), [
                'exception' => $error::class,
            ]);
        }

        if ($result instanceof CaptchaValidationResult) {
            return $result->withProvider($provider->extensionName());
        }

        return CaptchaValidationResult::providerFault($provider->extensionName(), [
            'reason' => 'invalid_validation_result',
            'type' => get_debug_type($result),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackContext(CaptchaRenderContext $context): array
    {
        return [
            'captcha' => [
                'provider' => 'none',
                'workflow' => $context->workflow(),
                'form_id' => $context->formId(),
                'field_name' => $context->fieldName(),
                'fallback_rendered' => true,
            ],
        ];
    }
}
