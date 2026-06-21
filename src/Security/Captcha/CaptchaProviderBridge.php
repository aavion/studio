<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Throwable;

final readonly class CaptchaProviderBridge
{
    public function __construct(
        private ExtensionRuntimeContributionRegistry $runtimeContributions,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    public function activeProvider(): ?string
    {
        return $this->runtimeContributions->provider(ExtensionScope::CaptchaProvider)?->extensionName();
    }

    public function render(CaptchaRenderContext $context): CaptchaRenderResult
    {
        $provider = $this->runtimeContributions->provider(ExtensionScope::CaptchaProvider);
        if (null === $provider) {
            return CaptchaRenderResult::fallback();
        }

        try {
            $result = ($provider->provider())($context);
        } catch (Throwable $error) {
            $this->reportProviderRuntimeFailure($provider->extensionName(), 'render', $context, $error);

            return CaptchaRenderResult::providerFault($provider->extensionName(), [
                'failure_code' => CaptchaFailureCode::ProviderRuntimeFailed->value,
                'exception' => $error::class,
            ]);
        }

        if ($result instanceof CaptchaRenderResult) {
            return $result->withProvider($provider->extensionName());
        }

        return $this->invalidRenderResult($provider->extensionName(), $context, $result);
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
            $this->reportProviderRuntimeFailure($provider->extensionName(), 'validate', $request, $error);

            return CaptchaValidationResult::providerFault($provider->extensionName(), [
                'failure_code' => CaptchaFailureCode::ProviderRuntimeFailed->value,
                'exception' => $error::class,
            ]);
        }

        if ($result instanceof CaptchaValidationResult) {
            return $result->withProvider($provider->extensionName());
        }

        return $this->invalidValidationResult($provider->extensionName(), $request, $result);
    }

    private function invalidRenderResult(string $provider, CaptchaRenderContext $context, mixed $result): CaptchaRenderResult
    {
        $type = get_debug_type($result);
        $this->reportInvalidProviderResult($provider, 'render', $context, $type);

        return CaptchaRenderResult::providerFault($provider, [
            'failure_code' => CaptchaFailureCode::ProviderResultInvalid->value,
            'reason' => 'invalid_render_result',
            'type' => $type,
        ]);
    }

    private function invalidValidationResult(string $provider, CaptchaValidationRequest $request, mixed $result): CaptchaValidationResult
    {
        $type = get_debug_type($result);
        $this->reportInvalidProviderResult($provider, 'validate', $request, $type);

        return CaptchaValidationResult::providerFault($provider, [
            'failure_code' => CaptchaFailureCode::ProviderResultInvalid->value,
            'reason' => 'invalid_validation_result',
            'type' => $type,
        ]);
    }

    private function reportProviderRuntimeFailure(
        string $provider,
        string $phase,
        CaptchaRenderContext|CaptchaValidationRequest $context,
        Throwable $error,
    ): void {
        $this->messageReporter?->report(
            Message::exception(
                SecurityMessageCode::CAPTCHA_PROVIDER_RUNTIME_FAILED,
                SecurityMessageKey::CAPTCHA_PROVIDER_RUNTIME_FAILED,
                ['%provider%' => $provider, '%phase%' => $phase],
                [
                    ...$this->safeContext($provider, $phase, $context),
                    'failure_code' => CaptchaFailureCode::ProviderRuntimeFailed->value,
                    'exception' => $error::class,
                ],
            ),
            ['operation' => 'security.captcha.provider'],
        );
    }

    private function reportInvalidProviderResult(
        string $provider,
        string $phase,
        CaptchaRenderContext|CaptchaValidationRequest $context,
        string $type,
    ): void {
        $this->messageReporter?->report(
            Message::warning(
                SecurityMessageCode::CAPTCHA_PROVIDER_RESULT_INVALID,
                SecurityMessageKey::CAPTCHA_PROVIDER_RESULT_INVALID,
                ['%provider%' => $provider, '%phase%' => $phase],
                [
                    ...$this->safeContext($provider, $phase, $context),
                    'failure_code' => CaptchaFailureCode::ProviderResultInvalid->value,
                    'result_type' => $type,
                ],
            ),
            ['operation' => 'security.captcha.provider'],
        );
    }

    /**
     * @return array{provider: string, phase: string, workflow: string, form_id: string, field_name: string}
     */
    private function safeContext(string $provider, string $phase, CaptchaRenderContext|CaptchaValidationRequest $context): array
    {
        return [
            'provider' => $provider,
            'phase' => $phase,
            'workflow' => $context->workflow(),
            'form_id' => $context->formId(),
            'field_name' => $context->fieldName(),
        ];
    }
}
