<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\ManifestMessageCode;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;

final class ExtensionValidator
{
    public function __construct(
        private readonly ExtensionInventoryInspector $inventoryInspector = new ExtensionInventoryInspector(),
        private readonly ExtensionRequiredPathValidator $requiredPathValidator = new ExtensionRequiredPathValidator(),
        private readonly ExtensionTemplatePathValidator $templatePathValidator = new ExtensionTemplatePathValidator(),
        private readonly ExtensionTemplateReferenceValidator $templateReferenceValidator = new ExtensionTemplateReferenceValidator(),
        private readonly ExtensionFilePolicy $filePolicy = new ExtensionFilePolicy(),
        private readonly ExtensionPhpCapabilityPolicy $phpCapabilityPolicy = new ExtensionPhpCapabilityPolicy(),
        private readonly ExtensionFileSyntaxValidator $fileSyntaxValidator = new ExtensionFileSyntaxValidator(),
        private readonly ExtensionCssNamespaceValidator $cssNamespaceValidator = new ExtensionCssNamespaceValidator(),
        private readonly ExtensionSourceNamespaceValidator $sourceNamespaceValidator = new ExtensionSourceNamespaceValidator(),
        private readonly ExtensionTranslationNamespaceValidator $translationNamespaceValidator = new ExtensionTranslationNamespaceValidator(),
        private readonly ExtensionDependencyManifestValidator $dependencyManifestValidator = new ExtensionDependencyManifestValidator(),
        private readonly ExtensionSchedulerCronValidator $schedulerCronValidator = new ExtensionSchedulerCronValidator(),
        private readonly ExtensionDependencyParser $dependencyParser = new ExtensionDependencyParser(),
    ) {
    }

