import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        if (!this.hasUrlValue || !this.urlValue || typeof window.EventSource !== 'function') {
            return;
        }

        this.source = new EventSource(this.urlValue, { withCredentials: true });
        this.source.addEventListener('message', this.receive);
        this.source.addEventListener('ui-alert', this.receive);
    }

    disconnect() {
        if (!this.source) {
            return;
        }

        this.source.removeEventListener('message', this.receive);
        this.source.removeEventListener('ui-alert', this.receive);
        this.source.close();
    }

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
}
