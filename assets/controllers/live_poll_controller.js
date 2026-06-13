import { Controller } from '@hotwired/stimulus';
import { LivePoller, liveRouteUrl } from '../js/live/live_poll.js';

export default class extends Controller {
    static values = {
        url: String,
        relativeRoute: String,
        interval: { type: Number, default: 750 },
        cursor: { type: Number, default: 0 },
        autostart: { type: Boolean, default: true },
    };

    connect() {
        if (this.autostartValue && this.endpoint) {
            this.start();
        }
    }

    disconnect() {
        this.stop();
    }

    start() {
        if (!this.endpoint) {
            return;
        }

        this.poller = new LivePoller({
            interval: this.intervalValue,
            onPayload: (payload, cursor) => this.payload(payload, cursor),
            onError: (response, error) => this.error(response, error),
            onDone: (payload) => this.done(payload),
        });
        this.poller.poll(this.endpoint, this.cursorValue);
    }

    stop() {
        this.poller?.stop();
    }

    payload(payload, cursor) {
        this.cursorValue = cursor;
        this.dispatch('payload', { detail: { payload, cursor } });
    }

    error(response, error) {
        this.dispatch('error', { detail: { response, error } });
    }

    done(payload) {
        this.dispatch('done', { detail: { payload } });
    }

    get endpoint() {
        if (this.hasUrlValue && this.urlValue) {
            return this.urlValue;
        }

        if (this.hasRelativeRouteValue && this.relativeRouteValue) {
            return liveRouteUrl(this.relativeRouteValue);
        }

        return null;
    }
}
