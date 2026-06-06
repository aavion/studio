<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\HttpFoundation\Request;

final readonly class SetupWizardFlow
{
    public const STEPS = ['language', 'site', 'database', 'admin', 'review'];

    public function __construct(
        private string $projectDir,
        private string $environment,
        private SetupWebInputFactory $inputFactory,
        private SetupSiteSettings $siteSettings,
        private SetupPreflightChecker $preflightChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    public function mergeSubmittedValues(array $current, array $submitted): array
    {
        foreach (['admin_password', 'admin_password_confirm'] as $secretField) {
            if (array_key_exists($secretField, $submitted) && '' === trim((string) $submitted[$secretField]) && '' !== trim((string) ($current[$secretField] ?? ''))) {
                unset($submitted[$secretField]);
            }
        }

        return array_replace($current, $submitted);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    public function resetLaterStepsWhenValuesChanged(array $state, string $step, array $submitted): array
    {
        $previousValues = array_replace($this->inputFactory->defaults(), $state['values']);

        if (!$this->stepValuesChanged($step, $previousValues, $submitted)) {
            return $state;
        }

        $index = array_search($step, self::STEPS, true);

        if (!is_int($index)) {
            return $state;
        }

        $allowedCompleted = array_slice(self::STEPS, 0, $index);
        $state['completed'] = array_values(array_intersect($state['completed'], $allowedCompleted));
        $state['workflow'] = null;
        $state['action_log'] = null;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, list<string>>}
     */
    public function advance(Request $request, array $state, string $step): array
    {
        $errors = 'language' === $step && !$this->preflightChecker->check($this->projectDir, $this->environment, server: $request->server->all())['ok']
            ? ['__form' => ['setup.preflight.errors.required']]
            : $this->inputFactory->validateStep($step, $state['values']);

        if ([] !== $errors) {
            return [$state, $step, $errors];
        }

        if (in_array($step, self::STEPS, true) && !in_array($step, $state['completed'], true)) {
            $state['completed'][] = $step;
        }

        return [$state, $this->nextStep($step), []];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function stepReachable(string $step, array $state): bool
    {
        if ('preflight' === $step) {
            return false;
        }

        if ('result' === $step) {
            return null !== $state['workflow'];
        }

        $index = array_search($step, self::STEPS, true);

        if (!is_int($index)) {
            return false;
        }

        return 0 === $index || in_array(self::STEPS[$index - 1], $state['completed'], true);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function firstLockedStep(array $state): string
    {
        foreach (self::STEPS as $index => $step) {
            if (0 === $index || in_array(self::STEPS[$index - 1], $state['completed'], true)) {
                continue;
            }

            return self::STEPS[$index - 1];
        }

        return 'review';
    }

    public function nextStep(string $step): string
    {
        $index = array_search($step, self::STEPS, true);

        return is_int($index) && isset(self::STEPS[$index + 1]) ? self::STEPS[$index + 1] : $step;
    }

    public function previousStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);

        if (!is_int($index) || 0 === $index) {
            return null;
        }

        return self::STEPS[$index - 1];
    }

    /**
     * @param array<string, mixed> $previousValues
     * @param array<string, mixed> $submitted
     */
    private function stepValuesChanged(string $step, array $previousValues, array $submitted): bool
    {
        $fields = match ($step) {
            'language' => ['language'],
            'site' => ['site_title', 'default_uri', ...array_keys($this->siteSettings->defaults())],
            'database' => ['database_driver', 'database_url', 'database_host', 'database_port', 'database_name', 'database_user', 'database_password', 'database_prefix'],
            'admin' => ['admin_username', 'admin_password', 'admin_password_confirm', 'admin_email', 'app_secret'],
            default => [],
        };

        foreach ($fields as $field) {
            if ((string) ($previousValues[$field] ?? '') !== (string) ($submitted[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
