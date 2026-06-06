<?php

declare(strict_types=1);

namespace App\Core\Lint;

final class LintMessageKey
{
    public const LINT_PHP_UNREADABLE = 'message.lint.php_unreadable';
    public const LINT_PHP_SYNTAX_ERROR = 'message.lint.php_syntax_error';
    public const LINT_TWIG_SYNTAX_ERROR = 'message.lint.twig_syntax_error';
    public const LINT_JSON_SYNTAX_ERROR = 'message.lint.json_syntax_error';
    public const LINT_YAML_SYNTAX_ERROR = 'message.lint.yaml_syntax_error';
    public const LINT_CSS_SYNTAX_ERROR = 'message.lint.css_syntax_error';
    public const LINT_JAVASCRIPT_SYNTAX_ERROR = 'message.lint.javascript_syntax_error';
}
