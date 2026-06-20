<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionPhpCapabilityPolicy
{
    /**
     * @var array<string, string>
     */
    private const BLOCKED_FUNCTIONS = [
        'chmod' => 'direct_filesystem',
        'chown' => 'direct_filesystem',
        'call_user_func' => 'dynamic_callable',
        'call_user_func_array' => 'dynamic_callable',
        'copy' => 'direct_filesystem',
        'curl_exec' => 'direct_network',
        'curl_init' => 'direct_network',
        'exec' => 'direct_process',
        'forward_static_call' => 'dynamic_callable',
        'forward_static_call_array' => 'dynamic_callable',
        'file' => 'direct_filesystem',
        'file_exists' => 'direct_filesystem',
        'file_get_contents' => 'direct_filesystem',
        'file_put_contents' => 'direct_filesystem',
        'filemtime' => 'direct_filesystem',
        'filesize' => 'direct_filesystem',
        'fopen' => 'direct_filesystem',
        'fsockopen' => 'direct_network',
        'getenv' => 'direct_environment',
        'glob' => 'direct_filesystem',
        'is_dir' => 'direct_filesystem',
        'is_file' => 'direct_filesystem',
        'link' => 'direct_filesystem',
        'mkdir' => 'direct_filesystem',
        'opendir' => 'direct_filesystem',
        'parse_ini_file' => 'direct_filesystem',
        'passthru' => 'direct_process',
        'pfsockopen' => 'direct_network',
        'popen' => 'direct_process',
        'proc_open' => 'direct_process',
        'putenv' => 'direct_environment',
        'readfile' => 'direct_filesystem',
        'readlink' => 'direct_filesystem',
        'realpath' => 'direct_filesystem',
        'rename' => 'direct_filesystem',
        'rmdir' => 'direct_filesystem',
        'scandir' => 'direct_filesystem',
        'shell_exec' => 'direct_process',
        'socket_create' => 'direct_network',
        'stream_socket_client' => 'direct_network',
        'symlink' => 'direct_filesystem',
        'system' => 'direct_process',
        'tempnam' => 'direct_filesystem',
        'tmpfile' => 'direct_filesystem',
        'touch' => 'direct_filesystem',
        'unlink' => 'direct_filesystem',
    ];

    /**
     * @var array<string, string>
     */
    private const BLOCKED_CLASSES = [
        'directoryiterator' => 'direct_filesystem',
        'filesystemiterator' => 'direct_filesystem',
        'recursivecallbackfilteriterator' => 'direct_filesystem',
        'recursivedirectoryiterator' => 'direct_filesystem',
        'splfileinfo' => 'direct_filesystem',
        'splfileobject' => 'direct_filesystem',
        'reflectionclass' => 'dynamic_introspection',
        'reflectionfunction' => 'dynamic_introspection',
        'reflectionmethod' => 'dynamic_introspection',
        'ziparchive' => 'direct_filesystem',
    ];

    /**
     * @var array<int, string>
     */
    private const BLOCKED_LANGUAGE_TOKENS = [
        T_EVAL => 'dynamic_code',
        T_INCLUDE => 'direct_filesystem',
        T_INCLUDE_ONCE => 'direct_filesystem',
        T_REQUIRE => 'direct_filesystem',
        T_REQUIRE_ONCE => 'direct_filesystem',
    ];

    /**
     * @var array<string, string>
     */
    private const BLOCKED_SUPERGLOBALS = [
        '$_ENV' => 'direct_environment',
        '$_FILES' => 'direct_request_context',
        '$_GET' => 'direct_request_context',
        '$_POST' => 'direct_request_context',
        '$_COOKIE' => 'direct_request_context',
        '$_REQUEST' => 'direct_request_context',
        '$_SESSION' => 'direct_request_context',
        '$_SERVER' => 'direct_request_context',
    ];

    public function __construct(private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory())
    {
    }

    /**
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, ExtensionInspection $inspection): array
    {
        if ('extension' !== $candidate->source()->name()) {
            return [];
        }

        $issues = [];

        foreach ($inspection->phpFiles() as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            array_push($issues, ...$this->scan($candidate, $file, $path, $contents));
        }

        return $issues;
    }

    /**
     * @return list<Message>
     */
    private function scan(ExtensionCandidate $candidate, string $file, string $path, string $contents): array
    {
        $issues = [];
        $tokens = token_get_all($contents);

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if (isset(self::BLOCKED_LANGUAGE_TOKENS[$token[0]])) {
                $issues[] = $this->issue($candidate, $file, $path, token_name($token[0]), self::BLOCKED_LANGUAGE_TOKENS[$token[0]]);
                continue;
            }

            if (T_VARIABLE === $token[0] && isset(self::BLOCKED_SUPERGLOBALS[$token[1]])) {
                $issues[] = $this->issue($candidate, $file, $path, $token[1], self::BLOCKED_SUPERGLOBALS[$token[1]]);
                continue;
            }

            if (T_VARIABLE === $token[0] && $this->isFunctionCall($tokens, $index)) {
                $issues[] = $this->issue($candidate, $file, $path, $token[1].'()', 'dynamic_callable');
                continue;
            }

            if (T_CONSTANT_ENCAPSED_STRING === $token[0] && $this->isStringLiteralCall($tokens, $index)) {
                $name = $this->policyName($this->literalStringValue((string) $token[1]));

                $issues[] = $this->issue(
                    $candidate,
                    $file,
                    $path,
                    $this->literalStringValue((string) $token[1]).'()',
                    self::BLOCKED_FUNCTIONS[$name] ?? 'dynamic_callable',
                );
                continue;
            }

            if (!in_array($token[0], $this->nameTokenIds(), true)) {
                continue;
            }

            $name = $this->policyName((string) $token[1]);

            if ($this->isFunctionCall($tokens, $index) && isset(self::BLOCKED_FUNCTIONS[$name])) {
                $issues[] = $this->issue($candidate, $file, $path, (string) $token[1], self::BLOCKED_FUNCTIONS[$name]);
                continue;
            }

            if ($this->isNewClassName($tokens, $index) && isset(self::BLOCKED_CLASSES[$name])) {
                $issues[] = $this->issue($candidate, $file, $path, (string) $token[1], self::BLOCKED_CLASSES[$name]);
            }
        }

        return $issues;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function isFunctionCall(array $tokens, int $index): bool
    {
        $next = $this->nextSignificantToken($tokens, $index);
        if ('(' !== $next) {
            return false;
        }

        $previous = $this->previousSignificantToken($tokens, $index);

        return !in_array($previous, ['->', '?->', '::', 'function', 'new'], true);
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function isStringLiteralCall(array $tokens, int $index): bool
    {
        if ('(' === $this->nextSignificantToken($tokens, $index)) {
            return true;
        }

        if ('(' !== $this->previousSignificantToken($tokens, $index)) {
            return false;
        }

        $closeIndex = $this->nextSignificantTokenIndex($tokens, $index);

        return null !== $closeIndex
            && ')' === $this->significantTokenValue($tokens[$closeIndex])
            && '(' === $this->nextSignificantToken($tokens, $closeIndex);
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function isNewClassName(array $tokens, int $index): bool
    {
        return 'new' === $this->previousSignificantToken($tokens, $index);
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function previousSignificantToken(array $tokens, int $index): ?string
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $value = $this->significantTokenValue($tokens[$i]);

            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextSignificantToken(array $tokens, int $index): ?string
    {
        $nextIndex = $this->nextSignificantTokenIndex($tokens, $index);

        return null === $nextIndex ? null : $this->significantTokenValue($tokens[$nextIndex]);
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; ++$i) {
            $value = $this->significantTokenValue($tokens[$i]);

            if (null !== $value) {
                return $i;
            }
        }

        return null;
    }

    private function significantTokenValue(mixed $token): ?string
    {
        if (is_string($token)) {
            return $token;
        }

        if (!is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return null;
        }

        return match ($token[0]) {
            T_DOUBLE_COLON => '::',
            T_FUNCTION => 'function',
            T_NEW => 'new',
            T_OBJECT_OPERATOR => '->',
            defined('T_NULLSAFE_OBJECT_OPERATOR') ? constant('T_NULLSAFE_OBJECT_OPERATOR') : -1 => '?->',
            default => strtolower((string) $token[1]),
        };
    }

    /**
     * @return list<int>
     */
    private function nameTokenIds(): array
    {
        $ids = [T_STRING];

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $ids[] = constant($constant);
            }
        }

        return $ids;
    }

    private function policyName(string $name): string
    {
        $name = strtolower(ltrim($name, '\\'));
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    private function literalStringValue(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        if ("'" === $quote) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }

        return stripcslashes($value);
    }

    private function issue(ExtensionCandidate $candidate, string $file, string $path, string $capability, string $reason): Message
    {
        return Message::create(
            ExtensionMessageCode::EXTENSION_POLICY_BLOCKED_PHP_CAPABILITY,
            ExtensionMessageKey::EXTENSION_POLICY_BLOCKED_PHP_CAPABILITY,
            ['%path%' => $path, '%capability%' => $capability, '%reason%' => $reason],
            context: $this->issueFactory->fileContext($candidate, $file, $path, [
                'capability' => $capability,
                'reason' => $reason,
                'policy' => 'extension.php_capability',
            ]),
            level: MessageLevel::Error,
        );
    }
}