    /**
     * @return WorkflowResult<ExtensionCandidate>
     */
    public function validate(ExtensionCandidate $candidate, ExtensionSpec $spec): WorkflowResult
    {
        $issues = [
            ...$this->validateExtensionSlug($candidate, $spec),
            ...$this->validateExtensionVersion($candidate),
            ...$this->validateExtensionManifestKeyPrefix($candidate),
            ...$this->validateDependencySyntax($candidate),
        ];

        array_push($issues, ...$this->requiredPathValidator->validate($candidate, $spec));

        $inspection = $this->inventoryInspector->inspect($candidate->directory(), $spec->inventoryDepth());
        $policyMessages = $this->filePolicy->warningMessages($candidate, $inspection);

        array_push($issues, ...$this->templatePathValidator->validate($candidate, $inspection->templateFiles()));
        array_push($issues, ...$this->templateReferenceValidator->validate($candidate, $inspection->templateFiles()));
        array_push($issues, ...$this->filePolicy->blockedIssues($candidate, $inspection));
        array_push($issues, ...$this->phpCapabilityPolicy->validate($candidate, $inspection));
        array_push($issues, ...$this->schedulerCronValidator->validate($candidate, $inspection->phpFiles()));
        array_push($issues, ...$this->fileSyntaxValidator->validate($candidate, $inspection, $spec));
        array_push($issues, ...$this->dependencyManifestValidator->validate($candidate, $inspection));
        array_push($issues, ...$this->cssNamespaceValidator->validate($candidate, $inspection->cssFiles()));
        array_push($issues, ...$this->sourceNamespaceValidator->validate($candidate, $inspection->sourcePhpFiles()));
        array_push($issues, ...$this->translationNamespaceValidator->validate($candidate, $inspection->yamlFiles()));

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues, [
                'inventory' => $inspection->inventory(),
                'inspection' => $inspection,
            ], $policyMessages);
        }

        $context = [
            'inventory' => $inspection->inventory(),
            'inspection' => $inspection,
        ];

        return WorkflowResult::success($candidate, $context, [
            ...$policyMessages,
            Message::debug(ExtensionMessageCode::EXTENSION_VALIDATION_COMPLETED, ExtensionMessageKey::EXTENSION_VALIDATION_COMPLETED, [
                '%extension%' => $candidate->directory(),
            ], [
                'source' => $candidate->source()->name(),
                'extension' => $candidate->directory(),
                'inventory_count' => count($inspection->inventory()),
            ]),
        ]);
    }

    /**
     * @return list<Message>
     */
    private function validateExtensionSlug(ExtensionCandidate $candidate, ExtensionSpec $spec): array
    {
        if ('extension' !== $candidate->source()->name()) {
            return [];
        }

        $slug = trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''));

        if ('' === $slug) {
            return [
                Message::create(
                    ManifestMessageCode::MANIFEST_MISSING_REQUIRED_KEY,
                    ManifestMessageKey::MANIFEST_MISSING_REQUIRED_KEY,
                    ['%key%' => 'EXTENSION_SLUG'],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'EXTENSION_SLUG'],
                    MessageLevel::Error,
                ),
            ];
        }

        if (!ExtensionManifestSpec::isValidSlug($slug)) {
            return [
                Message::create(
                    ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                    ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'EXTENSION_SLUG', 'slug' => $slug],
                    MessageLevel::Error,
                ),
            ];
        }

        $directorySlug = basename(str_replace('\\', '/', rtrim($candidate->directory(), '/\\')));
        if ($spec->directorySlugMatchRequired() && $directorySlug !== $slug) {
            return [
                Message::create(
                    ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                    ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    [
                        'source' => $candidate->source()->name(),
                        'path' => $candidate->manifestPath(),
                        'key' => 'EXTENSION_SLUG',
                        'slug' => $slug,
                        'expected_slug' => $directorySlug,
                    ],
                    MessageLevel::Error,
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Message>
     */
    private function validateExtensionVersion(ExtensionCandidate $candidate): array
    {
        if ('extension' !== $candidate->source()->name()) {
            return [];
        }

        $version = $candidate->manifest()->get('EXTENSION_VERSION');
        if (null === $version || '' === trim((string) $version)) {
            return [];
        }

        $version = trim((string) $version);
        if (ExtensionManifestSpec::isValidVersion($version)) {
            return [];
        }

        return [
            Message::create(
                ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
                ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
                ['%identifier%' => $version],
                [
                    'source' => $candidate->source()->name(),
                    'path' => $candidate->manifestPath(),
                    'key' => 'EXTENSION_VERSION',
                    'version' => $version,
                    'expected_pattern' => ExtensionManifestSpec::VERSION_PATTERN,
                ],
                MessageLevel::Error,
            ),
        ];
    }

    /**
     * @return list<Message>
     */
    private function validateExtensionManifestKeyPrefix(ExtensionCandidate $candidate): array
    {
        if ('extension' !== $candidate->source()->name()) {
            return [];
        }

        $issues = [];

        foreach ($candidate->manifest()->keys() as $key) {
            if (str_starts_with($key, 'EXTENSION_')) {
                continue;
            }

            $issues[] = Message::create(
                ManifestMessageCode::MANIFEST_INVALID_KEY,
                ManifestMessageKey::MANIFEST_INVALID_KEY,
                ['%key%' => $key],
                [
                    'source' => $candidate->source()->name(),
                    'path' => $candidate->manifestPath(),
                    'key' => $key,
                    'expected_prefix' => 'EXTENSION_',
                ],
                MessageLevel::Error,
            );
        }

        return $issues;
    }

    /**
     * @return list<Message>
     */
    private function validateDependencySyntax(ExtensionCandidate $candidate): array
    {
        $value = $candidate->manifest()->get('EXTENSION_DEPENDENCIES');

        if (null !== $this->dependencyParser->parse($value)) {
            return [];
        }

        return [
            Message::create(
                ExtensionMessageCode::EXTENSION_DEPENDENCY_INVALID,
                ExtensionMessageKey::EXTENSION_DEPENDENCY_INVALID,
                ['%extension%' => trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''))],
                [
                    'source' => $candidate->source()->name(),
                    'path' => $candidate->manifestPath(),
                    'key' => 'EXTENSION_DEPENDENCIES',
                    'value' => $value,
                ],
                MessageLevel::Error,
            ),
        ];
    }
}
