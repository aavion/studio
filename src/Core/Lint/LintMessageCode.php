<?php

declare(strict_types=1);

namespace App\Core\Lint;

final class LintMessageCode
{
    public const LINT_PHP_UNREADABLE = 'lint.php_unreadable';
    public const LINT_PHP_SYNTAX_ERROR = 'lint.php_syntax_error';
    public const LINT_TWIG_SYNTAX_ERROR = 'lint.twig_syntax_error';
    public const LINT_JSON_SYNTAX_ERROR = 'lint.json_syntax_error';
    public const LINT_YAML_SYNTAX_ERROR = 'lint.yaml_syntax_error';
    public const LINT_CSS_SYNTAX_ERROR = 'lint.css_syntax_error';
    public const LINT_JAVASCRIPT_SYNTAX_ERROR = 'lint.javascript_syntax_error';
}
