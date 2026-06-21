<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionCandidate;
use App\Core\Extension\ExtensionDiscovery;
use App\Core\Extension\ExtensionSource;
use App\Core\Extension\ExtensionSpec;
use App\Core\Extension\ExtensionValidator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionFixtureTest extends TestCase
{
    use FilesystemTestHelper;

    public function testFixtureExtensionsCanBeDiscoveredAndValidated(): void
    {
        $root = $this->fixturePath('extensions');
        $result = (new ExtensionDiscovery())->discover($root, 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame(['app', 'extension', 'extension', 'import'], array_map(
            static fn (ExtensionCandidate $candidate): string => $candidate->source()->name(),
            $result->value(),
        ));

        $validator = new ExtensionValidator();

        foreach ($result->value() as $candidate) {
            $validationResult = $validator->validate($candidate, $this->specFor($candidate->source()->name()));

            self::assertTrue($validationResult->isSuccess(), sprintf('Fixture extension "%s" should validate.', $candidate->directory()));
        }
    }

    public function testInvalidFixtureExtensionsExposeExpectedDiagnostics(): void
    {
        $root = $this->fixturePath('extensions-invalid');
        $result = (new ExtensionDiscovery())->discoverSources($root, [
            ExtensionSource::children('fixture', '.'),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.invalid_line', $result->firstIssue()?->code());

        $candidates = $result->context()['candidates'];
        self::assertContainsOnlyInstancesOf(ExtensionCandidate::class, $candidates);
        self::assertCount(2, $candidates);

        $byDirectory = [];
        foreach ($candidates as $candidate) {
            $byDirectory[basename($candidate->directory())] = $candidate;
        }

        $missingResult = (new ExtensionValidator())->validate($byDirectory['missing-extension-files'], ExtensionSpec::create()
            ->requireDirectory('templates')
            ->requireDirectory('assets'));

        self::assertFalse($missingResult->isSuccess());
        self::assertSame([
            'extension.required_directory_missing',
            'extension.required_directory_missing',
        ], array_map(static fn ($issue): string => $issue->code(), $missingResult->issues()));

        $lintResult = (new ExtensionValidator())->validate($byDirectory['broken-lint'], ExtensionSpec::create()->withLintingChecks());

        self::assertFalse($lintResult->isSuccess());
        self::assertSame([
            'extension.php_syntax_error',
            'extension.twig_syntax_error',
            'extension.json_syntax_error',
            'extension.yaml_syntax_error',
            'extension.css_syntax_error',
            'extension.javascript_syntax_error',
        ], array_map(static fn ($issue): string => $issue->code(), $lintResult->issues()));
    }

    private function specFor(string $source): ExtensionSpec
    {
        return match ($source) {
            'app' => ExtensionSpec::create()
                ->requireFile('.manifest'),
            'extension' => ExtensionSpec::create()
                ->requireFile('.manifest')
                ->withLintingChecks(),
            'import' => ExtensionSpec::create()
                ->requireFile('.manifest')
                ->withLintingChecks(),
            default => ExtensionSpec::create(),
        };
    }
}
