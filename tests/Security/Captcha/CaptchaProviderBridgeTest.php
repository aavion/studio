<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use App\Security\Captcha\CaptchaProviderBridge;
use App\Security\Captcha\CaptchaRenderContext;
use App\Security\Captcha\CaptchaRenderResult;
use App\Security\Captcha\CaptchaValidationRequest;
use App\Security\Captcha\CaptchaValidationResult;
use App\Security\Captcha\CaptchaValidationStatus;
use PHPUnit\Framework\TestCase;

final class CaptchaProviderBridgeTest extends TestCase
{
    public function testItSkipsValidationWhenNoCaptchaProviderIsActive(): void
    {
        $bridge = new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry());

        $result = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', null));

        self::assertSame(CaptchaValidationStatus::Skipped, $result->status());
        self::assertSame('none', $result->provider());
        self::assertFalse($result->isVerified());
        self::assertFalse($result->isProviderBacked());
    }

    public function testItReturnsFallbackRenderResultWhenNoCaptchaProviderIsActive(): void
    {
        $bridge = new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry());

        $result = $bridge->render(new CaptchaRenderContext('login', 'login-form'));

        self::assertSame('@provider/captcha/field.html.twig', $result->template());
        self::assertNull($result->provider());
        self::assertFalse($result->visible());
        self::assertFalse($result->faulty());
        self::assertTrue($result->context()['captcha']['skipped']);
        self::assertSame('login-form', $result->context()['captcha']['form_id']);
    }

    public function testItDelegatesRenderAndValidationToTheActiveCaptchaProvider(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static function (CaptchaRenderContext|CaptchaValidationRequest $input): CaptchaRenderResult|CaptchaValidationResult {
                if ($input instanceof CaptchaRenderContext) {
                    return CaptchaRenderResult::forProvider('demo-captcha', [
                        'captcha' => [
                            'form_id' => $input->formId(),
                        ],
                    ]);
                }

                return CaptchaValidationResult::verifiedForProvider('demo-captcha', [
                    'field' => $input->fieldName(),
                ]);
            },
        ));
        $bridge = new CaptchaProviderBridge($registry);

        $render = $bridge->render(new CaptchaRenderContext('login', 'login-form'));
        $validation = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', ['token' => 'ok']));

        self::assertSame('demo-module', $bridge->activeProvider());
        self::assertSame('demo-module', $render->provider());
        self::assertTrue($render->visible());
        self::assertSame('login-form', $render->context()['captcha']['form_id']);
        self::assertTrue($validation->isVerified());
        self::assertTrue($validation->isProviderBacked());
        self::assertSame('demo-module', $validation->provider());
        self::assertSame('captcha', $validation->context()['field']);
    }

    public function testItConvertsProviderExceptionsToProviderFaultResults(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static function (): never {
                throw new \RuntimeException('provider failed');
            },
        ));
        $bridge = new CaptchaProviderBridge($registry);

        $render = $bridge->render(new CaptchaRenderContext('login', 'login-form'));
        $validation = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', null));

        self::assertTrue($render->faulty());
        self::assertSame('demo-module', $render->provider());
        self::assertSame(CaptchaValidationStatus::ProviderFault, $validation->status());
        self::assertSame('demo-module', $validation->provider());
        self::assertFalse($validation->isVerified());
    }

    public function testItConvertsInvalidProviderReturnsToProviderFaultResults(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static fn (): string => 'invalid',
        ));
        $bridge = new CaptchaProviderBridge($registry);

        $render = $bridge->render(new CaptchaRenderContext('login', 'login-form'));
        $validation = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', null));

        self::assertTrue($render->faulty());
        self::assertSame('invalid_render_result', $render->context()['reason']);
        self::assertSame(CaptchaValidationStatus::ProviderFault, $validation->status());
        self::assertSame('invalid_validation_result', $validation->context()['reason']);
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000704',
            [ExtensionScope::CaptchaProvider],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
