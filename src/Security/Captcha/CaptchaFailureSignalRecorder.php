<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use App\Core\Log\AccessRequestMetadata;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubject;
use App\Security\Abuse\AbuseSubjectType;
use App\Security\Abuse\RequestIntent;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\AutoBan\AutoBanScoreCatalogue;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class CaptchaFailureSignalRecorder
{
    public function __construct(
        private AbuseRequestInspector $inspector,
        private SecuritySignalRecorder $signals,
        private AccessRequestMetadata $requestMetadata,
    ) {
    }

    public function record(Request $request, CaptchaInstanceEntry $entry, CaptchaValidationResult $result): void
    {
        if (!$this->shouldRecord($result)) {
            return;
        }

        $this->recordSignal(
            $request,
            $entry,
            AutoBanScoreCatalogue::SIGNAL_CAPTCHA_FAILURE,
            $result->status()->value,
            $result->provider(),
            $this->failureCode($result),
            $this->severity($result),
            $this->confidence($result),
        );
    }

    public function recordVisitorMismatch(Request $request, CaptchaInstanceEntry $entry): void
    {
        $this->recordSignal(
            $request,
            $entry,
            AutoBanScoreCatalogue::SIGNAL_CAPTCHA_VISITOR_MISMATCH,
            'visitor_mismatch',
            null,
            'visitor_mismatch',
            'NOTICE',
            45,
        );
    }

    private function shouldRecord(CaptchaValidationResult $result): bool
    {
        return $result->isProviderBacked()
            && in_array($result->status(), [
                CaptchaValidationStatus::RecoverableFailure,
                CaptchaValidationStatus::SuspiciousFailure,
            ], true);
    }

    private function recordSignal(
        Request $request,
        CaptchaInstanceEntry $entry,
        string $reasonCode,
        string $captchaStatus,
        ?string $provider,
        string $failureCode,
        string $severity,
        int $confidence,
    ): void {
        try {
            $inspection = $this->inspector->inspect($request);
            $profile = $inspection['profile'];
            $subjects = $inspection['subjects'];
            $visitor = $subjects->first(AbuseSubjectType::Visitor);
            $ipBucket = $subjects->first(AbuseSubjectType::IpBucket);

            foreach ($this->sourceSubjects($visitor, $ipBucket) as $subject) {
                $this->signals->record(
                    'captcha',
                    $reasonCode,
                    $subject->type()->value,
                    $subject->identifier(),
                    ipDerived: $subject->ipDerived(),
                    severity: $severity,
                    confidence: $confidence,
                    requestFamily: $profile->family()->value,
                    requestIntent: RequestIntent::CaptchaFailure->value,
                    requestId: $this->requestMetadata->requestId($request),
                    visitorId: $visitor?->identifier() ?? 'n/a',
                    path: $this->requestMetadata->sanitizedPath($request),
                    route: $profile->route(),
                    context: [
                        'captcha_provider' => $provider,
                        'captcha_status' => $captchaStatus,
                        'captcha_failure_code' => $failureCode,
                        'workflow' => $entry->workflow(),
                        'form_id' => $entry->formId(),
                        'field_name' => $entry->fieldName(),
                        'original_request_intent' => $profile->intent()->value,
                        'ip_bucket' => $ipBucket?->identifier(),
                        'cost_bucket' => 'captcha_failure',
                        'cost_credits' => 1,
                        'ordinary_enforcement' => true,
                    ],
                );
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @return list<AbuseSubject>
     */
    private function sourceSubjects(?AbuseSubject $visitor, ?AbuseSubject $ipBucket): array
    {
        return array_values(array_filter([$visitor, $ipBucket]));
    }

    private function severity(CaptchaValidationResult $result): string
    {
        return CaptchaValidationStatus::SuspiciousFailure === $result->status() ? 'WARNING' : 'NOTICE';
    }

    private function confidence(CaptchaValidationResult $result): int
    {
        return CaptchaValidationStatus::SuspiciousFailure === $result->status() ? 80 : 65;
    }

    private function failureCode(CaptchaValidationResult $result): string
    {
        $failureCode = $result->context()['failure_code'] ?? null;

        if (!is_scalar($failureCode)) {
            return 'n/a';
        }

        $normalized = substr(trim((string) $failureCode), 0, 120);

        return '' !== $normalized ? $normalized : 'n/a';
    }
}
