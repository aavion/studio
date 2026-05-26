<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\Config\Config;
use App\Form\FormFieldDefinition;
use App\Form\FormSubmissionHandler;
use App\Form\FormSubmissionResult;

final readonly class CoreSettingsFormHandler
{
    public function __construct(
        private CoreSettingsRegistry $registry,
        private Config $config,
        private FormSubmissionHandler $submissionHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function submit(string $section, array $submitted, ?string $modifiedBy = null): FormSubmissionResult
    {
        $definitions = $this->registry->definitions($section);
        $result = $this->submissionHandler->submit(
            array_map(static fn (CoreSettingDefinition $definition): FormFieldDefinition => $definition->formField(), $definitions),
            $submitted,
        );

        if (!$result->isValid()) {
            return $result;
        }

        foreach ($definitions as $definition) {
            if (false === ($definition->metadata()['persist'] ?? true)) {
                continue;
            }

            if (!$this->config->set($definition->key(), $result->value($definition->key()), $definition->valueType(), modifiedBy: $modifiedBy)) {
                return new FormSubmissionResult($result->values(), [
                    '__form' => ['admin.settings.form.errors.save_failed'],
                ]);
            }
        }

        return $result;
    }
}
