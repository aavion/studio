import test from 'node:test';
import assert from 'node:assert/strict';

import { loadStimulusController } from './support/controller_loader.mjs';
import { FakeElement, installDom } from './support/fake_dom.mjs';

const { default: LivePollController } = await loadStimulusController('assets/controllers/live_poll_controller.js');
const { default: AlertStackController } = await loadStimulusController('assets/controllers/alert_stack_controller.js');
const { default: UiAlertPollController } = await loadStimulusController('assets/controllers/ui_alert_poll_controller.js');
const { default: UiAlertStreamController } = await loadStimulusController('assets/controllers/ui_alert_stream_controller.js');

test('live poll controller resolves relative routes and dispatches payload lifecycle events', () => {
    installDom({ origin: 'https://studio.example.test' });

    const controller = new LivePollController();
    const element = new FakeElement();
    const events = [];
    element.addEventListener('payload', (event) => events.push({ type: 'payload', detail: event.detail }));
    element.addEventListener('error', (event) => events.push({ type: 'error', detail: event.detail }));
    element.addEventListener('done', (event) => events.push({ type: 'done', detail: event.detail }));
    controller.element = element;
    controller.hasUrlValue = false;
    controller.hasRelativeRouteValue = true;
    controller.relativeRouteValue = '/captcha/seed';

    assert.equal(controller.endpoint, 'https://studio.example.test/api/live/captcha/seed');

    const payload = { status: 'queued' };
    controller.payload(payload, 9);
    controller.error({ status: 503 }, null);
    controller.done({ status: 'success' });

    assert.equal(controller.cursorValue, 9);
    assert.deepEqual(events, [
        { type: 'payload', detail: { payload, cursor: 9 } },
        { type: 'error', detail: { response: { status: 503 }, error: null } },
        { type: 'done', detail: { payload: { status: 'success' } } },
    ]);
});

test('UI alert polling dispatches each received alert and advances the cursor', () => {
    installDom();

    const controller = new UiAlertPollController();
    const element = new FakeElement();
    const received = [];
    element.addEventListener('ui-alert:received', (event) => received.push(event.detail));
    controller.element = element;

    controller.payload({
        alerts: [
            { id: 'one', message: 'First' },
            { id: 'two', message: 'Second' },
        ],
    }, 12);

    assert.equal(controller.cursorValue, 12);
    assert.deepEqual(received, [
        { id: 'one', message: 'First' },
        { id: 'two', message: 'Second' },
    ]);
});

test('alert stack stores new alerts, deduplicates updates, and closes all active alerts', () => {
    const { sessionStorage } = installDom();

    const controller = new AlertStackController();
    const element = new FakeElement();
    const list = new FakeElement();
    const panel = new FakeElement();
    const badge = new FakeElement();
    const toggle = new FakeElement('button');
    const clearAll = new FakeElement('button');
    const empty = new FakeElement();
    const shown = [];
    document.addEventListener('ui-alert:shown', (event) => shown.push(event.detail));
    element.dataset.alertCloseLabel = 'Close';
    panel.hidden = true;
    controller.element = element;
    controller.listTarget = list;
    controller.panelTarget = panel;
    controller.badgeTarget = badge;
    controller.toggleTarget = toggle;
    controller.clearAllTarget = clearAll;
    controller.emptyTarget = empty;
    controller.hasClearAllTarget = true;
    controller.hasEmptyTarget = true;
    controller.storageScopeValue = 'session:test';
    controller.dismissDelayValue = 8000;
    controller.initialize();

    controller.upsertAlert({ id: 'alert-1', level: 'success', message: 'Saved' });
    controller.upsertAlert({ id: 'alert-1', level: 'success', message: 'Saved' });

    assert.equal(controller.activeCount, 1);
    assert.equal(list.children.length, 1);
    assert.equal(badge.textContent, '1');
    assert.equal(panel.hidden, false);
    assert.equal(shown.length, 1);
    assert.deepEqual(JSON.parse(sessionStorage.getItem(controller.storageKey)), [{
        id: 'alert-1',
        level: 'success',
        message: 'Saved',
        mode: 'auto',
        persistent: false,
        loading: false,
        actions: [],
    }]);

    controller.closeAll({ preventDefault() {} });

    assert.equal(controller.activeCount, 0);
    assert.equal(list.children.length, 0);
    assert.equal(badge.hidden, true);
    assert.equal(empty.hidden, false);
    assert.deepEqual(JSON.parse(sessionStorage.getItem(controller.storageKey)), []);
    assert.deepEqual(JSON.parse(sessionStorage.getItem(controller.closedStorageKey)), ['alert-1']);
});

