<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Manifest\Manifest;
use App\Core\Extension\ExtensionCandidate;
use App\Core\Extension\ExtensionInspection;
use App\Core\Extension\ExtensionManifestSpec;
use App\Core\Extension\ExtensionSource;
use App\Core\Extension\ExtensionSpec;
use App\Core\Extension\ExtensionValidator;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExtensionValidatorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $rootDir;
    private string $extensionDir;

    protected function setUp(): void
    {
        $this->rootDir = $this->createTemporaryDirectory('system-extension-validator');
        $this->extensionDir = $this->rootDir.'/system';
        mkdir($this->extensionDir, 0777, true);
        $this->writeFile('.manifest', 'EXTENSION_NAME=System');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testItAcceptsExtensionsWithRequiredFilesAndDirectories(): void
    {
        $this->writeFile('templates/base.html.twig', 'layout');
        $this->writeFile('assets/app.css', 'body {}');

        $candidate = $this->candidate();
        $spec = ExtensionSpec::create()
            ->requireFile('.manifest')
            ->requireFile('templates/base.html.twig')
            ->requireDirectory('assets');

        $result = (new ExtensionValidator())->validate($candidate, $spec);

        self::assertTrue($result->isSuccess());
        self::assertSame($candidate, $result->value());
        self::assertContains('.manifest', $result->context()['inventory']);
        self::assertContains('templates/base.html.twig', $result->context()['inventory']);
        self::assertContains('assets/', $result->context()['inventory']);
    }

    public function testItExposesExtensionInspectionFeatures(): void
    {
        $this->writeFile('templates/base.html.twig', '<main></main>');
        $this->writeFile('assets/app.css', 'body {}');
        $this->writeFile('assets/app.js', 'export default true;');
        $this->writeFile('assets/images/logo.svg', '<svg></svg>');
        $this->writeFile('assets/fonts/demo.woff2', 'font');
        $this->writeFile('private-assets/index.json', '{"items": []}');
        $this->writeFile('data/extension.yaml', 'enabled: true');
        $this->writeFile('data/extension.json', '{"enabled": true}');
        $this->writeFile('src/ExtensionExtension.php', '<?php class ExtensionExtension {}');
        $this->writeFile('extension.php', '<?php return true;');

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ExtensionInspection::class, $result->context()['inspection']);

        /** @var ExtensionInspection $inspection */
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
        self::assertSame(['src/ExtensionExtension.php'], $inspection->sourcePhpFiles());
        self::assertSame(['extension.php', 'src/ExtensionExtension.php'], $inspection->phpFiles());
        self::assertSame(['data/extension.json', 'private-assets/index.json'], $inspection->jsonFiles());
        self::assertSame(['data/extension.yaml'], $inspection->yamlFiles());
        self::assertSame(['assets/app.css'], $inspection->cssFiles());
        self::assertSame(['assets/app.js'], $inspection->javaScriptFiles());
        self::assertSame(['assets/fonts/demo.woff2', 'assets/images/logo.svg'], $inspection->staticAssetFiles());
    }

    public function testItReportsMissingRequiredFilesAndDirectories(): void
    {
        $candidate = $this->candidate();
        $spec = ExtensionSpec::create()
            ->requireFile('templates/base.html.twig')
            ->requireDirectory('assets');

        $result = (new ExtensionValidator())->validate($candidate, $spec);

        self::assertFalse($result->isSuccess());
        self::assertCount(2, $result->issues());
        self::assertSame('extension.required_file_missing', $result->issues()[0]->code());
        self::assertSame('templates/base.html.twig', $result->issues()[0]->context()['requirement']);
        self::assertSame('extension.required_directory_missing', $result->issues()[1]->code());
        self::assertSame('assets', $result->issues()[1]->context()['requirement']);
        self::assertContains('.manifest', $result->context()['inventory']);
    }

    public function testItRequiresExtensionSlugForExtensionCandidates(): void
    {
        $candidate = new ExtensionCandidate(
            ExtensionSource::children('extension', 'extensions'),
            $this->extensionDir,
            $this->extensionDir.'/.manifest',
            new Manifest(['EXTENSION_NAME' => 'System']),
        );

        $result = (new ExtensionValidator())->validate($candidate, ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.missing_required_key', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsInvalidExtensionSlugForExtensionCandidates(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => '../system']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsExtensionSlugsLongerThanIdentifierSpecAllows(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => str_repeat('a', 61)]),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsDigitPrefixedExtensionSlugsBeforeCssValidation(): void
    {
        $this->writeFile('assets/app.css', <<<'CSS'
.foreign-card {
    color: red;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => '3d-gallery']),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItAllowsAdditionalExtensionManifestKeysWithExtensionPrefix(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_DATE' => '2026-06-19']),
            ExtensionSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsAdditionalExtensionManifestKeysWithoutExtensionPrefix(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['CUSTOM_DATE' => '2026-06-19']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('manifest.invalid_key', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_', $result->firstIssue()?->context()['expected_prefix']);
    }

    public function testItRejectsSlashSeparatedExtensionSlugs(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'vendor/extension']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
    }

    public function testItRejectsExtensionSlugThatDoesNotMatchDirectoryName(): void
    {
        $result = (new ExtensionValidator())->validate(
            new ExtensionCandidate(
                ExtensionSource::children('extension', 'extensions'),
                $this->extensionDir,
                $this->extensionDir.'/.manifest',
                new Manifest(['EXTENSION_SLUG' => 'other-system', 'EXTENSION_NAME' => 'System']),
            ),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.identifier.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_SLUG', $result->firstIssue()?->context()['key']);
        self::assertSame('system', $result->firstIssue()?->context()['expected_slug']);
    }

    public function testItRejectsMalformedExtensionDependencies(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_DEPENDENCIES' => '["demo-base >=1.0"]']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.dependency.invalid', $result->firstIssue()?->code());
        self::assertSame('EXTENSION_DEPENDENCIES', $result->firstIssue()?->context()['key']);
    }

    public function testItAcceptsExplicitExtensionDependencyConstraints(): void
    {
        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_DEPENDENCIES' => '[["system",">=0.2.6"],["demo-module","=1.0.0"]]']),
            ExtensionSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsInvalidSchedulerTaskCronExpressions(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('src/SchedulerTasks.php', $result->firstIssue()?->context()['file']);
        self::assertSame('not a cron', $result->firstIssue()?->context()['value']);
    }

    public function testItRejectsInvalidSchedulerTaskCronExpressionsThroughImportAliases(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition as Task;

return [
    Task::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('not a cron', $result->firstIssue()?->context()['value']);
    }

    public function testItRejectsInvalidSchedulerTaskCronExpressionsThroughGroupedImportAliases(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\{SchedulerTaskDefinition as Task};

return [
    Task::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('not a cron', $result->firstIssue()?->context()['value']);
    }

    public function testItIgnoresSchedulerExamplesInsideComments(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

// Example only: SchedulerTaskDefinition::command('demo.bad', 'label', 'description', 'demo:bad', 'not a cron');

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        '*/10 * * * *',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItIgnoresSchedulerExamplesInsideStrings(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

$documentation = "Example only: SchedulerTaskDefinition::command('demo.bad', 'label', 'description', 'demo:bad', 'not a cron')";

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        '*/10 * * * *',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItReadsNamedSchedulerCronArgumentWithoutFalsePositives(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        command: 'demo:cleanup',
        identifier: 'demo.cleanup',
        labelKey: 'ext.demo.cleanup.label',
        descriptionKey: 'ext.demo.cleanup.description',
        defaultCronExpression: '*/10 * * * *',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItReadsNamedSchedulerCronArgumentAtTopLevelOnly(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        command: 'demo --label="defaultCronExpression: \'not a cron\'"',
        identifier: 'demo.cleanup',
        labelKey: 'ext.demo.cleanup.label',
        descriptionKey: 'ext.demo.cleanup.description',
        defaultCronExpression: '*/10 * * * *',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItIgnoresSchedulerCommentsInsideCallArguments(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        // Example only, with comma: defaultCronExpression: 'not a cron',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        '*/10 * * * *',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItIgnoresUnrelatedSchedulerTaskDefinitionClasses(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use Vendor\SchedulerTaskDefinition;

return [
    SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItReadsFullyQualifiedSchedulerTaskDefinitions(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

return [
    \App\Scheduler\SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
    }

    public function testItReadsSchedulerDefinitionsThroughNamespaceAliases(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler as Scheduler;

return [
    Scheduler\SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
    }

    public function testItReadsSchedulerDefinitionsThroughRootNamespaceAliases(): void
    {
        $this->writeFile('src/SchedulerTasks.php', <<<'PHP'
<?php

use App as Studio;

return [
    Studio\Scheduler\SchedulerTaskDefinition::command(
        'demo.cleanup',
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        'not a cron',
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
    }

    public function testItAcceptsExtensionSchedulerProviderWithLiteralCronExpression(): void
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
                'ext.demo.cleanup.label',
                'ext.demo.cleanup.description',
                'demo:cleanup',
                '*/15 * * * *',
                'demo-module',
                false,
            ),
        ];
    }
}
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertTrue($result->isSuccess());
    }

    public function testItAcceptsExtensionSchedulerProviderWithShortExtensionSource(): void
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
                'ext.demo.cleanup.label',
                'ext.demo.cleanup.description',
                'demo:cleanup',
                '*/15 * * * *',
                'ai',
                false,
            ),
        ];
    }
}
PHP);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'ai']),
            ExtensionSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItReadsSchedulerCronByArgumentPosition(): void
    {
        $this->writeFile('src/DemoSchedulerTasks.php', <<<'PHP'
<?php

use App\Scheduler\SchedulerTaskDefinition;

final class TaskIds
{
    public const CLEANUP = 'demo.cleanup';
}

return [
    SchedulerTaskDefinition::command(
        TaskIds::CLEANUP,
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo cleanup',
        '*/15 * * * *',
        'demo-module',
        false,
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

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
        'ext.demo.cleanup.label',
        'ext.demo.cleanup.description',
        'demo:cleanup',
        $cron,
    ),
];
PHP);

        $result = (new ExtensionValidator())->validate($this->candidate(), ExtensionSpec::create());

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.scheduler.cron_invalid', $result->firstIssue()?->code());
        self::assertSame('', $result->firstIssue()?->context()['value']);
    }

    public function testItLimitsInventoryDepth(): void
    {
        $this->writeFile('one/two/three/file.txt', 'nested');

        $candidate = $this->candidate();
        $spec = ExtensionSpec::create()->withInventoryDepth(1);

        $result = (new ExtensionValidator())->validate($candidate, $spec);

        self::assertTrue($result->isSuccess());
        self::assertContains('one/', $result->context()['inventory']);
        self::assertContains('one/two/', $result->context()['inventory']);
        self::assertNotContains('one/two/three/', $result->context()['inventory']);
        self::assertNotContains('one/two/three/file.txt', $result->context()['inventory']);
    }

    public function testItRejectsUnsafeRequirementPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Extension requirement path "../outside.txt" must be relative and stay inside the extension.');

        ExtensionSpec::create()->requireFile('../outside.txt');
    }

    public function testItCanLintPhpFiles(): void
    {
        $this->writeFile('src/Valid.php', '<?php class ValidExtensionPhp {}');
        $this->writeFile('src/Broken.php', '<?php class BrokenExtensionPhp {');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withPhpLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.php_syntax_error', $result->firstIssue()?->code());
        self::assertSame('src/Broken.php', $result->firstIssue()?->context()['file']);
    }

    public function testItAcceptsExtensionSourceFilesWithinDeclaredNamespace(): void
    {
        $this->writeFile('src/Root.php', '<?php namespace Demo\\Extension; final class Root {}');
        $this->writeFile('src/Nested.php', '<?php namespace Demo\\Extension\\Nested; final class Nested {}');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_NAMESPACE' => 'Demo\\Extension']),
            ExtensionSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsExtensionSourceFilesOutsideDeclaredNamespace(): void
    {
        $this->writeFile('src/Foreign.php', '<?php namespace Other\\Extension; final class Foreign {}');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_NAMESPACE' => 'Demo\\Extension']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.php_namespace_invalid', $result->firstIssue()?->code());
        self::assertSame('Other\\Extension', $result->firstIssue()?->context()['namespace']);
        self::assertSame('Demo\\Extension', $result->firstIssue()?->context()['expected_namespace']);
    }

    public function testItCanLintTwigFiles(): void
    {
        $this->writeFile('templates/valid.html.twig', '<main>{{ title }}</main>');
        $this->writeFile('templates/broken.html.twig', '<main>{% if title %}</main>');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withTwigLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.twig_syntax_error', $result->firstIssue()?->code());
        self::assertSame('templates/broken.html.twig', $result->firstIssue()?->context()['file']);
    }

    public function testItAllowsAreaTemplatesForAdditiveExtensions(): void
    {
        $this->writeFile('templates/frontend/captcha/field.html.twig', '<input>');
        $this->writeFile('templates/backend/module/settings.html.twig', '<form></form>');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('[module, captcha-provider]'),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAllowsProviderTemplatesForMatchingProviderScope(): void
    {
        $this->writeFile('templates/provider/captcha/field.html.twig', '<input>');
        $this->writeFile('templates/provider/editor/richtext.html.twig', '<textarea></textarea>');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('[captcha-provider, editor-provider]'),
            ExtensionSpec::create()->withInventoryDepth(4),
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

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('[frontend-theme, backend-theme, system-template, module]'),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsRootAndMacroTemplatesOutsideDeclaredExtensionScopes(): void
    {
        $this->writeFile('templates/frontend/page.html.twig', '<main></main>');
        $this->writeFile('templates/backend/dashboard.html.twig', '<main></main>');
        $this->writeFile('templates/base.html.twig', '<main></main>');
        $this->writeFile('templates/macros/core/ui.html.twig', '{% macro badge(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/macros/other-extension/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/macros/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');
        $this->writeFile('templates/provider/captcha/field.html.twig', '<input>');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('module'),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame([
            'templates/base.html.twig',
            'templates/macros/core/ui.html.twig',
            'templates/macros/forms.html.twig',
            'templates/macros/other-extension/forms.html.twig',
            'templates/provider/captcha/field.html.twig',
        ], array_map(static fn ($issue): string => $issue->context()['file'], $result->issues()));
        self::assertSame('extension.template_path_invalid', $result->firstIssue()?->code());
    }

    public function testItAllowsExtensionOwnedMacroNamespaceWithoutThemeScope(): void
    {
        $this->writeFile('templates/macros/system/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('module'),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAllowsExtensionOwnedMacroNamespaceUsingManifestSlugWhenDirectoryDiffers(): void
    {
        $this->writeFile('templates/macros/demo-module/forms.html.twig', '{% macro field(label) %}{{ label }}{% endmacro %}');

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module', 'EXTENSION_SCOPE' => 'module']),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItCanRunAllLintingChecks(): void
    {
        $this->writeFile('src/Valid.php', '<?php class ValidExtensionLintPhp {}');
        $this->writeFile('templates/valid.html.twig', '<main>{{ title }}</main>');
        $this->writeFile('data/valid.json', '{"enabled": true}');
        $this->writeFile('data/valid.yaml', 'enabled: true');
        $this->writeFile('assets/valid.css', 'body { color: red; }');
        $this->writeFile('assets/valid.js', 'export default true;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withLintingChecks(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAcceptsExtensionOwnedCssClasses(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.system-panel .demo-module-card,
.demo-module-card,
.demo-module-card.demo-module-card-active {
    color: red;
    background-image: url("../images/icon.svg");
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItAcceptsTailwindDirectivesInExtensionCssSyntaxChecks(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.demo-module-card {
    @apply grid gap-4 rounded-lg border p-4;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create()->withInventoryDepth(4)->withCssLinting(),
        );

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testItRejectsCssSyntaxErrorsNextToTailwindDirectives(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.demo-module-card {
    @apply grid gap-4 rounded-lg border p-4;
    color red;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create()->withInventoryDepth(4)->withCssLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.css_syntax_error', $result->issues()[0]->code());
    }

    public function testItAcceptsModernCssAtRulesInExtensionCssSyntaxChecks(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.demo-module-card {
    transform: var(--tw-rotate-x,) var(--tw-rotate-y,);
    @custom-variant demo-module-dark (&:where(.demo-module-dark, .demo-module-dark *));
    @supports (color: color-mix(in lab, red, red)) {
        color: color-mix(in oklab, currentcolor 50%, transparent);
    }
    @media (width >= 40rem) {
        max-width: 40rem;
    }
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create()->withInventoryDepth(4)->withCssLinting(),
        );

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testItRejectsCssSyntaxErrorsInsideModernAtRules(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.demo-module-card {
    @supports (color: color-mix(in lab, red, red)) {
        color red;
    }
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create()->withInventoryDepth(4)->withCssLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.css_syntax_error', $result->issues()[0]->code());
    }

    public function testItRejectsCssRulesTargetingClassesOutsideExtensionNamespace(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.system-panel,
.other-extension-card {
    color: red;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame(
            ['system-panel', 'other-extension-card'],
            array_map(static fn ($issue): string => $issue->context()['class'], $result->issues()),
        );
        self::assertSame('extension.css_namespace_invalid', $result->firstIssue()?->code());
        self::assertSame('demo-module-', $result->firstIssue()?->context()['expected_prefix']);
    }

    public function testItAcceptsExtensionTranslationFilesInOwnedNamespace(): void
    {
        $this->writeFile('languages/en/messages.yaml', "ext:\n  system:\n    title: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsExtensionTranslationYmlFiles(): void
    {
        $this->writeFile('languages/en/messages.yml', "ext:\n  system:\n    title: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('language_path_invalid', $result->firstIssue()?->context()['reason']);
    }

    public function testItAcceptsExtensionTranslationFilesUsingManifestSlugWhenDirectoryDiffers(): void
    {
        $this->writeFile('languages/en/messages.yaml', "ext:\n  demo-module:\n    title: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module']),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRequiresFallbackLocaleWhenExtensionTranslationsExist(): void
    {
        $this->writeFile('languages/de/messages.yaml', "ext:\n  system:\n    title: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.translation_fallback_missing', $result->firstIssue()?->code());
        self::assertSame('languages/en', $result->firstIssue()?->context()['file']);
    }

    public function testItRequiresEnglishEvenWhenOtherLanguagesExist(): void
    {
        $this->writeFile('languages/de/messages.yaml', "ext:\n  system:\n    title: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.translation_fallback_missing', $result->firstIssue()?->code());
    }

    public function testItRejectsExtensionTranslationFilesOutsideOwnedNamespace(): void
    {
        $this->writeFile('languages/en/messages.yaml', "ui:\n  app:\n    name: Demo\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withYamlLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.translation_namespace_invalid', $result->firstIssue()?->code());
        self::assertSame('languages/en/messages.yaml', $result->firstIssue()?->context()['file']);
    }

    public function testItRejectsExtensionCssTargetClassesOutsideTheExplicitAssetScope(): void
    {
        $this->writeFile('assets/frontend/app.css', <<<'CSS'
.demo-module-card,
.demo-module-frontend-card,
.system-panel .demo-module-backend-card {
    color: red;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module', 'EXTENSION_SCOPE' => 'frontend-theme']),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame(['demo-module-backend-card'], array_map(
            static fn ($issue): string => $issue->context()['class'],
            $result->issues(),
        ));
        self::assertSame(['demo-module-', 'demo-module-frontend-'], $result->firstIssue()?->context()['expected_prefixes']);
    }

    public function testItAcceptsOwnerAndDeclaredScopeCssClassesForMultiScopeExtensions(): void
    {
        $this->writeFile('assets/module.css', <<<'CSS'
.demo-module-card,
.demo-module-captcha-card {
    color: red;
}
CSS);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithManifest(['EXTENSION_SLUG' => 'demo-module', 'EXTENSION_SCOPE' => '[module, captcha-provider]']),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsTemplateReferencesOutsideTheTemplateScope(): void
    {
        $this->writeFile('templates/frontend/page.html.twig', <<<'TWIG'
{% extends '@backend/admin.html.twig' %}
{% include '@root/partials/brand/_brand.html.twig' %}
TWIG);

        $result = (new ExtensionValidator())->validate(
            $this->candidateWithScope('module'),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.template_reference_invalid', $result->firstIssue()?->code());
        self::assertSame('@backend/admin.html.twig', $result->firstIssue()?->context()['reference']);
    }

    public function testItReportsStructuredSyntaxErrors(): void
    {
        $this->writeFile('data/broken.json', '{');
        $this->writeFile('data/broken.yaml', 'enabled: [');
        $this->writeFile('assets/broken.css', 'body { color: ; }');
        $this->writeFile('assets/broken.js', 'const = ;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withLintingChecks(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame([
            'extension.json_syntax_error',
            'extension.yaml_syntax_error',
            'extension.css_syntax_error',
            'extension.javascript_syntax_error',
        ], array_map(static fn ($issue): string => $issue->code(), $result->issues()));
    }

    public function testItCanRunIndividualLintingChecks(): void
    {
        $this->writeFile('data/broken.json', '{');
        $this->writeFile('assets/broken.js', 'const = ;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withJsonLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertCount(1, $result->issues());
        self::assertSame('extension.json_syntax_error', $result->firstIssue()?->code());
    }

    public function testItBlocksReservedExtensionPaths(): void
    {
        $this->writeFile('.env.local', 'APP_SECRET=leaked');
        $this->writeFile('public/index.php', '<?php echo "no";');
        $this->writeFile('vendor/autoload.php', '<?php return true;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertNotEmpty($result->issues());
        self::assertSame(
            ['extension.policy.blocked_path'],
            array_values(array_unique(array_map(static fn ($issue): string => $issue->code(), $result->issues()))),
        );

        $reasons = array_values(array_unique(array_map(static fn ($issue): string => $issue->context()['reason'], $result->issues())));
        self::assertContains('environment_file', $reasons);
        self::assertContains('reserved_project_path', $reasons);
        self::assertContains('composer_dependency_manifest_missing', $reasons);
    }

    public function testItIgnoresDevelopmentOnlyExtensionPaths(): void
    {
        $this->writeFile('docs/readme.md', 'notes');
        $this->writeFile('tests/ExtensionTest.php', '<?php');
        $this->writeFile('.git/config', '[core]');
        $this->writeFile('.editorconfig', "root = true\n");

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->issues());
    }

    public function testItRejectsExtensionPhpFilesOutsideSrcExceptExtensionBootstrap(): void
    {
        $this->writeFile('extension.php', '<?php return [];');
        $this->writeFile('src/Extension.php', '<?php final class Extension {}');
        $this->writeFile('tools/helper.php', '<?php return true;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.policy.blocked_path', $result->firstIssue()?->code());
        self::assertSame('php_outside_src', $result->firstIssue()?->context()['reason']);
        self::assertSame('tools/helper.php', $result->firstIssue()?->context()['path']);
    }

    public function testItRejectsTemplatesOutsideTemplateRoot(): void
    {
        $this->writeFile('views/page.html.twig', '<main></main>');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('template_outside_templates', $result->firstIssue()?->context()['reason']);
    }

    public function testItAllowsUnknownAssetFileTypesInsideAssetRoots(): void
    {
        $this->writeFile('assets/models/scene.glb', 'binary');
        $this->writeFile('private-assets/challenges/prompt.challenge', 'private');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertTrue($result->isSuccess());
    }

    public function testItRejectsExecutableOrBareHtmlFilesInsideAssetRoots(): void
    {
        $this->writeFile('assets/shell.php', '<?php echo "no";');
        $this->writeFile('assets/page.html', '<script>alert(1)</script>');
        $this->writeFile('private-assets/script.py', 'print("no")');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame(
            ['asset_executable_file', 'asset_executable_file', 'asset_executable_file'],
            array_map(static fn ($issue): string => $issue->context()['reason'], $result->issues()),
        );
    }

    public function testItAcceptsPrivateAssetsWithoutAddingThemToSyncAssets(): void
    {
        $this->writeFile('private-assets/icons/icon.svg', '<svg></svg>');
        $this->writeFile('private-assets/index.json', '{"icons": ["icon"]}');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withJsonLinting(),
        );

        self::assertTrue($result->isSuccess());

        /** @var ExtensionInspection $inspection */
        $inspection = $result->context()['inspection'];

        self::assertSame([], $inspection->assetFiles());
        self::assertSame(['private-assets/index.json'], $inspection->jsonFiles());
    }

    public function testItAllowsCommittedDependencyPayloadsWithMatchingManifests(): void
    {
        $this->writeFile('composer.json', '{"name": "aavion/demo-extension", "type": "library", "require": {"php": ">=8.4"}}');
        $this->writeFile('composer.lock', $this->emptyComposerLock());
        $this->writeFile('vendor/vendor/extension/src/Broken.php', '<?php class Broken {');
        $this->writeFile('assets/package.json', '{"dependencies": {"library": "1.0.0"}}');
        $this->writeFile('assets/package-lock.json', '{"lockfileVersion": 3}');
        $this->writeFile('assets/node_modules/library/broken.js', 'const = ;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(6)->withLintingChecks(),
        );

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testItValidatesExtensionComposerManifestWhenPresent(): void
    {
        $this->writeFile('composer.json', '{"name": "Invalid Name"}');
        $this->writeFile('composer.lock', $this->emptyComposerLock());
        $this->writeFile('vendor/autoload.php', '<?php return true;');

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4)->withJsonLinting(),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.policy.blocked_path', $result->firstIssue()?->code());
        self::assertSame('composer_manifest_invalid', $result->firstIssue()?->context()['reason']);
    }

    public function testItBlocksDirectPhpCapabilitiesForInstallableExtensions(): void
    {
        $this->writeFile('extension.php', <<<'PHP'
            <?php

            $secret = file_get_contents('/etc/passwd');
            putenv('APP_DEBUG=1');

            return [];
            PHP);
        $this->writeFile('src/Runner.php', <<<'PHP'
            <?php

            namespace DemoExtension;

            final class Runner
            {
                public function run(): void
                {
                    exec('whoami');
                    new \ZipArchive();
                }
            }
            PHP);

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());

        $policyIssues = array_values(array_filter(
            $result->issues(),
            static fn ($issue): bool => 'extension.policy.blocked_php_capability' === $issue->code(),
        ));

        self::assertCount(4, $policyIssues);
        self::assertSame(['file_get_contents', 'putenv', 'exec', '\ZipArchive'], array_map(
            static fn ($issue): string => $issue->context()['capability'],
            $policyIssues,
        ));
        self::assertSame(['direct_filesystem', 'direct_environment', 'direct_process', 'direct_filesystem'], array_map(
            static fn ($issue): string => $issue->context()['reason'],
            $policyIssues,
        ));
    }

    public function testItBlocksDynamicPhpCapabilityBypassesForInstallableExtensions(): void
    {
        $this->writeFile('extension.php', <<<'PHP'
            <?php

            $reader = 'file_get_contents';
            $reader('/etc/passwd');
            call_user_func('exec', 'whoami');
            new ReflectionFunction('file_get_contents');

            return [];
            PHP);

        $result = (new ExtensionValidator())->validate(
            $this->candidate(),
            ExtensionSpec::create()->withInventoryDepth(4),
        );

        self::assertFalse($result->isSuccess());

        $policyIssues = array_values(array_filter(
            $result->issues(),
            static fn ($issue): bool => 'extension.policy.blocked_php_capability' === $issue->code(),
        ));

        self::assertSame(['$reader()', 'call_user_func', 'ReflectionFunction'], array_map(
            static fn ($issue): string => $issue->context()['capability'],
            $policyIssues,
        ));
        self::assertSame(['dynamic_callable', 'dynamic_callable', 'dynamic_introspection'], array_map(
            static fn ($issue): string => $issue->context()['reason'],
            $policyIssues,
        ));
    }

    private function candidate(): ExtensionCandidate
    {
        return new ExtensionCandidate(
            ExtensionSource::children('extension', 'extensions'),
            $this->extensionDir,
            $this->extensionDir.'/.manifest',
            new Manifest(['EXTENSION_SLUG' => 'system', 'EXTENSION_NAME' => 'System']),
        );
    }

    private function candidateWithScope(string $scope): ExtensionCandidate
    {
        return $this->candidateWithManifest(['EXTENSION_SCOPE' => $scope]);
    }

    /**
     * @param array<string, string> $manifest
     */
    private function candidateWithManifest(array $manifest): ExtensionCandidate
    {
        $slug = $manifest['EXTENSION_SLUG'] ?? 'system';
        if (ExtensionManifestSpec::isValidSlug($slug) && basename($this->extensionDir) !== $slug) {
            $targetDir = $this->rootDir.'/'.$slug;
            rename($this->extensionDir, $targetDir);
            $this->extensionDir = $targetDir;
        }

        return new ExtensionCandidate(
            ExtensionSource::children('extension', 'extensions'),
            $this->extensionDir,
            $this->extensionDir.'/.manifest',
            new Manifest(['EXTENSION_SLUG' => 'system', 'EXTENSION_NAME' => 'System', ...$manifest]),
        );
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $this->writeTestFile($this->extensionDir, $relativePath, $contents);
    }

    private function emptyComposerLock(): string
    {
        return <<<'JSON'
{
    "_readme": [],
    "content-hash": "test",
    "packages": [],
    "packages-dev": [],
    "aliases": [],
    "minimum-stability": "stable",
    "stability-flags": {},
    "prefer-stable": false,
    "prefer-lowest": false,
    "platform": {},
    "platform-dev": {},
    "plugin-api-version": "2.6.0"
}
JSON;
    }
}
