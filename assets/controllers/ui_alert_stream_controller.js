import { Controller } from '@hotwired/stimulus';
import { LivePoller } from '../js/live/live_poll.js';

export default class extends Controller {
    static reconnectBaseDelay = 1000;
    static reconnectMaxDelay = 30000;

    static values = {
        url: String,
        catchUpUrl: String,
        catchUpCursor: { type: Number, default: 0 },
        credentials: { type: Boolean, default: false },
        fallbackUrl: String,
        fallbackInterval: { type: Number, default: 15000 },
    };

    connect() {
        if (!this.hasUrlValue || !this.urlValue) {
            return;
        }

        this.reconnectAttempts = 0;
        this.shouldReconnect = true;
        this.streamOpened = false;
        document.addEventListener('visibilitychange', this.reconnectWhenActive);
        window.addEventListener('online', this.reconnectWhenActive);

        if (typeof window.EventSource !== 'function') {
            this.startFallbackPolling();

            return;
        }

        this.catchUp();
        this.openSource();
    }

    disconnect() {
        this.shouldReconnect = false;
        window.clearTimeout(this.reconnectTimer);
        document.removeEventListener('visibilitychange', this.reconnectWhenActive);
        window.removeEventListener('online', this.reconnectWhenActive);
        this.fallbackPoller?.stop();
        this.closeSource();
    }

    openSource() {
        if (this.source || !this.shouldReconnect) {
            return;
        }

        this.source = new EventSource(this.urlValue, { withCredentials: this.credentialsValue });
        this.source.addEventListener('open', this.open);
        this.source.addEventListener('error', this.error);
        this.source.addEventListener('message', this.receive);
        this.source.addEventListener('ui-alert', this.receive);
    }

    open = () => {
        this.reconnectAttempts = 0;
        this.streamOpened = true;
        this.catchUp();
    };

    error = () => {
        if (this.source?.readyState === EventSource.CLOSED) {
            this.closeSource();
            if (!this.streamOpened) {
                this.startFallbackPolling();

                return;
            }

            this.scheduleReconnect();
        }
    };

    reconnectWhenActive = () => {
        if (document.hidden || !this.shouldReconnect) {
            return;
        }

        if (!this.source || this.source.readyState === EventSource.CLOSED) {
            this.closeSource();
            this.scheduleReconnect(0);
        }
    };

    receive = (event) => {
        try {
            const payload = JSON.parse(event.data || '{}');
            this.element.dispatchEvent(new CustomEvent('ui-alert:received', {
                bubbles: true,
                detail: payload,
            }));
        } catch {
            // Ignore malformed updates; the stream can continue with the next event.
        }
    };

    async catchUp() {
        if (!this.hasCatchUpUrlValue || !this.catchUpUrlValue || typeof window.fetch !== 'function') {
            return;
        }

        if (this.catchUpRunning) {
            this.catchUpRequested = true;

            return;
        }

        this.catchUpRunning = true;

        try {
            let previousCursor = -1;

            do {
                previousCursor = Math.max(0, this.catchUpCursorValue || 0);

                const payload = await this.fetchCatchUpPage(previousCursor);
                if (!payload) {
                    return;
                }

                const cursor = Number(payload.cursor);
                if (Number.isFinite(cursor)) {
                    this.updateCursor(cursor);
                }

                for (const alert of Array.isArray(payload.alerts) ? payload.alerts : []) {
                    this.dispatchAlert(alert);
                }

                if (payload.has_more !== true) {
                    return;
                }
            } while (this.catchUpCursorValue > previousCursor);
        } catch {
            // Stream delivery remains active; the next open/reconnect can catch up again.
        } finally {
            this.catchUpRunning = false;
            if (this.catchUpRequested && this.shouldReconnect) {
                this.catchUpRequested = false;
                this.catchUp();
            }
        }
    }

    async fetchCatchUpPage(cursor) {
        const url = new URL(this.catchUpUrlValue, window.location.origin);
        url.searchParams.set('cursor', String(Math.max(0, cursor || 0)));
        const response = await window.fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            return null;
        }

        return response.json();
    }

    startFallbackPolling() {
        this.shouldReconnect = false;
        if (!this.hasFallbackUrlValue || !this.fallbackUrlValue || this.fallbackPoller) {
            return;
        }

        this.closeSource();
        this.fallbackPoller = new LivePoller({
            interval: this.fallbackIntervalValue,
            onPayload: (payload, cursor) => this.fallbackPayload(payload, cursor),
            retryOnError: true,
        });
        this.fallbackPoller.poll(this.fallbackUrlValue, this.catchUpCursorValue);
    }

    fallbackPayload(payload, cursor) {
        this.updateCursor(cursor);

        for (const alert of Array.isArray(payload.alerts) ? payload.alerts : []) {
            this.dispatchAlert(alert);
        }
    }

    updateCursor(cursor) {
        if (Number.isFinite(Number(cursor))) {
            this.catchUpCursorValue = Math.max(0, this.catchUpCursorValue || 0, Number(cursor));
        }
    }

    dispatchAlert(alert) {
        this.element.dispatchEvent(new CustomEvent('ui-alert:received', {
            bubbles: true,
            detail: alert,
        }));
    }

    scheduleReconnect(delay = null) {
        if (!this.shouldReconnect || this.reconnectTimer) {
            return;
        }

        const nextDelay = delay ?? Math.min(
            this.constructor.reconnectBaseDelay * (2 ** this.reconnectAttempts),
            this.constructor.reconnectMaxDelay,
        );
        this.reconnectAttempts += 1;

        this.reconnectTimer = window.setTimeout(() => {
            this.reconnectTimer = null;
            this.openSource();
        }, nextDelay);
    }

    closeSource() {
        this.source?.removeEventListener('open', this.open);
        this.source?.removeEventListener('error', this.error);
        this.source?.removeEventListener('message', this.receive);
        this.source?.removeEventListener('ui-alert', this.receive);
        this.source?.close();
        this.source = null;
    }
}
