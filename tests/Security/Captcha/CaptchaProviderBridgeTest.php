<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Entity\Extension;
use App\Security\Captcha\CaptchaFailureCode;
use App\Security\Captcha\CaptchaProviderBridge;
use App\Security\Captcha\CaptchaRenderContext;
use App\Security\Captcha\CaptchaRenderResult;
use App\Security\Captcha\CaptchaValidationRequest;
use App\Security\Captcha\CaptchaValidationResult;
use App\Security\Captcha\CaptchaValidationStatus;
use App\Security\SecurityMessageCode;
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
        self::assertSame([], $result->context());
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

    public function testItDoesNotTrustClientSuppliedSkipPayloadWhenProviderIsActive(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static function (CaptchaValidationRequest $request): CaptchaValidationResult {
                return CaptchaValidationResult::recoverableFailure('demo-captcha', [
                    'payload' => $request->payload(),
                ]);
            },
        ));
        $bridge = new CaptchaProviderBridge($registry);

        $result = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', [
            'provider' => 'none',
            'status' => 'skipped',
        ]));

        self::assertSame(CaptchaValidationStatus::RecoverableFailure, $result->status());
        self::assertSame('demo-module', $result->provider());
        self::assertTrue($result->isProviderBacked());
        self::assertSame('skipped', $result->context()['payload']['status']);
    }

    public function testItConvertsProviderExceptionsToProviderFaultResults(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $messages = new RecordingCaptchaMessageReporter();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static function (): never {
                throw new \RuntimeException('provider failed');
            },
        ));
        $bridge = new CaptchaProviderBridge($registry, $messages);

        $render = $bridge->render(new CaptchaRenderContext('login', 'login-form'));
        $validation = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', null));

        self::assertTrue($render->faulty());
        self::assertSame('demo-module', $render->provider());
        self::assertSame(CaptchaFailureCode::ProviderRuntimeFailed->value, $render->context()['failure_code']);
        self::assertSame(CaptchaValidationStatus::ProviderFault, $validation->status());
        self::assertSame('demo-module', $validation->provider());
        self::assertSame(CaptchaFailureCode::ProviderRuntimeFailed->value, $validation->context()['failure_code']);
        self::assertFalse($validation->isVerified());
        self::assertCount(2, $messages->records);
        self::assertSame(SecurityMessageCode::CAPTCHA_PROVIDER_RUNTIME_FAILED, $messages->records[0]['message']->code());
        self::assertSame('render', $messages->records[0]['message']->context()['phase']);
        self::assertSame('login-form', $messages->records[0]['message']->context()['form_id']);
        self::assertSame('security.captcha.provider', $messages->records[0]['context']['operation']);
        self::assertSame(SecurityMessageCode::CAPTCHA_PROVIDER_RUNTIME_FAILED, $messages->records[1]['message']->code());
        self::assertSame('validate', $messages->records[1]['message']->context()['phase']);
    }

    public function testItConvertsInvalidProviderReturnsToProviderFaultResults(): void
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $messages = new RecordingCaptchaMessageReporter();
        $registry->add($this->extension(), new ExtensionProviderContribution(
            ExtensionScope::CaptchaProvider,
            static fn (): string => 'invalid',
        ));
        $bridge = new CaptchaProviderBridge($registry, $messages);

        $render = $bridge->render(new CaptchaRenderContext('login', 'login-form'));
        $validation = $bridge->validate(new CaptchaValidationRequest('login', 'login-form', 'captcha', null));

        self::assertTrue($render->faulty());
        self::assertSame(CaptchaFailureCode::ProviderResultInvalid->value, $render->context()['failure_code']);
        self::assertSame('invalid_render_result', $render->context()['reason']);
        self::assertSame(CaptchaValidationStatus::ProviderFault, $validation->status());
        self::assertSame(CaptchaFailureCode::ProviderResultInvalid->value, $validation->context()['failure_code']);
        self::assertSame('invalid_validation_result', $validation->context()['reason']);
        self::assertCount(2, $messages->records);
        self::assertSame(SecurityMessageCode::CAPTCHA_PROVIDER_RESULT_INVALID, $messages->records[0]['message']->code());
        self::assertSame('render', $messages->records[0]['message']->context()['phase']);
        self::assertSame('string', $messages->records[0]['message']->context()['result_type']);
        self::assertSame(SecurityMessageCode::CAPTCHA_PROVIDER_RESULT_INVALID, $messages->records[1]['message']->code());
        self::assertSame('validate', $messages->records[1]['message']->context()['phase']);
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

final class RecordingCaptchaMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = ['message' => $message, 'context' => $context];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];
        foreach ($records as $record) {
            $message = $record['message'];
            if ($message instanceof Message) {
                $messages[] = $this->report($message, $record['context'] ?? []);
            }
        }

        return $messages;
    }
}
