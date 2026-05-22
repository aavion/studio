<?php

declare(strict_types=1);

namespace App\Core\Lint;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

final class TwigLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        $twig = new Environment(new ArrayLoader([]));

        try {
            $source = new Source($contents, $path ?? 'inline.twig', $path ?? '');
            $twig->parse($twig->tokenize($source));
        } catch (SyntaxError $error) {
            return LintResult::invalid([
                LintIssue::create(
                    'lint.twig_syntax_error',
                    'Twig has a syntax error.',
                    $error->getTemplateLine(),
                    details: ['error' => $error->getRawMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
