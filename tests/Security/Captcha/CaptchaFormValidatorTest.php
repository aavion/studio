<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use App\Security\Captcha\CaptchaFormValidator;
use App\Security\Captcha\CaptchaProviderBridge;
use App\Security\Captcha\CaptchaValidationRequest;
use App\Security\Captcha\CaptchaValidationResult;
use App\Security\Captcha\CaptchaValidationStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CaptchaFormValidatorTest extends TestCase
{
    public function testItAllowsRequiredCaptchaWhenNoProviderIsActive(): void
    {
        $validator = new CaptchaFormValidator(new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry()));

        $result = $validator->validateRequired(Request::create('/user/register', 'POST'), 'user.registration', 'user-registration-form');

        self::assertSame(CaptchaValidationStatus::Skipped, $result->status());
        self::assertTrue($result->allowsWorkflow());
    }

    public function testItRejectsMissingPayloadWhenProviderIsActive(): void
    {
        $providerCalled = false;
        $validator = $this->validator(static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        });

        $result = $validator->validateRequired(Request::create('/user/register', 'POST'), 'user.registration', 'user-registration-form');

        self::assertSame(CaptchaValidationStatus::RecoverableFailure, $result->status());
        self::assertSame('demo-module', $result->provider());
        self::assertSame('missing_payload', $result->context()['reason']);
        self::assertFalse($result->allowsWorkflow());
        self::assertFalse($providerCalled);
    }

    public function testItRejectsNativeFallbackPayloadWhenProviderIsActive(): void
    {
        $providerCalled = false;
        $validator = $this->validator(static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        });
        $request = Request::create('/user/register', 'POST', [
            'captcha' => [
                'provider' => 'none',
                'fallback_rendered' => '1',
                'form_id' => 'user-registration-form',
            ],
        ]);

        $result = $validator->validateRequired($request, 'user.registration', 'user-registration-form');

        self::assertSame(CaptchaValidationStatus::RecoverableFailure, $result->status());
        self::assertSame('fallback_payload_for_active_provider', $result->context()['reason']);
        self::assertFalse($result->allowsWorkflow());
        self::assertFalse($providerCalled);
    }

    public function testItDelegatesProviderPayloadWhenProviderIsActive(): void
    {
        $validator = $this->validator(static function (CaptchaValidationRequest $request): CaptchaValidationResult {
            return CaptchaValidationResult::verifiedForProvider('demo-captcha', [
                'payload' => $request->payload(),
            ]);
        });
        $request = Request::create('/user/register', 'POST', [
            'captcha' => [
                'token' => 'ok',
            ],
        ]);

        $result = $validator->validateRequired($request, 'user.registration', 'user-registration-form');

        self::assertTrue($result->allowsWorkflow());
        self::assertSame('demo-module', $result->provider());
        self::assertSame(['token' => 'ok'], $result->context()['payload']);
    }

    public function testItDoesNotTreatProviderSkippedResultAsAccepted(): void
    {
        $validator = $this->validator(static fn (): CaptchaValidationResult => CaptchaValidationResult::skipped('demo-captcha'));
        $request = Request::create('/user/register', 'POST', [
            'captcha' => [
                'token' => 'ignored',
            ],
        ]);

        $result = $validator->validateRequired($request, 'user.registration', 'user-registration-form');

        self::assertSame(CaptchaValidationStatus::Skipped, $result->status());
        self::assertSame('demo-module', $result->provider());
        self::assertFalse($result->allowsWorkflow());
    }

    private function validator(callable $provider): CaptchaFormValidator
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, $provider));

        return new CaptchaFormValidator(new CaptchaProviderBridge($registry));
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000705',
            [ExtensionScope::CaptchaProvider],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
