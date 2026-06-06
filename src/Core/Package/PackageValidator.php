<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestMessageCode;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Workflow\WorkflowResult;

final class PackageValidator
{
    public function __construct(
        private readonly PackageInventoryInspector $inventoryInspector = new PackageInventoryInspector(),
        private readonly PackageRequiredPathValidator $requiredPathValidator = new PackageRequiredPathValidator(),
        private readonly PackageTemplatePathValidator $templatePathValidator = new PackageTemplatePathValidator(),
        private readonly PackageTemplateReferenceValidator $templateReferenceValidator = new PackageTemplateReferenceValidator(),
        private readonly PackageFilePolicy $filePolicy = new PackageFilePolicy(),
        private readonly PackagePhpCapabilityPolicy $phpCapabilityPolicy = new PackagePhpCapabilityPolicy(),
        private readonly PackageFileSyntaxValidator $fileSyntaxValidator = new PackageFileSyntaxValidator(),
        private readonly PackageCssNamespaceValidator $cssNamespaceValidator = new PackageCssNamespaceValidator(),
        private readonly PackageSourceNamespaceValidator $sourceNamespaceValidator = new PackageSourceNamespaceValidator(),
        private readonly PackageTranslationNamespaceValidator $translationNamespaceValidator = new PackageTranslationNamespaceValidator(),
        private readonly PackageSchedulerCronValidator $schedulerCronValidator = new PackageSchedulerCronValidator(),
        private readonly PackageDependencyParser $dependencyParser = new PackageDependencyParser(),
    ) {
    }

    /**
     * @return WorkflowResult<PackageCandidate>
     */
    public function validate(PackageCandidate $candidate, PackageSpec $spec): WorkflowResult
    {
        $issues = [
            ...$this->validatePackageSlug($candidate),
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
            Message::debug(PackageMessageCode::PACKAGE_VALIDATION_COMPLETED, PackageMessageKey::PACKAGE_VALIDATION_COMPLETED, [
                '%package%' => $candidate->directory(),
            ], [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'inventory_count' => count($inspection->inventory()),
            ]),
        ]);
    }

    /**
     * @return list<Message>
     */
    private function validatePackageSlug(PackageCandidate $candidate): array
    {
        if ('package' !== $candidate->source()->name()) {
            return [];
        }

        $slug = trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''));

        if ('' === $slug) {
            return [
                Message::create(
                    ManifestMessageCode::MANIFEST_MISSING_REQUIRED_KEY,
                    ManifestMessageKey::MANIFEST_MISSING_REQUIRED_KEY,
                    ['%key%' => 'PACKAGE_SLUG'],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'PACKAGE_SLUG'],
                    MessageLevel::Error,
                ),
            ];
        }

        if (!PackageManifestSpec::isValidSlug($slug)) {
            return [
                Message::create(
                    PackageMessageCode::PACKAGE_IDENTIFIER_INVALID,
                    PackageMessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['source' => $candidate->source()->name(), 'path' => $candidate->manifestPath(), 'key' => 'PACKAGE_SLUG', 'slug' => $slug],
                    MessageLevel::Error,
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<Message>
     */
    private function validateDependencySyntax(PackageCandidate $candidate): array
    {
        $value = $candidate->manifest()->get('PACKAGE_DEPENDENCIES');

        if (null !== $this->dependencyParser->parse($value)) {
            return [];
        }

        return [
            Message::create(
                PackageMessageCode::PACKAGE_DEPENDENCY_INVALID,
                PackageMessageKey::PACKAGE_DEPENDENCY_INVALID,
                ['%package%' => trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''))],
                [
                    'source' => $candidate->source()->name(),
                    'path' => $candidate->manifestPath(),
                    'key' => 'PACKAGE_DEPENDENCIES',
                    'value' => $value,
                ],
                MessageLevel::Error,
            ),
        ];
    }
}
