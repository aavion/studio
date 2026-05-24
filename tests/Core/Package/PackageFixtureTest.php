<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageDiscovery;
use App\Core\Package\PackageSource;
use App\Core\Package\PackageSpec;
use App\Core\Package\PackageValidator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class PackageFixtureTest extends TestCase
{
    use FilesystemTestHelper;

    public function testFixturePackagesCanBeDiscoveredAndValidated(): void
    {
        $root = $this->fixturePath('packages');
        $result = (new PackageDiscovery())->discover($root, 'test');

        self::assertTrue($result->isSuccess());
        self::assertSame(['app', 'package', 'package', 'import'], array_map(
            static fn (PackageCandidate $candidate): string => $candidate->source()->name(),
            $result->value(),
        ));

        $validator = new PackageValidator();

        foreach ($result->value() as $candidate) {
            $validationResult = $validator->validate($candidate, $this->specFor($candidate->source()->name()));

            self::assertTrue($validationResult->isSuccess(), sprintf('Fixture package "%s" should validate.', $candidate->directory()));
        }
    }

    public function testInvalidFixturePackagesExposeExpectedDiagnostics(): void
    {
        $root = $this->fixturePath('packages-invalid');
        $result = (new PackageDiscovery())->discoverSources($root, [
            PackageSource::children('fixture', '.'),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.invalid_line', $result->firstIssue()?->code());

        $candidates = $result->context()['candidates'];
        self::assertContainsOnlyInstancesOf(PackageCandidate::class, $candidates);
        self::assertCount(2, $candidates);

        $byDirectory = [];
        foreach ($candidates as $candidate) {
            $byDirectory[basename($candidate->directory())] = $candidate;
        }

        $missingResult = (new PackageValidator())->validate($byDirectory['missing-package-files'], PackageSpec::create()
            ->requireDirectory('templates')
            ->requireDirectory('assets'));

        self::assertFalse($missingResult->isSuccess());
        self::assertSame([
            'package.required_directory_missing',
            'package.required_directory_missing',
        ], array_map(static fn ($issue): string => $issue->code(), $missingResult->issues()));

        $lintResult = (new PackageValidator())->validate($byDirectory['broken-lint'], PackageSpec::create()->withLintingChecks());

        self::assertFalse($lintResult->isSuccess());
        self::assertSame([
            'package.php_syntax_error',
            'package.twig_syntax_error',
            'package.json_syntax_error',
            'package.yaml_syntax_error',
            'package.css_syntax_error',
            'package.javascript_syntax_error',
        ], array_map(static fn ($issue): string => $issue->code(), $lintResult->issues()));
    }

    private function specFor(string $source): PackageSpec
    {
        return match ($source) {
            'app' => PackageSpec::create()
                ->requireFile('.manifest'),
            'package' => PackageSpec::create()
                ->requireFile('.manifest')
                ->withLintingChecks(),
            'import' => PackageSpec::create()
                ->requireFile('.manifest')
                ->withLintingChecks(),
            default => PackageSpec::create(),
        };
    }
}
