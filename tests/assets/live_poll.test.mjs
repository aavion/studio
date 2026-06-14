import test from 'node:test';
import assert from 'node:assert/strict';

import { LivePoller, liveRouteUrl } from '../../assets/js/live/live_poll.js';

test('pollOnce fetches JSON payloads with the current cursor', async () => {
    installWindow();

    const requestedUrls = [];
    const payloads = [];
    const poller = new LivePoller({
        fetcher: async (url, options) => {
            requestedUrls.push(url);
            assert.equal(options.headers.Accept, 'application/json');
            assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');

            return jsonResponse({ cursor: 7, status: 'queued', next_poll_ms: 0 });
        },
        onPayload: (payload, cursor) => payloads.push({ payload, cursor }),
    });

    const result = await poller.pollOnce('/api/live/alerts', 3);

    assert.equal(new URL(requestedUrls[0]).searchParams.get('cursor'), '3');
    assert.deepEqual(result, { cursor: 7, status: 'queued', next_poll_ms: 0 });
    assert.deepEqual(payloads, [{
        payload: { cursor: 7, status: 'queued', next_poll_ms: 0 },
        cursor: 7,
    }]);
});

test('poll stops and calls onDone for terminal payloads', async () => {
    installWindow();

    const donePayloads = [];
    const poller = new LivePoller({
        fetcher: async () => jsonResponse({ cursor: 4, status: 'success', next_poll_ms: 750 }),
        onDone: (payload) => donePayloads.push(payload),
    });

    const result = await poller.poll('/api/live/operations/1', 0);

    assert.equal(poller.active, false);
    assert.deepEqual(result, { cursor: 4, status: 'success', next_poll_ms: 750 });
    assert.deepEqual(donePayloads, [{ cursor: 4, status: 'success', next_poll_ms: 750 }]);
});

test('poll returns null after a non-retryable HTTP failure', async () => {
    installWindow();

    const errors = [];
    const poller = new LivePoller({
        fetcher: async () => response({
            ok: false,
            status: 503,
            contentType: 'application/json',
            payload: { status: 'unavailable' },
        }),
        onError: (response, error) => errors.push({ response, error }),
    });

    const result = await poller.poll('/api/live/alerts', 0);

    assert.equal(result, null);
    assert.equal(errors.length, 1);
    assert.equal(errors[0].response.status, 503);
    assert.equal(errors[0].error, null);
});

test('poll retries transient failures when retryOnError is enabled', async () => {
    installWindow({ immediateTimeout: true });

    let calls = 0;
    const errors = [];
    const payloads = [];
    const poller = new LivePoller({
        interval: 0,
        retryOnError: true,
        fetcher: async () => {
            calls += 1;

            return calls === 1
                ? response({ ok: false, status: 503 })
                : jsonResponse({ cursor: 2, status: 'queued', next_poll_ms: 0 });
        },
        onError: (response, error) => errors.push({ response, error }),
        onPayload: (payload, cursor) => payloads.push({ payload, cursor }),
    });

    const result = await poller.poll('/api/live/alerts', 0);

    assert.equal(calls, 2);
    assert.equal(errors.length, 1);
    assert.equal(errors[0].response.status, 503);
    assert.deepEqual(payloads, [{
        payload: { cursor: 2, status: 'queued', next_poll_ms: 0 },
        cursor: 2,
    }]);
    assert.deepEqual(result, { cursor: 2, status: 'queued', next_poll_ms: 0 });
});

test('readJson rejects non-JSON responses with the configured message', async () => {
    installWindow();

    const errors = [];
    const poller = new LivePoller({
        fetcher: async () => response({ ok: true, status: 200, contentType: 'text/html' }),
        invalidJsonMessage: 'Translated invalid response.',
        onError: (response, error) => errors.push({ response, error }),
    });

    const result = await poller.pollOnce('/api/live/alerts', 0);

    assert.equal(result, null);
    assert.equal(errors.length, 1);
    assert.equal(errors[0].response, null);
    assert.equal(errors[0].error.message, 'Translated invalid response.');
});

test('liveRouteUrl builds relative live API URLs', () => {
    installWindow({ origin: 'https://studio.example.test' });

    assert.equal(
        liveRouteUrl('/captcha-pack/seed'),
        'https://studio.example.test/api/live/captcha-pack/seed',
    );
});

function jsonResponse(payload, status = 200) {
    return response({
        ok: status >= 200 && status < 300,
        status,
        contentType: 'application/json; charset=utf-8',
        payload,
    });
}

function response({ ok, status = 200, contentType = 'application/json', payload = {} }) {
    return {
        ok,
        status,
        headers: {
            get(name) {
                return name.toLowerCase() === 'content-type' ? contentType : '';
            },
        },
        async json() {
            return payload;
        },
    };
}

function installWindow({ origin = 'http://127.0.0.1:8000', immediateTimeout = false } = {}) {
    globalThis.window = {
        fetch: async () => {
            throw new Error('Unexpected fetch call.');
        },
        location: { origin },
        setTimeout(callback, delay) {
            return immediateTimeout ? setTimeout(callback, 0) : setTimeout(callback, delay);
        },
    };
}
