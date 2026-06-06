<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Localization\LocaleToken;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class PackageTranslationNamespaceValidator
{
    /**
     * @var non-empty-list<string>
     */
    private array $fallbackLocaleCandidates;

    public function __construct(
        private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory(),
        string $fallbackLocale = 'en',
    ) {
        $this->fallbackLocaleCandidates = $this->fallbackLocaleCandidates($fallbackLocale);
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

        if (!$this->hasFallbackCatalogue($translationFiles)) {
            $expectedPath = 'languages/'.$this->fallbackLocaleCandidates[0];
            $issues[] = Message::create(
                PackageMessageCode::PACKAGE_TRANSLATION_FALLBACK_MISSING,
                PackageMessageKey::PACKAGE_TRANSLATION_FALLBACK_MISSING,
                ['%package%' => $packageName, '%locale%' => $this->fallbackLocaleCandidates[0]],
                context: $this->issueFactory->fileContext($candidate, $expectedPath, $candidate->directory().DIRECTORY_SEPARATOR.$expectedPath, [
                    'package' => $packageName,
                    'fallback_locale' => $this->fallbackLocaleCandidates[0],
                    'fallback_locale_candidates' => $this->fallbackLocaleCandidates,
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
                    PackageMessageCode::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
                    PackageMessageKey::PACKAGE_TRANSLATION_NAMESPACE_INVALID,
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

    /**
     * @param list<string> $translationFiles
     */
    private function hasFallbackCatalogue(array $translationFiles): bool
    {
        foreach ($this->fallbackLocaleCandidates as $locale) {
            foreach ($translationFiles as $file) {
                if (str_starts_with($file, 'languages/'.$locale.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return non-empty-list<string>
     */
    private function fallbackLocaleCandidates(string $locale): array
    {
        $normalized = str_replace('-', '_', trim($locale));
        if (!LocaleToken::isValid($normalized)) {
            $normalized = 'en';
        }

        $candidates = [$normalized];
        $hyphenated = str_replace('_', '-', $normalized);
        if ($hyphenated !== $normalized) {
            $candidates[] = $hyphenated;
        }

        $primary = preg_replace('/[_-].*$/', '', $normalized) ?: $normalized;
        if ($primary !== $normalized) {
            $candidates[] = $primary;
        }

        return array_values(array_unique($candidates));
    }
}
