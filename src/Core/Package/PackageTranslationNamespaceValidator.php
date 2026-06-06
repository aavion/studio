<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class PackageTranslationNamespaceValidator
{
    public function __construct(private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory())
    {
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, array $files): array
    {
        $packageName = $this->translationPackageName($candidate);
        $translationFiles = array_values(array_filter(
            $files,
            static fn (string $file): bool => 1 === preg_match('#^languages/[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*/[^/]+\.yaml$#', $file),
        ));
        $issues = [];

        if ([] === $translationFiles) {
            return [];
        }

        if ([] === array_filter($translationFiles, static fn (string $file): bool => str_starts_with($file, 'languages/en/'))) {
            $issues[] = Message::create(
                MessageCode::PACKAGE_TRANSLATION_ENGLISH_MISSING,
                MessageKey::PACKAGE_TRANSLATION_ENGLISH_MISSING,
                ['%package%' => $packageName],
                context: $this->issueFactory->fileContext($candidate, 'languages/en', $candidate->directory().DIRECTORY_SEPARATOR.'languages/en', [
                    'package' => $packageName,
                ]),
                level: MessageLevel::Error,
            );
        }

        foreach ($translationFiles as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;

            try {
                $data = Yaml::parseFile($path);
            } catch (Throwable) {
                continue;
            }

            if (
                !is_array($data)
                || array_keys($data) !== ['pkg']
                || !isset($data['pkg'])
                || !is_array($data['pkg'])
                || array_keys($data['pkg']) !== [$packageName]
            ) {
                $issues[] = Message::create(
                    MessageCode::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
                    MessageKey::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
                    ['%path%' => $path, '%package%' => $packageName],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, [
                        'package' => $packageName,
                        'expected_prefix' => 'pkg.'.$packageName,
                    ]),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    private function translationPackageName(PackageCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''));

        return PackageManifestSpec::isValidSlug($slug) ? $slug : basename($candidate->directory());
    }
}
