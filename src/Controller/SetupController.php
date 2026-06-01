<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\ActionLog\ActionLog;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Setup\DatabaseUrlFactory;
use App\Setup\SetupCompletionMarker;
use App\Setup\SetupDatabaseConnectionFactory;
use App\Setup\SetupLiveOperationPayloadProtector;
use App\Setup\SetupPreflightChecker;
use App\Setup\SetupRunner;
use App\Setup\SetupSiteSettings;
use App\Setup\SetupWebInputFactory;
use App\Setup\SetupWizardState;
use App\View\Http\HttpErrorRenderer;
use App\View\SystemPackageMetadataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Throwable;

final class SetupController extends AbstractController
{
    private const STEPS = ['language', 'site', 'database', 'admin', 'review'];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly SetupCompletionMarker $completionMarker,
        private readonly SetupWebInputFactory $inputFactory,
        private readonly SetupPreflightChecker $preflightChecker,
        private readonly DatabaseUrlFactory $databaseUrlFactory,
        private readonly SetupDatabaseConnectionFactory $databaseConnectionFactory,
        private readonly SetupSiteSettings $siteSettings,
        private readonly SetupLiveOperationPayloadProtector $payloadProtector,
        private readonly SetupRunner $setupRunner,
        private readonly LiveOperationStarter $liveOperationStarter,
        private readonly LiveOperationHttpResponder $liveOperationResponder,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly HttpErrorRenderer $httpError,
        private readonly SystemPackageMetadataProvider $systemPackageMetadata,
    ) {
    }

    #[Route('/setup', name: 'backend_setup_index', methods: ['GET', 'POST'])]
    #[Route('/setup/{step}', name: 'backend_setup_step', requirements: ['step' => 'language|site|database|admin|review|result'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $step = 'language'): Response
    {
        if ($this->completionMarker->isComplete($this->projectDir, $this->environment)) {
            $this->clearState($request);

            return $this->httpError->notFound($request);
        }

        $state = $this->state($request);
        $this->applyLocale($request, $state);
        $errors = [];
        $databaseTest = null;
        $clearWizardState = false;

        if ($request->isMethod('POST')) {
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('setup-wizard', (string) $request->request->get('_csrf_token', '')))) {
                $errors['__form'] = ['setup.form.errors.invalid_csrf'];

                if ($this->wantsLiveOperation($request)) {
                    return $this->json(['success' => false, 'issues' => [[
                        'translation_key' => 'setup.form.errors.invalid_csrf',
                        'parameters' => [],
                    ]]], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $submitted = $this->mergeSubmittedValues($state['values'], $this->inputFactory->normalize($request->request->all()));
                $state = $this->resetLaterStepsWhenValuesChanged($state, $step, $submitted);
                $state['values'] = $submitted;
                $this->applyLocale($request, $state);
                if ('language' === $step && 'set_language' === $request->request->get('_setup_action')) {
                    $errors = $this->inputFactory->validateStep('language', $state['values']);
                } elseif ('language' === $step && 'heal_preflight' === $request->request->get('_setup_action')) {
                    $preflight = $this->preflightChecker->check($this->projectDir, $this->environment, autoHeal: true, server: $request->server->all());
                    $errors = $preflight['ok'] ? [] : ['__form' => ['setup.preflight.errors.auto_heal_failed']];
                } elseif ('database' === $step && 'test_database' === $request->request->get('_setup_action')) {
                    [$errors, $databaseTest] = $this->testDatabaseConnection($state['values']);
                } elseif ('review' === $step && 'apply' === $request->request->get('_setup_action')) {
                    $input = $this->inputFactory->create($state['values']);

                    if (!$input->isValid() || null === $input->input()) {
                        $errors = $input->errors();

                        if ($this->wantsLiveOperation($request)) {
                            return $this->json(['success' => false, 'issues' => [[
                                'translation_key' => 'setup.form.errors.invalid',
                                'parameters' => [],
                            ]], 'errors' => $errors], Response::HTTP_BAD_REQUEST);
                        }
                    } else {
                        if ($this->wantsLiveOperation($request)) {
                            $this->saveState($request, $state);

                            return $this->startSetupLiveOperation($request, $input->values());
                        }

                        $result = $this->setupRunner->run($input->input());
                        $state['workflow'] = $result->toArray();
                        $state['action_log'] = $result->value() instanceof ActionLog ? $result->value()->toArray() : ($result->context()['action_log'] ?? null);
                        $state['completed'] = self::STEPS;
                        $clearWizardState = $result->isSuccess();
                        $step = 'result';
                    }
                } else {
                    [$state, $step, $errors] = $this->advance($request, $state, $step);
                }
            }

            if ($clearWizardState) {
                $this->clearState($request);
            } else {
                $this->saveState($request, $state);
            }
        }

        if (!$this->stepReachable($step, $state)) {
            return $this->redirectToRoute('backend_setup_step', ['step' => $this->firstLockedStep($state)]);
        }

        $preflight = $this->preflightChecker->check($this->projectDir, $this->environment, server: $request->server->all());
        $values = array_replace($this->inputFactory->defaults(), $state['values']);
        $displayValues = [
            ...$values,
            'database_prefix' => $this->inputFactory->databasePrefixInputValue((string) ($values['database_prefix'] ?? '')),
        ];

        return $this->render('@backend/setup/index.html.twig', [
            'setup_step' => $step,
            'setup_steps' => self::STEPS,
            'setup_completed_steps' => $state['completed'],
            'setup_values' => $values,
            'setup_display_values' => $displayValues,
            'setup_errors' => $errors,
            'setup_site_settings_form' => $this->siteSettings->form($state['values'], $errors),
            'setup_available_languages' => $this->inputFactory->availableLanguages(),
            'setup_database_driver_options' => $this->inputFactory->databaseDriverOptions(),
            'setup_preflight' => $preflight,
            'setup_workflow' => $state['workflow'],
            'setup_action_log' => $state['action_log'],
            'setup_database_test' => $databaseTest,
            'setup_previous_step' => $this->previousStep($step),
            'setup_next_step' => $this->nextStep($step),
            'setup_app_name' => $this->systemPackageMetadata->metadata()['name'],
        ]);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function mergeSubmittedValues(array $current, array $submitted): array
    {
        foreach (['admin_password', 'admin_password_confirm', 'database_password'] as $secretField) {
            if (array_key_exists($secretField, $submitted) && '' === trim((string) $submitted[$secretField]) && '' !== trim((string) ($current[$secretField] ?? ''))) {
                unset($submitted[$secretField]);
            }
        }

        return array_replace($current, $submitted);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function startSetupLiveOperation(Request $request, array $values): Response
    {
        $result = $this->liveOperationStarter->start(
            LiveOperationQueueFactory::SETUP_APPLY,
            ['values' => $values, 'trigger' => 'setup_wizard'],
            'Setup apply',
        );

        return $this->liveOperationResponder->render($result);
    }

    private function wantsLiveOperation(Request $request): bool
    {
        return '1' === (string) $request->request->get('_operation_live', '')
            || $request->isXmlHttpRequest();
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, list<string>>, 1: array{success: bool, key: string}|null}
     */
    private function testDatabaseConnection(array $values): array
    {
        $errors = $this->inputFactory->validateStep('database', $values);

        if ([] !== $errors) {
            return [$errors, ['success' => false, 'key' => 'setup.database.test_invalid']];
        }

        $input = $this->inputFactory->create([
            ...$values,
            'admin_username' => 'admin',
            'admin_password' => 'Valid1!pass',
            'admin_password_confirm' => 'Valid1!pass',
            'admin_email' => 'admin@example.test',
        ]);

        if (!$input->isValid() || null === $input->input()) {
            return [$input->errors(), ['success' => false, 'key' => 'setup.database.test_invalid']];
        }

        try {
            $databaseUrl = $this->databaseUrlFactory->create($input->input(), $this->projectDir);
            $connection = $this->databaseConnectionFactory->create($this->projectDir, $databaseUrl, $this->environment);
            $connection->fetchOne('SELECT 1');

            return [[], ['success' => true, 'key' => 'setup.database.test_success']];
        } catch (\Throwable) {
            return [[], ['success' => false, 'key' => 'setup.database.test_failed']];
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function resetLaterStepsWhenValuesChanged(array $state, string $step, array $submitted): array
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

    /**
     * @param array<string, mixed> $state
     *
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, list<string>>}
     */
    private function advance(Request $request, array $state, string $step): array
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
     * @return array{values: array<string, mixed>, completed: list<string>, workflow: array<string, mixed>|null, action_log: array<string, mixed>|null}
     */
    private function state(Request $request): array
    {
        $session = $request->getSession();
        $state = $session->get(SetupWizardState::SESSION_KEY, []);

        if (!is_array($state)) {
            $state = [];
        }

        $normalized = [
            'values' => array_replace($this->inputFactory->defaults(), is_array($state['values'] ?? null) ? $state['values'] : []),
            'completed' => array_values(array_filter(is_array($state['completed'] ?? null) ? $state['completed'] : [], 'is_string')),
            'workflow' => is_array($state['workflow'] ?? null) ? $state['workflow'] : null,
            'action_log' => is_array($state['action_log'] ?? null) ? $state['action_log'] : null,
        ];

        return $this->unprotectState($normalized, $state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(Request $request, array $state): void
    {
        $request->getSession()->set(SetupWizardState::SESSION_KEY, $this->protectState($state));
    }

    private function clearState(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(SetupWizardState::SESSION_KEY);
        }
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

    /**
     * @param array<string, mixed> $state
     */
    private function applyLocale(Request $request, array $state): void
    {
        $language = (string) ($state['values']['language'] ?? $this->inputFactory->defaults()['language']);

        if (!in_array($language, $this->inputFactory->availableLanguages(), true)) {
            return;
        }

        $request->setLocale($language);
        $request->getSession()->set('_locale', $language);
        $this->localeSwitcher->setLocale($language);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function stepReachable(string $step, array $state): bool
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
    private function firstLockedStep(array $state): string
    {
        foreach (self::STEPS as $index => $step) {
            if (0 === $index || in_array(self::STEPS[$index - 1], $state['completed'], true)) {
                continue;
            }

            return self::STEPS[$index - 1];
        }

        return 'review';
    }

    private function nextStep(string $step): string
    {
        $index = array_search($step, self::STEPS, true);

        return is_int($index) && isset(self::STEPS[$index + 1]) ? self::STEPS[$index + 1] : $step;
    }

    private function previousStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);

        if (!is_int($index) || 0 === $index) {
            return null;
        }

        return self::STEPS[$index - 1];
    }

}
