<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;

final readonly class PackageSourceNamespaceValidator
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
        $expectedNamespace = trim((string) ($candidate->manifest()->get('PACKAGE_NAMESPACE') ?? ''));

        if ('' === $expectedNamespace || [] === $files) {
            return [];
        }

        if (!$this->isValidPhpNamespace($expectedNamespace)) {
            return [$this->invalidNamespaceIssue($candidate, 'PACKAGE_NAMESPACE', 'PACKAGE_NAMESPACE', $expectedNamespace, $expectedNamespace)];
        }

        $issues = [];

        foreach ($files as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            $namespace = $this->declaredPhpNamespace($contents);

            if (!$this->isPackageNamespace($namespace, $expectedNamespace)) {
                $issues[] = $this->invalidNamespaceIssue($candidate, $file, $path, $namespace ?? '', $expectedNamespace);
            }
        }

        return $issues;
    }

    private function isValidPhpNamespace(string $namespace): bool
    {
        return 1 === preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*$/', $namespace);
    }

    private function isPackageNamespace(?string $namespace, string $expectedNamespace): bool
    {
        return null !== $namespace
            && ($namespace === $expectedNamespace || str_starts_with($namespace, $expectedNamespace.'\\'));
    }

    private function declaredPhpNamespace(string $contents): ?string
    {
        $tokens = token_get_all($contents);
        $namespace = '';
        $collect = false;

        foreach ($tokens as $token) {
            if (is_array($token) && T_NAMESPACE === $token[0]) {
                $collect = true;
                continue;
            }

            if (!$collect) {
                continue;
            }

            if (is_string($token) && (';' === $token || '{' === $token)) {
                break;
            }

            if (is_array($token) && T_WHITESPACE === $token[0]) {
                continue;
            }

            if (is_array($token) && in_array($token[0], $this->namespaceTokenIds(), true)) {
                $namespace .= $token[1];
                continue;
            }

            if (is_string($token) && '\\' === $token) {
                $namespace .= $token;
            }
        }

        $namespace = trim($namespace, '\\');

        return '' === $namespace ? null : $namespace;
    }

    /**
     * @return list<int>
     */
    private function namespaceTokenIds(): array
    {
        $ids = [T_STRING, T_NS_SEPARATOR];

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $ids[] = constant($constant);
            }
        }

        return $ids;
    }

    private function invalidNamespaceIssue(
        PackageCandidate $candidate,
        string $file,
        string $path,
        string $namespace,
        string $expectedNamespace,
    ): Message {
        return Message::create(
            MessageCode::PACKAGE_PHP_NAMESPACE_INVALID,
            MessageKey::PACKAGE_PHP_NAMESPACE_INVALID,
            ['%path%' => $path, '%expected_namespace%' => $expectedNamespace],
            context: $this->issueFactory->fileContext($candidate, $file, $path, [
                'namespace' => $namespace,
                'expected_namespace' => $expectedNamespace,
            ]),
            level: MessageLevel::Error,
        );
    }
}
