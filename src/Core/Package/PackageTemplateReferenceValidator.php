<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;

final readonly class PackageTemplateReferenceValidator
{
    public function __construct(private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory())
    {
    }

    /**
     * @param list<string> $templateFiles
     *
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, array $templateFiles): array
    {
        $issues = [];

        foreach ($templateFiles as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            foreach ($this->references($contents) as $reference) {
                if ($this->isAllowedReference($file, $reference)) {
                    continue;
                }

                $issues[] = Message::create(
                    PackageMessageCode::PACKAGE_TEMPLATE_REFERENCE_INVALID,
                    PackageMessageKey::PACKAGE_TEMPLATE_REFERENCE_INVALID,
                    ['%path%' => $path, '%reference%' => $reference],
                    context: $this->issueFactory->fileContext($candidate, $file, $path, [
                        'reference' => $reference,
                    ]),
                    level: MessageLevel::Error,
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function references(string $contents): array
    {
        preg_match_all(
            '/{%\s*(?:extends|include|embed|import|from)\s+[\'"](?P<reference>@(?:root|frontend|backend|provider)\/[^\'"]+)[\'"]/i',
            $contents,
            $matches,
        );

        return array_values(array_unique($matches['reference'] ?? []));
    }

    private function isAllowedReference(string $file, string $reference): bool
    {
        if (str_starts_with($reference, '@root/')) {
            return true;
        }

        if (str_starts_with($file, 'templates/frontend/')) {
            return str_starts_with($reference, '@frontend/');
        }

        if (str_starts_with($file, 'templates/backend/')) {
            return str_starts_with($reference, '@backend/');
        }

        if (preg_match('#\Atemplates/provider/([a-z][a-z0-9-]*)/#', $file, $matches)) {
            return str_starts_with($reference, '@provider/'.$matches[1].'/');
        }

        return false;
    }
}
