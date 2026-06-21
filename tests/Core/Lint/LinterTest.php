<?php

declare(strict_types=1);

namespace App\Tests\Core\Lint;

use App\Core\Lint\CssLinter;
use App\Core\Lint\JavaScriptLinter;
use App\Core\Lint\JsonLinter;
use App\Core\Lint\LinterInterface;
use App\Core\Lint\PhpLinter;
use App\Core\Lint\TwigLinter;
use App\Core\Lint\YamlLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: LinterInterface, 1: string}>
     */
    public static function validSourceProvider(): iterable
    {
        yield 'php' => [new PhpLinter(), '<?php class ValidLintSource {}'];
        yield 'twig' => [new TwigLinter(), '<main>{{ title }}</main>'];
        yield 'twig extensions' => [new TwigLinter(), '<a href="{{ path("demo_route") }}">{{ "ext.demo-module.title"|trans }}</a>{% if item is studio_visible %}{{ extension_settings("demo-module")|length }}{% endif %}'];
        yield 'json' => [new JsonLinter(), '{"enabled": true}'];
        yield 'yaml' => [new YamlLinter(), 'enabled: true'];
        yield 'css' => [new CssLinter(), 'body { color: red; }'];
        yield 'javascript' => [new JavaScriptLinter(), 'export default true;'];
    }

    #[DataProvider('validSourceProvider')]
    public function testItAcceptsValidSource(LinterInterface $linter, string $source): void
    {
        $result = $linter->lint($source, 'virtual/path');

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->issues());
    }

    /**
     * @return iterable<string, array{0: LinterInterface, 1: string, 2: string}>
     */
    public static function invalidSourceProvider(): iterable
    {
        yield 'php' => [new PhpLinter(), '<?php class BrokenLintSource {', 'lint.php_syntax_error'];
        yield 'twig' => [new TwigLinter(), '<main>{% if title %}</main>', 'lint.twig_syntax_error'];
        yield 'json' => [new JsonLinter(), '{', 'lint.json_syntax_error'];
        yield 'yaml' => [new YamlLinter(), 'enabled: [', 'lint.yaml_syntax_error'];
        yield 'css' => [new CssLinter(), 'body { color: ; }', 'lint.css_syntax_error'];
        yield 'javascript' => [new JavaScriptLinter(), 'const = ;', 'lint.javascript_syntax_error'];
    }

    #[DataProvider('invalidSourceProvider')]
    public function testItReportsInvalidSource(LinterInterface $linter, string $source, string $expectedCode): void
    {
        $result = $linter->lint($source, 'virtual/path');

        self::assertFalse($result->isSuccess());
        self::assertSame($expectedCode, $result->firstIssue()?->code());
        self::assertSame('virtual/path', $result->firstIssue()?->details()['path']);
        self::assertArrayHasKey('error', $result->firstIssue()?->context());
    }

    public function testCssLinterAcceptsCommentOnlyRegistryStubs(): void
    {
        $result = (new CssLinter())->lint(<<<'CSS'
/* Generated CSS extension asset registry. */
/* Extension lifecycle owns this file after activation changes. */
CSS);

        self::assertTrue($result->isSuccess());
    }
}
