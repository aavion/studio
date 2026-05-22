<?php

declare(strict_types=1);

namespace App\Tests\Core\DryRun;

use App\Core\ActionLog\ActionLogStatus;
use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunDiff;
use App\Core\DryRun\DryRunDiffType;
use App\Core\DryRun\DryRunPlan;
use App\Core\DryRun\DryRunRisk;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DryRunPlanTest extends TestCase
{
    public function testItAggregatesPlanSummary(): void
    {
        $plan = DryRunPlan::create('theme import')
            ->add(DryRunAction::create('copy_file', 'Copy template', paths: ['templates/base.html.twig']))
            ->add(DryRunAction::create('write_config', 'Update manifest', DryRunRisk::Medium, ['.manifest']))
            ->add(DryRunAction::create('remove_file', 'Remove stale asset', DryRunRisk::High, ['assets/old.css']));

        self::assertFalse($plan->isEmpty());
        self::assertSame(DryRunRisk::High, $plan->highestRisk());
        self::assertSame(['.manifest', 'assets/old.css', 'templates/base.html.twig'], $plan->affectedPaths());
        self::assertSame([
            'copy_file' => 1,
            'remove_file' => 1,
            'write_config' => 1,
        ], $plan->actionCounts());
    }

    public function testItCarriesTextDiffs(): void
    {
        $diff = DryRunDiff::text('template', '<h1>Old</h1>', '<h1>New</h1>');
        $action = DryRunAction::create('write_file', 'Update template', diffs: [$diff]);

        self::assertTrue($action->hasDiffs());
        self::assertSame(DryRunDiffType::Text, $action->diffs()[0]->type());
        self::assertSame([
            'before' => '<h1>Old</h1>',
            'after' => '<h1>New</h1>',
            'changes' => [[
                'path' => 'template',
                'type' => 'changed',
                'before' => '<h1>Old</h1>',
                'after' => '<h1>New</h1>',
            ]],
        ], $action->diffs()[0]->payload());
    }

    public function testItCarriesKeyValueDiffs(): void
    {
        $diff = DryRunDiff::keyValue('manifest', [
            'THEME_NAME' => 'Old',
            'THEME_VERSION' => '1.0.0',
        ], [
            'THEME_NAME' => 'New',
            'THEME_VERSION' => '1.0.0',
            'THEME_AUTHOR' => 'Studio',
        ]);

        self::assertSame(DryRunDiffType::KeyValue, $diff->type());
        self::assertSame(['THEME_AUTHOR', 'THEME_NAME'], $diff->payload()['changed_keys']);
        self::assertSame('added', $diff->payload()['changes'][0]['type']);
        self::assertSame('changed', $diff->payload()['changes'][1]['type']);
    }

    public function testItExportsToActionLog(): void
    {
        $plan = DryRunPlan::create('module import')
            ->add(DryRunAction::create(
                'write_file',
                'Write module manifest',
                DryRunRisk::Medium,
                ['.manifest'],
                [DryRunDiff::keyValue('manifest', [], ['MODULE_NAME' => 'Demo'])],
                ['package' => 'demo'],
            ));

        $log = $plan->toActionLog();

        self::assertCount(1, $log->entries());
        self::assertSame(ActionLogStatus::Skipped, $log->entries()[0]->status());
        self::assertSame('medium', $log->entries()[0]->context()['risk']);
        self::assertSame(['.manifest'], $log->entries()[0]->context()['paths']);
        self::assertSame('demo', $log->entries()[0]->context()['package']);
        self::assertSame('key_value', $log->entries()[0]->context()['diffs'][0]['type']);
    }

    public function testItExportsStructuredPayload(): void
    {
        $plan = DryRunPlan::create('module import', ['package' => 'demo'])
            ->add(DryRunAction::create(
                'write_file',
                'Write module manifest',
                DryRunRisk::Medium,
                ['.manifest'],
                [DryRunDiff::keyValue('manifest', [], ['MODULE_NAME' => 'Demo'])],
                ['target' => '.manifest'],
            ));

        $payload = $plan->toArray();

        self::assertSame('module import', $payload['name']);
        self::assertSame(['write_file' => 1], $payload['action_counts']);
        self::assertSame(['.manifest'], $payload['affected_paths']);
        self::assertSame('medium', $payload['highest_risk']);
        self::assertTrue($payload['has_diffs']);
        self::assertSame(['package' => 'demo'], $payload['context']);
        self::assertSame([
            'type' => 'write_file',
            'label' => 'Write module manifest',
            'risk' => 'medium',
            'paths' => ['.manifest'],
            'diffs' => [[
                'type' => 'key_value',
                'label' => 'manifest',
                'payload' => $plan->actions()[0]->diffs()[0]->payload(),
            ]],
            'context' => ['target' => '.manifest'],
        ], $payload['actions'][0]);
    }

    public function testItRejectsInvalidActions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DryRunAction::create('', 'Missing type');
    }

    public function testItRejectsInvalidDiffs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DryRunAction('write_file', 'Write file', diffs: ['invalid']);
    }
}
