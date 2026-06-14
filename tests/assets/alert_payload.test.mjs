import test from 'node:test';
import assert from 'node:assert/strict';

import {
    actionDetailFromElement,
    alertIds,
    alertMode,
    normalizeAlertLevel,
    payloadFromAlertElement,
    storableAlertPayload,
} from '../../assets/js/alerts/alert_payload.js';

test('alertIds normalizes single and list values', () => {
    assert.deepEqual(alertIds(' alert-1 '), ['alert-1']);
    assert.deepEqual(alertIds(['one', '', null, ' two ']), ['one', 'two']);
    assert.deepEqual(alertIds(''), []);
});

test('alertMode falls back to auto for unknown modes', () => {
    assert.equal(alertMode({ mode: 'hidden' }), 'hidden');
    assert.equal(alertMode({ mode: 'persistent' }), 'persistent');
    assert.equal(alertMode({ mode: 'unexpected' }), 'auto');
});

test('normalizeAlertLevel maps aliases to supported levels', () => {
    assert.equal(normalizeAlertLevel('danger'), 'error');
    assert.equal(normalizeAlertLevel('warn'), 'warning');
    assert.equal(normalizeAlertLevel('notice'), 'info');
    assert.equal(normalizeAlertLevel('debug'), 'debug');
    assert.equal(normalizeAlertLevel('unknown'), 'info');
});

test('storableAlertPayload keeps only display-safe alert fields', () => {
    const payload = storableAlertPayload({
        id: 'alert-1',
        title: 'Title',
        message: 'Message',
        level: 'danger',
        mode: 'persistent',
        persistent: true,
        loading: true,
        actions: [{ label: 'Open' }],
        context: { localPath: '/secret' },
    });

    assert.deepEqual(payload, {
        id: 'alert-1',
        title: 'Title',
        message: 'Message',
        level: 'error',
        mode: 'persistent',
        persistent: true,
        loading: true,
        actions: [{ label: 'Open' }],
    });
    assert.equal(Object.hasOwn(payload, 'context'), false);
});

test('payloadFromAlertElement reads structured dataset payloads', () => {
    const alert = {
        dataset: {
            alertId: 'server-alert',
            alertMode: 'persistent',
            alertPayload: JSON.stringify({
                title: 'Server',
                message: 'Rendered',
                level: 'success',
            }),
        },
    };

    assert.deepEqual(payloadFromAlertElement(alert), {
        id: 'server-alert',
        title: 'Server',
        message: 'Rendered',
        level: 'success',
        mode: 'persistent',
    });
});

test('payloadFromAlertElement falls back to text content when JSON is invalid', () => {
    const alert = {
        dataset: {
            alertId: 'fallback-alert',
            alertMode: 'auto',
            alertPersistent: 'true',
            alertPayload: '{broken',
        },
        classList: ['system-alert', 'system-alert-warning'],
        querySelector(selector) {
            const text = {
                '.system-alert-title': 'Fallback title',
                '.system-alert-message': 'Fallback message',
                '.system-alert-content': 'Fallback content',
            }[selector];

            return text ? { textContent: text } : null;
        },
    };

    assert.deepEqual(payloadFromAlertElement(alert), {
        id: 'fallback-alert',
        title: 'Fallback title',
        message: 'Fallback message',
        level: 'warning',
        mode: 'auto',
        persistent: true,
        actions: [],
    });
});

test('actionDetailFromElement parses action details safely', () => {
    assert.deepEqual(actionDetailFromElement({
        dataset: {
            alertActionDetail: '{"id":"operation"}',
        },
    }), { id: 'operation' });

    assert.deepEqual(actionDetailFromElement({
        dataset: {
            alertActionDetail: '{broken',
        },
    }), {});
});
