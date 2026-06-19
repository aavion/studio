<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class ExtensionTranslationNamespaceValidator
{
    /**
     * @var non-empty-list<string>
     */
    private array $fallbackLocaleCandidates;

    public function __construct(
        private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory(),
    ) {
        $this->fallbackLocaleCandidates = ['en'];
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, array $files): array
    {
        $extensionName = $this->translationExtensionName($candidate);
        $translationFiles = array_values(array_filter(
            $files,
            static fn (string $file): bool => 1 === preg_match('#^languages/[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*/[^/]+\.ya?ml$#', $file),
        ));
        $issues = [];

        if ([] === $translationFiles) {
            return [];
        }

        if (!$this->hasFallbackCatalogue($translationFiles)) {
            $expectedPath = 'languages/'.$this->fallbackLocaleCandidates[0];
            $issues[] = Message::create(
                ExtensionMessageCode::EXTENSION_TRANSLATION_FALLBACK_MISSING,
                ExtensionMessageKey::EXTENSION_TRANSLATION_FALLBACK_MISSING,
                ['%extension%' => $extensionName, '%locale%' => $this->fallbackLocaleCandidates[0]],
                context: $this->issueFactory->fileContext($candidate, $expectedPath, $candidate->directory().DIRECTORY_SEPARATOR.$expectedPath, [
                    'extension' => $extensionName,
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
                || array_keys($data) !== ['ext']
                || !isset($data['ext'])
                || !is_array($data['ext'])
                || array_keys($data['ext']) !== [$extensionName]
            ) {
                $issues[] = Message::create(
                    ExtensionMessageCode::EXTENSION_TRANSLATION_NAMESPACE_INVALID,
                    ExtensionMessageKey::EXTENSION_TRANSLATION_NAMESPACE_INVALID,
                    ['%path%' => $path, '%extension%' => $extensionName],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, [
                        'extension' => $extensionName,
                        'expected_prefix' => 'ext.'.$extensionName,
                    ]),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    private function translationExtensionName(ExtensionCandidate $candidate): string
    {
        $slug = trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''));

        return ExtensionManifestSpec::isValidSlug($slug) ? $slug : basename($candidate->directory());
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

}