test('alert stack auto-dismiss removes transient alerts without closing future duplicates', () => {
    const { sessionStorage, window } = installDom();
    let scheduled = null;
    window.setTimeout = (callback) => {
        scheduled = callback;

        return 1;
    };

    const controller = new AlertStackController();
    const element = new FakeElement();
    const list = new FakeElement();
    const panel = new FakeElement();
    const badge = new FakeElement();
    const toggle = new FakeElement('button');
    const clearAll = new FakeElement('button');
    const empty = new FakeElement();
    const closed = [];
    document.addEventListener('ui-alert:closed', (event) => closed.push(event.detail));
    panel.hidden = true;
    controller.element = element;
    controller.listTarget = list;
    controller.panelTarget = panel;
    controller.badgeTarget = badge;
    controller.toggleTarget = toggle;
    controller.clearAllTarget = clearAll;
    controller.emptyTarget = empty;
    controller.hasClearAllTarget = true;
    controller.hasEmptyTarget = true;
    controller.storageScopeValue = 'session:auto';
    controller.dismissDelayValue = 10;
    controller.initialize();

    controller.upsertAlert({ id: 'auto-alert', level: 'success', message: 'Saved', mode: 'auto' });
    scheduled();

    assert.equal(controller.activeCount, 0);
    assert.equal(list.children.length, 0);
    assert.deepEqual(JSON.parse(sessionStorage.getItem(controller.storageKey)), []);
    assert.equal(sessionStorage.getItem(controller.closedStorageKey), null);
    assert.deepEqual(closed, []);

    controller.upsertAlert({ id: 'auto-alert', level: 'success', message: 'Saved again', mode: 'auto' });

    assert.equal(controller.activeCount, 1);
    assert.equal(list.children.length, 1);
});

test('alert stack auto-dismiss keeps persistent alerts active', () => {
    const { sessionStorage, window } = installDom();
    let scheduled = null;
    window.setTimeout = (callback) => {
        scheduled = callback;

        return 1;
    };

    const controller = new AlertStackController();
    const element = new FakeElement();
    const list = new FakeElement();
    const panel = new FakeElement();
    const badge = new FakeElement();
    const toggle = new FakeElement('button');
    const clearAll = new FakeElement('button');
    const empty = new FakeElement();
    panel.hidden = true;
    controller.element = element;
    controller.listTarget = list;
    controller.panelTarget = panel;
    controller.badgeTarget = badge;
    controller.toggleTarget = toggle;
    controller.clearAllTarget = clearAll;
    controller.emptyTarget = empty;
    controller.hasClearAllTarget = true;
    controller.hasEmptyTarget = true;
    controller.storageScopeValue = 'session:persistent';
    controller.dismissDelayValue = 10;
    controller.initialize();

    controller.upsertAlert({ id: 'persistent-alert', level: 'info', message: 'Review details', mode: 'persistent' });
    controller.scheduleHide();
    scheduled();

    assert.equal(controller.activeCount, 1);
    assert.equal(list.children.length, 1);
    assert.equal(JSON.parse(sessionStorage.getItem(controller.storageKey))[0].id, 'persistent-alert');
});

test('UI alert stream opens EventSource with credentials and forwards valid alert events', () => {
    installDom();

    const sources = [];
    class FakeEventSource {
        static CLOSED = 2;

        constructor(url, options) {
            this.url = url;
            this.options = options;
            this.listeners = new Map();
            this.readyState = 0;
            this.closed = false;
            sources.push(this);
        }

        addEventListener(type, listener) {
            this.listeners.set(type, listener);
        }

        removeEventListener(type) {
            this.listeners.delete(type);
        }

        close() {
            this.closed = true;
        }

        emit(type, data = '{}') {
            this.listeners.get(type)?.({ data });
        }
    }
    window.EventSource = FakeEventSource;
    globalThis.EventSource = FakeEventSource;

    const controller = new UiAlertStreamController();
    const element = new FakeElement();
    const received = [];
    element.addEventListener('ui-alert:received', (event) => received.push(event.detail));
    controller.element = element;
    controller.hasUrlValue = true;
    controller.urlValue = 'http://127.0.0.1:3000/.well-known/mercure?topic=alerts';
    controller.credentialsValue = true;

    controller.connect();
    sources[0].emit('ui-alert', JSON.stringify({ id: 'push', message: 'Pushed' }));
    sources[0].emit('message', '{broken');
    controller.disconnect();

    assert.equal(sources.length, 1);
    assert.equal(sources[0].url, controller.urlValue);
    assert.deepEqual(sources[0].options, { withCredentials: true });
    assert.deepEqual(received, [{ id: 'push', message: 'Pushed' }]);
    assert.equal(sources[0].closed, true);
});

test('UI alert stream schedules reconnect when the stream closes', () => {
    const { window } = installDom();

    let scheduledDelay = null;
    window.setTimeout = (callback, delay) => {
        scheduledDelay = delay;
        callback();

        return 1;
    };

    const sources = [];
    class FakeEventSource {
        static CLOSED = 2;

        constructor() {
            this.listeners = new Map();
            this.readyState = 0;
            sources.push(this);
        }

        addEventListener(type, listener) {
            this.listeners.set(type, listener);
        }

        removeEventListener(type) {
            this.listeners.delete(type);
        }

        close() {}
    }
    window.EventSource = FakeEventSource;
    globalThis.EventSource = FakeEventSource;

    const controller = new UiAlertStreamController();
    controller.element = new FakeElement();
    controller.hasUrlValue = true;
    controller.urlValue = 'http://127.0.0.1:3000/.well-known/mercure?topic=alerts';
    controller.credentialsValue = false;

    controller.connect();
    sources[0].readyState = FakeEventSource.CLOSED;
    sources[0].listeners.get('error')();

    assert.equal(scheduledDelay, UiAlertStreamController.reconnectBaseDelay);
    assert.equal(sources.length, 2);
});
