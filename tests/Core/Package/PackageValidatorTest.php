<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Manifest\Manifest;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageInspection;
use App\Core\Package\PackageSource;
use App\Core\Package\PackageSpec;
use App\Core\Package\PackageValidator;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageValidatorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $packageDir;

    protected function setUp(): void
    {
        $this->packageDir = $this->createTemporaryDirectory('studio-package-validator');
        file_put_contents($this->packageDir.'/.manifest', 'THEME_NAME=System');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->packageDir);
    }

    public function testItAcceptsPackagesWithRequiredFilesAndDirectories(): void
    {
        $this->writeFile('templates/base.html.twig', 'layout');
        $this->writeFile('assets/app.css', 'body {}');

        $candidate = $this->candidate();
        $spec = PackageSpec::create()
            ->requireFile('.manifest')
            ->requireFile('templates/base.html.twig')
            ->requireDirectory('assets');

        $result = (new PackageValidator())->validate($candidate, $spec);

        self::assertTrue($result->isSuccess());
        self::assertSame($candidate, $result->value());
        self::assertContains('.manifest', $result->context()['inventory']);
        self::assertContains('templates/base.html.twig', $result->context()['inventory']);
        self::assertContains('assets/', $result->context()['inventory']);
    }

    public function testItExposesPackageInspectionFeatures(): void
    {
        $this->writeFile('templates/base.html.twig', '<main></main>');
        $this->writeFile('assets/app.css', 'body {}');
        $this->writeFile('assets/app.js', 'export default true;');
        $this->writeFile('config/package.yaml', 'enabled: true');
        $this->writeFile('config/package.json', '{"enabled": true}');
        $this->writeFile('src/ThemeExtension.php', '<?php class ThemeExtension {}');
        $this->writeFile('tools/helper.php', '<?php return true;');

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(PackageInspection::class, $result->context()['inspection']);

        /** @var PackageInspection $inspection */
        $inspection = $result->context()['inspection'];

        self::assertTrue($inspection->hasTemplates());
        self::assertTrue($inspection->hasAssets());
        self::assertTrue($inspection->hasPhpFiles());
        self::assertTrue($inspection->hasSourcePhpFiles());
        self::assertTrue($inspection->hasTwigFiles());
        self::assertTrue($inspection->hasJsonFiles());
        self::assertTrue($inspection->hasYamlFiles());
        self::assertTrue($inspection->hasCssFiles());
        self::assertTrue($inspection->hasJavaScriptFiles());
        self::assertSame(['templates/base.html.twig'], $inspection->templateFiles());
        self::assertSame(['assets/app.css', 'assets/app.js'], $inspection->assetFiles());
        self::assertSame(['src/ThemeExtension.php'], $inspection->sourcePhpFiles());
        self::assertSame(['src/ThemeExtension.php', 'tools/helper.php'], $inspection->phpFiles());
        self::assertSame(['config/package.json'], $inspection->jsonFiles());
        self::assertSame(['config/package.yaml'], $inspection->yamlFiles());
        self::assertSame(['assets/app.css'], $inspection->cssFiles());
        self::assertSame(['assets/app.js'], $inspection->javaScriptFiles());
    }

    public function testItReportsMissingRequiredFilesAndDirectories(): void
    {
        $candidate = $this->candidate();
        $spec = PackageSpec::create()
            ->requireFile('templates/base.html.twig')
            ->requireDirectory('assets');

        $result = (new PackageValidator())->validate($candidate, $spec);

        self::assertFalse($result->isSuccess());
        self::assertCount(2, $result->issues());
        self::assertSame('package.required_file_missing', $result->issues()[0]->code());
        self::assertSame('templates/base.html.twig', $result->issues()[0]->context()['requirement']);
        self::assertSame('package.required_directory_missing', $result->issues()[1]->code());
        self::assertSame('assets', $result->issues()[1]->context()['requirement']);
        self::assertContains('.manifest', $result->context()['inventory']);
    }

    public function testItLimitsInventoryDepth(): void
    {
        $this->writeFile('one/two/three/file.txt', 'nested');

        $candidate = $this->candidate();
        $spec = PackageSpec::create()->withInventoryDepth(1);

        $result = (new PackageValidator())->validate($candidate, $spec);

        self::assertTrue($result->isSuccess());
        self::assertContains('one/', $result->context()['inventory']);
        self::assertContains('one/two/', $result->context()['inventory']);
        self::assertNotContains('one/two/three/', $result->context()['inventory']);
        self::assertNotContains('one/two/three/file.txt', $result->context()['inventory']);
    }

    public function testItRejectsUnsafeRequirementPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Package requirement path "../outside.txt" must be relative and stay inside the package.');

        PackageSpec::create()->requireFile('../outside.txt');
    }

    public function testItCanLintPhpFiles(): void
    {
        $this->writeFile('src/Valid.php', '<?php class ValidPackagePhp {}');
        $this->writeFile('src/Broken.php', '<?php class BrokenPackagePhp {');

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withPhpLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.php_syntax_error', $result->firstIssue()?->code());
        self::assertSame('src/Broken.php', $result->firstIssue()?->context()['file']);
    }

    public function testItCanLintTwigFiles(): void
    {
        $this->writeFile('templates/valid.html.twig', '<main>{{ title }}</main>');
        $this->writeFile('templates/broken.html.twig', '<main>{% if title %}</main>');

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withTwigLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.twig_syntax_error', $result->firstIssue()?->code());
        self::assertSame('templates/broken.html.twig', $result->firstIssue()?->context()['file']);
    }

    public function testItCanRunAllLintingChecks(): void
    {
        $this->writeFile('src/Valid.php', '<?php class ValidPackageLintPhp {}');
        $this->writeFile('templates/valid.html.twig', '<main>{{ title }}</main>');
        $this->writeFile('config/valid.json', '{"enabled": true}');
        $this->writeFile('config/valid.yaml', 'enabled: true');
        $this->writeFile('assets/valid.css', 'body { color: red; }');
        $this->writeFile('assets/valid.js', 'export default true;');

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withLintingChecks(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItReportsStructuredSyntaxErrors(): void
    {
        $this->writeFile('config/broken.json', '{');
        $this->writeFile('config/broken.yaml', 'enabled: [');
        $this->writeFile('assets/broken.css', 'body { color: ; }');
        $this->writeFile('assets/broken.js', 'const = ;');

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withLintingChecks(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame([
            'package.json_syntax_error',
            'package.yaml_syntax_error',
            'package.css_syntax_error',
            'package.javascript_syntax_error',
        ], array_map(static fn ($issue): string => $issue->code(), $result->issues()));
    }

    public function testItCanRunIndividualLintingChecks(): void
    {
        $this->writeFile('config/broken.json', '{');
        $this->writeFile('assets/broken.js', 'const = ;');

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withJsonLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertCount(1, $result->issues());
        self::assertSame('package.json_syntax_error', $result->firstIssue()?->code());
    }

    private function candidate(): PackageCandidate
    {
        return new PackageCandidate(
            PackageSource::children('theme', 'themes'),
            $this->packageDir,
            $this->packageDir.'/.manifest',
            new Manifest(['THEME_NAME' => 'System']),
        );
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $this->writeTestFile($this->packageDir, $relativePath, $contents);
    }
}
