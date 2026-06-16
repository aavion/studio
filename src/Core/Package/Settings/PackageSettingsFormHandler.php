<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Form\FormErrorKey;
use App\Form\FormSubmissionHandler;
use App\Form\FormSubmissionResult;

final readonly class PackageSettingsFormHandler
{
    private const PROTECTED_VALUE = '[protected]';

    public function __construct(
        private PackageSettingRegistry $registry,
        private PackageSettings $settings,
        private FormSubmissionHandler $submissionHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function submit(string $packageName, array $submitted, ?string $modifiedBy = null): FormSubmissionResult
    {
        $definitions = $this->registry->definitions($packageName);
        $result = $this->submissionHandler->submit(
            array_map(static fn (PackageSettingDefinition $definition) => $definition->formField(), $definitions),
            $submitted,
        );

        if (!$result->isValid()) {
            return $result;
        }

        foreach ($definitions as $definition) {
            if (
                true === ($definition->metadata()['sensitive'] ?? false)
                && $this->isUnchangedSensitiveValue($result->value($definition->key()))
            ) {
                continue;
            }

            if (!$this->settings->set($packageName, $definition->key(), $result->value($definition->key()), $definition->valueType(), $modifiedBy)) {
                return new FormSubmissionResult($result->values(), [
                    '__form' => [FormErrorKey::SAVE_FAILED],
                ]);
            }
        }

        return $result;
    }

    private function isUnchangedSensitiveValue(mixed $value): bool
    {
        return null === $value
            || (is_string($value) && in_array(trim($value), ['', self::PROTECTED_VALUE], true));
    }
}
