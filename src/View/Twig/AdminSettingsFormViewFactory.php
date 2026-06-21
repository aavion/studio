<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\Config\Config;
use App\Core\Config\Settings\CoreSettingDefinition;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Extension\Settings\ExtensionSettingRegistry;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Entity\UserAccount;
use App\Form\FormBuilder;
use App\Form\FormFieldDefinition;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AdminSettingsFormViewFactory
{
    public function __construct(
        private Config $config,
        private CoreSettingsRegistry $coreSettingsRegistry,
        private FormBuilder $formBuilder,
        private ExtensionSettings $extensionSettings,
        private ExtensionSettingRegistry $extensionSettingRegistry,
        private Security $security,
        private RequestStack $requestStack,
        private ?AdminFeatureAccessPolicy $adminAcl = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function coreSettingsForm(string $section): array
    {
        $definitions = $this->coreSettingDefinitions($section);
        $mutableDefinitions = array_values(array_filter(
            $definitions,
            fn (CoreSettingDefinition $definition): bool => $this->coreSettingMutable($definition, $this->actor()),
        ));
        $values = [];
        $request = $this->requestStack->getCurrentRequest();

        foreach ($definitions as $definition) {
            $value = $this->config->get($definition->key()) ?? $definition->defaultValue();
            if (true === ($definition->metadata()['sensitive'] ?? false)) {
                $value = '';
            }

            $values[$definition->key()] = $value;
        }

        $values = array_replace($values, $this->requestFormValues($request, $this->sensitiveDefinitionKeys($definitions)));
        $errors = $this->requestFormErrors($request);

        return $this->formBuilder->build(
            'admin-settings-'.$section,
            'admin.settings.'.$section.'.title',
            array_map(static fn ($definition) => $definition->formField(), $definitions),
            $values,
            $errors,
            $errors['__form'] ?? [],
            metadata: [
                'read_only' => [] === $mutableDefinitions,
            ],
        )->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extensionSettings(string $extensionName): array
    {
        if (!$this->extensionFeatureVisible($extensionName)) {
            return [];
        }

        return $this->extensionSettings->viewRows($extensionName, $this->extensionSettingRegistry);
    }

    public function extensionSetting(string $extensionName, string $key, mixed $default = null): mixed
    {
        return $this->extensionSettings->get($extensionName, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function extensionSettingsForm(string $extensionName): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $errors = $this->requestFormErrors($request);
        $fields = $this->extensionSettings->formFields($extensionName, $this->extensionSettingRegistry);
        $mutable = $this->extensionFeatureMutable($extensionName);
        $sensitiveKeys = $this->sensitiveFieldKeys($fields);
        $values = array_replace(
            array_fill_keys($sensitiveKeys, ''),
            $this->requestFormValues($request, $sensitiveKeys),
        );

        $form = $this->formBuilder->build(
            'extension-settings-'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($extensionName)),
            $extensionName,
            $fields,
            $values,
            $errors,
            $errors['__form'] ?? [],
            metadata: [
                'read_only' => !$mutable,
            ],
        )->toArray();

        if (!$mutable) {
            foreach ($form['fields'] as $index => $field) {
                if (is_array($field)) {
                    $metadata = is_array($field['metadata'] ?? null) ? $field['metadata'] : [];
                    $form['fields'][$index]['metadata'] = [...$metadata, 'disabled' => true];
                }
            }
        }

        return $form;
    }

    /**
     * @return list<array{extension_name: string, label: string, description: string|null, path: string}>
     */
    public function extensionSettingExtensions(): array
    {
        $extensions = [];

        foreach ($this->extensionSettingRegistry->extensionsWithDefinitions() as $extensionName => $metadata) {
            if (!$this->extensionFeatureVisible($extensionName)) {
                continue;
            }

            $extensions[] = [
                'extension_name' => $extensionName,
                'label' => $metadata['label'],
                'description' => $metadata['description'],
                'path' => $metadata['path'],
            ];
        }

        return $extensions;
    }

    /**
     * @return list<CoreSettingDefinition>
     */
    private function coreSettingDefinitions(string $section): array
    {
        $actor = $this->actor();

        return array_values(array_filter(
            array_map(
                fn (CoreSettingDefinition $definition): CoreSettingDefinition => $this->decorateCoreSettingDefinition($definition, $actor),
                $this->coreSettingsRegistry->definitions($section),
            ),
            fn (CoreSettingDefinition $definition): bool => $this->coreSettingVisible($definition, $actor),
        ));
    }

    private function decorateCoreSettingDefinition(CoreSettingDefinition $definition, AccessActor $actor): CoreSettingDefinition
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (!is_string($feature) || null === $this->adminAcl) {
            return $definition;
        }

        $state = $this->adminAcl->state($feature, $actor);

        return $definition->withMetadata([
            'access_state' => $state->value,
            'disabled' => !$state->isMutable(),
        ]);
    }

    private function coreSettingVisible(CoreSettingDefinition $definition, AccessActor $actor): bool
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (is_string($feature) && null !== $this->adminAcl) {
            return $this->adminAcl->isVisible($feature, $actor);
        }

        return $definition->allows($actor);
    }

    private function coreSettingMutable(CoreSettingDefinition $definition, AccessActor $actor): bool
    {
        $feature = $definition->metadata()['access_feature'] ?? null;

        if (is_string($feature) && null !== $this->adminAcl) {
            return $this->adminAcl->isMutable($feature, $actor);
        }

        return $definition->allows($actor);
    }

    private function extensionFeatureVisible(string $extensionName): bool
    {
        return null === $this->adminAcl || $this->adminAcl->isVisible('admin.settings.extensions.'.$extensionName, $this->actor());
    }

    private function extensionFeatureMutable(string $extensionName): bool
    {
        return null === $this->adminAcl || $this->adminAcl->isMutable('admin.settings.extensions.'.$extensionName, $this->actor());
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    /**
     * @param list<string> $excludedKeys
     *
     * @return array<string, mixed>
     */
    private function requestFormValues(?Request $request, array $excludedKeys = []): array
    {
        $values = $request?->attributes->get('_system_form_values');

        if (!is_array($values)) {
            return [];
        }

        foreach ($excludedKeys as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requestFormErrors(?Request $request): array
    {
        $errors = $request?->attributes->get('_system_form_errors');

        return is_array($errors) ? $errors : [];
    }

    /**
     * @param iterable<CoreSettingDefinition> $definitions
     *
     * @return list<string>
     */
    private function sensitiveDefinitionKeys(iterable $definitions): array
    {
        $keys = [];

        foreach ($definitions as $definition) {
            if (true === ($definition->metadata()['sensitive'] ?? false)) {
                $keys[] = $definition->key();
            }
        }

        return $keys;
    }

    /**
     * @param iterable<FormFieldDefinition> $fields
     *
     * @return list<string>
     */
    private function sensitiveFieldKeys(iterable $fields): array
    {
        $keys = [];

        foreach ($fields as $field) {
            if (true === ($field->metadata()['sensitive'] ?? false)) {
                $keys[] = $field->name();
            }
        }

        return $keys;
    }
}
