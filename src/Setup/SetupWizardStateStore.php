<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class SetupWizardStateStore
{
    public function __construct(
        private SetupWebInputFactory $inputFactory,
        private SetupLiveOperationPayloadProtector $payloadProtector,
    ) {
    }

    /**
     * @return array{values: array<string, mixed>, completed: list<string>, workflow: array<string, mixed>|null, action_log: array<string, mixed>|null}
     */
    public function read(Request $request): array
    {
        $session = $request->getSession();
        $state = $session->get(SetupWizardState::SESSION_KEY, []);

        if (!is_array($state)) {
            $state = [];
        }

        $storedValues = is_array($state['values'] ?? null) ? $state['values'] : [];
        $defaults = $this->inputFactory->defaults();
        if (!array_key_exists('default_uri', $storedValues)) {
            $defaults['default_uri'] = $this->defaultUriFromRequest($request) ?? $defaults['default_uri'];
        }

        $normalized = [
            'values' => array_replace($defaults, $storedValues),
            'completed' => array_values(array_filter(is_array($state['completed'] ?? null) ? $state['completed'] : [], 'is_string')),
            'workflow' => is_array($state['workflow'] ?? null) ? $state['workflow'] : null,
            'action_log' => is_array($state['action_log'] ?? null) ? $state['action_log'] : null,
        ];

        return $this->unprotectState($normalized, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(Request $request, array $state): void
    {
        $request->getSession()->set(SetupWizardState::SESSION_KEY, $this->protectState($state));
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(SetupWizardState::SESSION_KEY);
        }
    }

    private function defaultUriFromRequest(Request $request): ?string
    {
        try {
            $host = trim($request->getHttpHost());
        } catch (Throwable) {
            return null;
        }

        if ('' === $host) {
            return null;
        }

        $uri = $request->getScheme().'://'.$host;

        return false === filter_var($uri, FILTER_VALIDATE_URL) ? null : $uri;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function protectState(array $state): array
    {
        $payload = $this->payloadProtector->protect(['values' => is_array($state['values'] ?? null) ? $state['values'] : []]);
        $state['values'] = $payload['values'] ?? [];

        if (true === ($payload[SetupLiveOperationPayloadProtector::MARKER] ?? false)) {
            $state[SetupLiveOperationPayloadProtector::MARKER] = true;
            $state[SetupLiveOperationPayloadProtector::SECRETS] = $payload[SetupLiveOperationPayloadProtector::SECRETS] ?? [];
        } else {
            unset($state[SetupLiveOperationPayloadProtector::MARKER], $state[SetupLiveOperationPayloadProtector::SECRETS]);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function unprotectState(array $normalized, array $stored): array
    {
        $payload = ['values' => $normalized['values']];

        if (true === ($stored[SetupLiveOperationPayloadProtector::MARKER] ?? false)) {
            $payload[SetupLiveOperationPayloadProtector::MARKER] = true;
            $payload[SetupLiveOperationPayloadProtector::SECRETS] = $stored[SetupLiveOperationPayloadProtector::SECRETS] ?? [];
        }

        try {
            $payload = $this->payloadProtector->unprotect($payload);
            $normalized['values'] = is_array($payload['values'] ?? null) ? $payload['values'] : $normalized['values'];
        } catch (Throwable) {
            foreach (['admin_password', 'admin_password_confirm', 'database_password', 'database_url', 'app_secret'] as $field) {
                if ('database_url' === $field && 'sqlite' !== (string) ($normalized['values']['database_driver'] ?? '')) {
                    continue;
                }

                $normalized['values'][$field] = '';
            }
        }

        return $normalized;
    }
}
