<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertAction;
use App\View\Alert\UiAlertMode;
use App\View\Alert\UiAlertPresentation;
use PHPUnit\Framework\TestCase;

final class UiAlertTest extends TestCase
{
    public function testPresentationAddsTitleActionsAndPersistentMode(): void
    {
        $alert = UiAlert::fromLevel('info', 'Operation running')->withPresentation(UiAlertPresentation::loading(
            title: 'Cache clear',
            actions: [UiAlertAction::event('Show details', 'operation:open', ['id' => 'cache-clear'])],
            id: 'operation-cache-clear',
        ));

        self::assertSame([
            'message' => 'Operation running',
            'level' => 'info',
            'persistent' => true,
            'mode' => 'persistent',
            'loading' => true,
            'title' => 'Cache clear',
            'id' => 'operation-cache-clear',
            'actions' => [[
                'label' => 'Show details',
                'event' => 'operation:open',
                'detail' => ['id' => 'cache-clear'],
            ]],
        ], $alert->toArray());
    }

    public function testHiddenPresentationOverridesPreviousPersistentMode(): void
    {
        $alert = UiAlert::fromLevel('warning', 'Background alert', persistent: true)
            ->withPresentation(new UiAlertPresentation(UiAlertMode::Hidden));

        self::assertSame('hidden', $alert->toArray()['mode']);
        self::assertFalse($alert->toArray()['persistent']);
    }

    public function testItCanAttachStableDedupeId(): void
    {
        $alert = UiAlert::fromLevel('success', 'Saved')->withId('ui-alert-test');

        self::assertTrue($alert->hasId());
        self::assertSame('ui-alert-test', $alert->toArray()['id']);
    }

    public function testItDoesNotSerializeDiagnosticContext(): void
    {
        $alert = UiAlert::translated(
            'Package failed.',
            'error',
            'package.runtime.failure',
            'message.package.runtime_failure',
            ['path' => '/srv/example/private.log', 'exception' => 'RuntimeException'],
        );

        self::assertArrayNotHasKey('context', $alert->toArray());
        self::assertSame('message.package.runtime_failure', $alert->toArray()['translation_key']);
    }

    public function testPresentationFiltersUnsafeActionLinks(): void
    {
        $alert = UiAlert::fromLevel('info', 'Saved')->withPresentation(UiAlertPresentation::persistent(actions: [
            UiAlertAction::link('Open', '/admin/packages', '_blank'),
            UiAlertAction::link('Script', 'javascript:alert(1)'),
            ['label' => 'Data', 'href' => 'data:text/html,boom'],
            ['label' => 'Protocol-relative', 'href' => '//evil.example.test/path'],
            ['label' => 'Hostless http', 'href' => 'http:evil.example.test'],
            ['label' => 'External', 'href' => 'https://example.test/privacy', 'target' => '_self'],
            ['label' => 'Event', 'event' => 'operation-overlay:show', 'detail' => ['id' => 'operation-1']],
        ]));

        self::assertSame([
            ['label' => 'Open', 'href' => '/admin/packages', 'target' => '_blank'],
            ['label' => 'External', 'href' => 'https://example.test/privacy', 'target' => '_self'],
            ['label' => 'Event', 'event' => 'operation-overlay:show', 'detail' => ['id' => 'operation-1']],
        ], $alert->toArray()['actions']);
    }

    public function testDirectAlertActionsUseTheSameLinkPolicy(): void
    {
        $alert = UiAlert::fromLevel('info', 'Saved', actions: [
            ['label' => 'Open', 'href' => '/admin/packages'],
            ['label' => 'Script', 'href' => 'javascript:alert(1)'],
            ['label' => 'Event', 'event' => 'operation-overlay:show'],
        ]);

        self::assertSame([
            ['label' => 'Open', 'href' => '/admin/packages'],
            ['label' => 'Event', 'event' => 'operation-overlay:show'],
        ], $alert->toArray()['actions']);
    }
}
