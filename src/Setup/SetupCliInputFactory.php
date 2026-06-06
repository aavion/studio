<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\SetupMessageKey;
use App\View\SystemPackageMetadataProvider;

final class SetupCliInputFactory
{
    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    private SetupCliPrompter $prompter;
    private SetupCliDatabaseInput $databaseInput;

    public function __construct(
        private readonly string $projectDir,
        private readonly SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        SetupMessageTranslator $translator = new SetupMessageTranslator(),
        private readonly SetupSiteSettings $siteSettings = new SetupSiteSettings(),
        private readonly SetupInputNormalizer $inputNormalizer = new SetupInputNormalizer(),
        private readonly SetupInputValidator $inputValidator = new SetupInputValidator(),
        ?array $extensionAvailability = null,
        ?SetupCliDatabaseInput $databaseInput = null,
        mixed $input = null,
        mixed $output = null,
        private readonly ?bool $interactive = null,
    ) {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
        $this->prompter = new SetupCliPrompter($projectDir, $translator, $this->input, $this->output, $interactive);
        $this->databaseInput = $databaseInput ?? new SetupCliDatabaseInput($this->inputNormalizer, $extensionAvailability);
    }

    /**
     * @param array<string, string|false> $options
     */
    public function create(array $options): SetupInput
    {
        $interactive = $this->prompter->isInteractive($options);
        $databaseUrl = $this->databaseInput->initialDatabaseUrl($options, $this->environment('DATABASE_URL'));
        $language = $this->language($options, $interactive);
        $siteTitle = $this->prompter->value($options, 'site-title', $this->appName(), $interactive, $language, SetupMessageKey::SETUP_PROMPT_SITE_TITLE);
        $defaultUri = $this->prompter->value($options, 'url', $this->environment('DEFAULT_URI', 'http://localhost'), $interactive, $language, SetupMessageKey::SETUP_PROMPT_DEFAULT_URI);
        $databaseDriver = $this->databaseInput->driver($options, $databaseUrl, $interactive, $language, $this->prompter);
        $parts = $this->databaseInput->parts(
            $options,
            $databaseUrl,
            $databaseDriver,
            $interactive,
            $language,
            $this->prompter,
            $this->option($options, 'env', $this->environment('APP_ENV', 'dev')),
        );

        $input = new SetupInput(
            appEnv: (string) $this->option($options, 'env', $this->environment('APP_ENV', 'dev')),
            language: $language,
            siteTitle: $siteTitle,
            defaultUri: $defaultUri,
            databaseDriver: $databaseDriver,
            databaseUrl: $parts['database_url'],
            databaseHost: $parts['database_host'],
            databasePort: null === $parts['database_port'] ? null : (int) $parts['database_port'],
            databaseName: $parts['database_name'],
            databaseUser: $parts['database_user'],
            databasePassword: $parts['database_password'],
            databasePrefix: $this->databaseInput->prefix($options, $this->environment('APP_DATABASE_PREFIX')),
            adminUsername: $this->prompter->value($options, 'admin-username', 'admin', $interactive, $language, SetupMessageKey::SETUP_PROMPT_ADMIN_USERNAME),
            adminPassword: $this->prompter->confirmedValue(
                $options,
                'admin-password',
                '',
                $interactive,
                $language,
                SetupMessageKey::SETUP_PROMPT_ADMIN_PASSWORD,
                SetupMessageKey::SETUP_PROMPT_ADMIN_PASSWORD_CONFIRM,
            ),
            adminEmail: $this->prompter->value($options, 'admin-email', $this->inputNormalizer->adminEmailFromDefaultUri($defaultUri), $interactive, $language, SetupMessageKey::SETUP_PROMPT_ADMIN_EMAIL),
            appSecret: $this->prompter->value($options, 'app-secret', '', $interactive, $language, SetupMessageKey::SETUP_PROMPT_APP_SECRET) ?: null,
            siteSettings: $this->siteSettings($options),
            dryRun: array_key_exists('dry-run', $options),
        );
        $this->inputValidator->assertValidInput($input, $this->languageCatalog->availableLanguages($this->projectDir));

        return $input;
    }

    private function appName(): string
    {
        return (new SystemPackageMetadataProvider($this->projectDir))->metadata()['name'];
    }

    /**
     * @param array<string, string|false> $options
     *
     * @return array<string, mixed>
     */
    private function siteSettings(array $options): array
    {
        $values = $this->siteSettings->defaults();

        if (($mode = $this->option($options, 'registration-mode')) !== null) {
            $values['registration_mode'] = $mode;
        }

        foreach ([
            'username-change-enabled' => 'username_change_enabled',
            'statistics-enabled' => 'statistics_enabled',
            'statistics-respect-dnt' => 'statistics_respect_dnt',
        ] as $option => $name) {
            if (array_key_exists($option, $options)) {
                $values[$name] = $this->inputNormalizer->boolValue($options[$option], falseMeansTrue: true);
            }
        }

        return $this->siteSettings->configMap($values);
    }

    /**
     * @param array<string, string|false> $options
     */
    private function language(array $options, bool $interactive): string
    {
        $default = $this->languageCatalog->defaultLanguage($this->projectDir);
        $language = $this->option($options, 'language', $default);

        if (!$interactive || isset($options['language'])) {
            return $language;
        }

        return $this->prompter->choice($language, SetupMessageKey::SETUP_PROMPT_LANGUAGE, $this->languageCatalog->availableLanguages($this->projectDir), $default);
    }

    private function option(array $options, string $name, ?string $default = null): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private function environment(string $key, ?string $default = null): string
    {
        return (string) ($_SERVER[$key] ?? $_ENV[$key] ?? $default);
    }

}
