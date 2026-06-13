import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static reconnectBaseDelay = 1000;
    static reconnectMaxDelay = 30000;

    static values = {
        url: String,
        credentials: { type: Boolean, default: false },
    };

    connect() {
        if (!this.hasUrlValue || !this.urlValue || typeof window.EventSource !== 'function') {
            return;
        }

        this.reconnectAttempts = 0;
        this.shouldReconnect = true;
        document.addEventListener('visibilitychange', this.reconnectWhenActive);
        window.addEventListener('online', this.reconnectWhenActive);
        this.openSource();
    }

    disconnect() {
        this.shouldReconnect = false;
        window.clearTimeout(this.reconnectTimer);
        document.removeEventListener('visibilitychange', this.reconnectWhenActive);
        window.removeEventListener('online', this.reconnectWhenActive);
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
    };

    error = () => {
        if (this.source?.readyState === EventSource.CLOSED) {
            this.closeSource();
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
