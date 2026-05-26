<?php

declare(strict_types=1);

namespace App\Core\Lint;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

final class TwigLinter implements LinterInterface
{
    public function lint(string $contents, ?string $path = null): LintResult
    {
        $twig = new Environment(new ArrayLoader([]));
        $twig->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (): mixed => null));
        $twig->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): mixed => null));
        $twig->registerUndefinedTestCallback(static fn (string $name): TwigTest => new TwigTest($name, static fn (): bool => false));

        try {
            $source = new Source($contents, $path ?? 'inline.twig', $path ?? '');
            $twig->parse($twig->tokenize($source));
        } catch (SyntaxError $error) {
            return LintResult::invalid([
                LintIssue::create(
                    MessageCode::LINT_TWIG_SYNTAX_ERROR,
                    MessageKey::LINT_TWIG_SYNTAX_ERROR,
                    $error->getTemplateLine(),
                    details: ['error' => $error->getRawMessage(), 'path' => $path],
                ),
            ]);
        }

        return LintResult::success();
    }
}
