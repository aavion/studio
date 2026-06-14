import { Controller } from '@hotwired/stimulus';
import { LivePoller } from '../js/live/live_poll.js';

export default class extends Controller {
    static values = {
        url: String,
        interval: { type: Number, default: 15000 },
        cursor: { type: Number, default: 0 },
    };

    connect() {
        if (!this.hasUrlValue || !this.urlValue) {
            return;
        }

        this.poller = new LivePoller({
            interval: this.intervalValue,
            onPayload: (payload, cursor) => this.payload(payload, cursor),
            retryOnError: true,
        });
        this.poller.poll(this.urlValue, this.cursorValue);
    }

    disconnect() {
        this.poller?.stop();
    }

    payload(payload, cursor) {
        this.cursorValue = cursor;

        for (const alert of Array.isArray(payload.alerts) ? payload.alerts : []) {
            this.element.dispatchEvent(new CustomEvent('ui-alert:received', {
                bubbles: true,
                detail: alert,
            }));
        }
    }
}
