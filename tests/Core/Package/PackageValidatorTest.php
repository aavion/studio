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
        $this->writeFile('.manifest', 'PACKAGE_NAME=System');
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
        $this->writeFile('assets/images/logo.svg', '<svg></svg>');
        $this->writeFile('assets/fonts/demo.woff2', 'font');
        $this->writeFile('config/package.yaml', 'enabled: true');
        $this->writeFile('config/package.json', '{"enabled": true}');
        $this->writeFile('src/PackageExtension.php', '<?php class PackageExtension {}');
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
        self::assertTrue($inspection->hasStaticAssetFiles());
        self::assertSame(['templates/base.html.twig'], $inspection->templateFiles());
        self::assertSame(['assets/app.css', 'assets/app.js', 'assets/fonts/demo.woff2', 'assets/images/logo.svg'], $inspection->assetFiles());
        self::assertSame(['src/PackageExtension.php'], $inspection->sourcePhpFiles());
        self::assertSame(['src/PackageExtension.php', 'tools/helper.php'], $inspection->phpFiles());
        self::assertSame(['config/package.json'], $inspection->jsonFiles());
        self::assertSame(['config/package.yaml'], $inspection->yamlFiles());
        self::assertSame(['assets/app.css'], $inspection->cssFiles());
        self::assertSame(['assets/app.js'], $inspection->javaScriptFiles());
        self::assertSame(['assets/fonts/demo.woff2', 'assets/images/logo.svg'], $inspection->staticAssetFiles());
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

    public function testItRequiresPackageSlugForPackageCandidates(): void
    {
        $candidate = new PackageCandidate(
            PackageSource::children('package', 'packages'),
            $this->packageDir,
            $this->packageDir.'/.manifest',
            new Manifest(['PACKAGE_NAME' => 'System']),
        );

        $result = (new PackageValidator())->validate($candidate, PackageSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.missing_required_key', $result->firstIssue()?->code());
        self::assertSame('PACKAGE_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsInvalidPackageSlugForPackageCandidates(): void
    {
        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_SLUG' => '../system']),
            PackageSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('PACKAGE_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsMalformedPackageDependencies(): void
    {
        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_DEPENDENCIES' => '["demo-base >=1.0"]']),
            PackageSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.dependency.invalid', $result->firstIssue()?->code());
        self::assertSame('PACKAGE_DEPENDENCIES', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsInvalidSchedulerTaskCronExpressions(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'pkg.demo.cleanup.label',
        'pkg.demo.cleanup.description',
        'studio:demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('package.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('src/SchedulerTasks.php', $result->firstIssue()?->context()['file']);
        self::assertSame('not a cron', $result->firstIssue()?->context()['value']);
    }

    public function testItIgnoresSchedulerExamplesInsideComments(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

// Example only: SchedulerTaskDefinition::command('demo.bad', 'label', 'description', 'studio:bad', 'not a cron');

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'pkg.demo.cleanup.label',
        'pkg.demo.cleanup.description',
        'studio:demo:cleanup',
        '*/10 * * * *',
    ),
];
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItIgnoresSchedulerExamplesInsideStrings(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

$documentation = "Example only: SchedulerTaskDefinition::command('demo.bad', 'label', 'description', 'studio:bad', 'not a cron')";

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'pkg.demo.cleanup.label',
        'pkg.demo.cleanup.description',
        'studio:demo:cleanup',
        '*/10 * * * *',
    ),
];
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItReadsNamedSchedulerCronArgumentWithoutFalsePositives(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        command: 'studio:demo:cleanup',
        identifier: 'demo.cleanup',
        labelKey: 'pkg.demo.cleanup.label',
        descriptionKey: 'pkg.demo.cleanup.description',
        defaultCronExpression: '*/10 * * * *',
    ),
];
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItAcceptsPackageSchedulerProviderWithLiteralCronExpression(): void
    {
        $this->writeFile('src/DemoSchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskProviderInterface;

final class DemoSchedulerTasks implements SchedulerTaskProviderInterface
{
    public function schedulerTasks(): array
    {
        return [
            SchedulerTaskDefinition::command(
                'demo.cleanup',
                'pkg.demo.cleanup.label',
                'pkg.demo.cleanup.description',
                'studio:demo:cleanup',
                '*/15 * * * *',
                'demo-module',
                false,
            ),
        ];
    }
}
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsSchedulerTaskRegistrationsWithoutLiteralDefaultCron(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

$cron = '*/10 * * * *';

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'pkg.demo.cleanup.label',
        'pkg.demo.cleanup.description',
        'studio:demo:cleanup',
        $cron,
    ),
];
PHP);

        $result = (new PackageValidator())->validate($this->candidate(), PackageSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('package.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('', $result->firstIssue()?->context()['value']);
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

    public function testItAcceptsPackageSourceFilesWithinDeclaredNamespace(): void
    {
        $this->writeFile('src/Root.php', '<?php namespace Demo\\Package; final class Root {}');
        $this->writeFile('src/Nested.php', '<?php namespace Demo\\Package\\Nested; final class Nested {}');

        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_NAMESPACE' => 'Demo\\Package']),
            PackageSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsPackageSourceFilesOutsideDeclaredNamespace(): void
    {
        $this->writeFile('src/Foreign.php', '<?php namespace Other\\Package; final class Foreign {}');

        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_NAMESPACE' => 'Demo\\Package']),
            PackageSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.php_namespace_invalid', $result->firstIssue()?->code());
        self::assertSame('Other\\Package', $result->firstIssue()?->context()['namespace']);
        self::assertSame('Demo\\Package', $result->firstIssue()?->context()['expected_namespace']);
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

    public function testItAllowsAreaTemplatesForAdditivePackages(): void
    {
        $this->writeFile('templates/frontend/captcha/field.html.twig', '<input>');
        $this->writeFile('templates/backend/module/settings.html.twig', '<form></form>');

        $result = (new PackageValidator())->validate(
            $this->candidateWithScope('[module, captcha-provider]'),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAllowsProviderTemplatesForMatchingProviderScope(): void
    {
        $this->writeFile('templates/provider/captcha/field.html.twig', '<input>');
        $this->writeFile('templates/provider/editor/richtext.html.twig', '<textarea></textarea>');

        $result = (new PackageValidator())->validate(
            $this->candidateWithScope('[captcha-provider, editor-provider]'),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAllowsTemplatesWithinDeclaredOverrideScopes(): void
    {
        $this->writeFile('templates/frontend/page.html.twig', '<main></main>');
        $this->writeFile('templates/backend/dashboard.html.twig', '<main></main>');
        $this->writeFile('templates/base.html.twig', '<main></main>');
        $this->writeFile('templates/macros/core/ui.html.twig', '{% macro badge(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/macros/system/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');

        $result = (new PackageValidator())->validate(
            $this->candidateWithScope('[frontend-theme, backend-theme, system-template, module]'),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsRootAndMacroTemplatesOutsideDeclaredPackageScopes(): void
    {
        $this->writeFile('templates/frontend/page.html.twig', '<main></main>');
        $this->writeFile('templates/backend/dashboard.html.twig', '<main></main>');
        $this->writeFile('templates/base.html.twig', '<main></main>');
        $this->writeFile('templates/macros/core/ui.html.twig', '{% macro badge(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/macros/other-package/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/macros/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/provider/captcha/field.html.twig', '<input>');

        $result = (new PackageValidator())->validate(
            $this->candidateWithScope('module'),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame([
            'templates/base.html.twig',
            'templates/macros/core/ui.html.twig',
            'templates/macros/forms.html.twig',
            'templates/macros/other-package/forms.html.twig',
            'templates/provider/captcha/field.html.twig',
        ], array_map(static fn ($issue): string => $issue->context()['file'], $result->issues()));
        self::assertSame('package.template_path_invalid', $result->firstIssue()?->code());
    }

    public function testItAllowsPackageOwnedMacroNamespaceWithoutThemeScope(): void
    {
        $this->writeFile('templates/macros/system/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');

        $result = (new PackageValidator())->validate(
            $this->candidateWithScope('module'),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAllowsPackageOwnedMacroNamespaceUsingManifestSlugWhenDirectoryDiffers(): void
    {
        $this->writeFile('templates/macros/demo-module/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');

        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_SLUG' => 'demo-module', 'PACKAGE_SCOPE' => 'module']),
            PackageSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
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

    public function testItAcceptsPackageTranslationFilesInOwnedNamespace(): void
    {
        $this->writeFile('languages/en/messages.yaml', "pkg:\n  system:\n    title: Demo\n");

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAcceptsPackageTranslationFilesUsingManifestSlugWhenDirectoryDiffers(): void
    {
        $this->writeFile('languages/en/messages.yaml', "pkg:\n  demo-module:\n    title: Demo\n");

        $result = (new PackageValidator())->validate(
            $this->candidateWithManifest(['PACKAGE_SLUG' => 'demo-module']),
            PackageSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRequiresEnglishWhenPackageTranslationsExist(): void
    {
        $this->writeFile('languages/de/messages.yaml', "pkg:\n  system:\n    title: Demo\n");

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.translation_english_missing', $result->firstIssue()?->code());
        self::assertSame('languages/en', $result->firstIssue()?->context()['file']);
    }

    public function testItRejectsPackageTranslationFilesOutsideOwnedNamespace(): void
    {
        $this->writeFile('languages/en/messages.yaml', "ui:\n  app:\n    name: Demo\n");

        $result = (new PackageValidator())->validate(
            $this->candidate(),
            PackageSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('package.translation_namespace_invalid', $result->firstIssue()?->code());
        self::assertSame('languages/en/messages.yaml', $result->firstIssue()?->context()['file']);
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
            PackageSource::children('package', 'packages'),
            $this->packageDir,
            $this->packageDir.'/.manifest',
            new Manifest(['PACKAGE_SLUG' => 'system', 'PACKAGE_NAME' => 'System']),
        );
    }

    private function candidateWithScope(string $scope): PackageCandidate
    {
        return $this->candidateWithManifest(['PACKAGE_SCOPE' => $scope]);
    }

    /**
     * @param array<string, string> $manifest
     */
    private function candidateWithManifest(array $manifest): PackageCandidate
    {
        return new PackageCandidate(
            PackageSource::children('package', 'packages'),
            $this->packageDir,
            $this->packageDir.'/.manifest',
            new Manifest(['PACKAGE_SLUG' => 'system', 'PACKAGE_NAME' => 'System', ...$manifest]),
        );
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $this->writeTestFile($this->packageDir, $relativePath, $contents);
    }
}
