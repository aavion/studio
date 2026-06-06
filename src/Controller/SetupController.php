<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\ActionLog\ActionLog;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Operation\Live\LiveOperationStarter;
use App\Setup\SetupCompletionMarker;
use App\Setup\SetupPreflightChecker;
use App\Setup\SetupRunner;
use App\Setup\SetupSiteSettings;
use App\Setup\SetupWebInputFactory;
use App\Setup\SetupWizardDatabaseTester;
use App\Setup\SetupWizardFlow;
use App\Setup\SetupWizardStateStore;
use App\View\Http\HttpErrorRenderer;
use App\View\SystemPackageMetadataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\LocaleSwitcher;

final class SetupController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly SetupCompletionMarker $completionMarker,
        private readonly SetupWebInputFactory $inputFactory,
        private readonly SetupPreflightChecker $preflightChecker,
        private readonly SetupSiteSettings $siteSettings,
        private readonly SetupRunner $setupRunner,
        private readonly SetupWizardFlow $wizardFlow,
        private readonly SetupWizardStateStore $wizardState,
        private readonly SetupWizardDatabaseTester $databaseTester,
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
            $this->wizardState->clear($request);

            return $this->httpError->notFound($request);
        }

        $state = $this->wizardState->read($request);
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
                $submitted = $this->wizardFlow->mergeSubmittedValues($state['values'], $this->inputFactory->normalize($request->request->all()));
                $state = $this->wizardFlow->resetLaterStepsWhenValuesChanged($state, $step, $submitted);
                $state['values'] = $submitted;
                $this->applyLocale($request, $state);
                if ('language' === $step && 'set_language' === $request->request->get('_setup_action')) {
                    $errors = $this->inputFactory->validateStep('language', $state['values']);
                } elseif ('language' === $step && 'heal_preflight' === $request->request->get('_setup_action')) {
                    $preflight = $this->preflightChecker->check($this->projectDir, $this->environment, autoHeal: true, server: $request->server->all());
                    $errors = $preflight['ok'] ? [] : ['__form' => ['setup.preflight.errors.auto_heal_failed']];
                } elseif ('database' === $step && 'test_database' === $request->request->get('_setup_action')) {
                    [$errors, $databaseTest] = $this->databaseTester->test($state['values']);
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
                            $this->wizardState->save($request, $state);

                            return $this->startSetupLiveOperation($request, $input->values());
                        }

                        $result = $this->setupRunner->run($input->input());
                        $state['workflow'] = $result->toArray();
                        $state['action_log'] = $result->value() instanceof ActionLog ? $result->value()->toArray() : ($result->context()['action_log'] ?? null);
                        $state['completed'] = SetupWizardFlow::STEPS;
                        $clearWizardState = $result->isSuccess();
                        $step = 'result';
                    }
                } else {
                    [$state, $step, $errors] = $this->wizardFlow->advance($request, $state, $step);
                }
            }

            if ($clearWizardState) {
                $this->wizardState->clear($request);
            } else {
                $this->wizardState->save($request, $state);
            }
        }

        if (!$this->wizardFlow->stepReachable($step, $state)) {
            return $this->redirectToRoute('backend_setup_step', ['step' => $this->wizardFlow->firstLockedStep($state)]);
        }

        $preflight = $this->preflightChecker->check($this->projectDir, $this->environment, server: $request->server->all());
        $values = array_replace($this->inputFactory->defaults(), $state['values']);
        $displayValues = [
            ...$values,
            'database_prefix' => $this->inputFactory->databasePrefixInputValue((string) ($values['database_prefix'] ?? '')),
        ];

        return $this->render('@backend/setup/index.html.twig', [
            'setup_step' => $step,
            'setup_steps' => SetupWizardFlow::STEPS,
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
            'setup_previous_step' => $this->wizardFlow->previousStep($step),
            'setup_next_step' => $this->wizardFlow->nextStep($step),
            'setup_app_name' => $this->systemPackageMetadata->metadata()['name'],
        ]);
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

}
