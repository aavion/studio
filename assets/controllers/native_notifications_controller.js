import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        enabled: { type: Boolean, default: false },
        title: { type: String, default: 'Notifications' },
    };

    connect() {
        document.addEventListener('ui-alert:shown', this.notify);
    }

    disconnect() {
        document.removeEventListener('ui-alert:shown', this.notify);
    }

    notify = (event) => {
        if (!this.enabledValue || !this.canNotify) {
            return;
        }

        const payload = event.detail || {};
        const title = String(payload.title || this.titleValue || '').trim();
        const body = String(payload.message || '').trim();

        if (!title && !body) {
            return;
        }

        try {
            new Notification(title || this.titleValue, { body });
        } catch {
            // Browser notification support is best-effort and must never break alerts.
        }
    };

    get canNotify() {
        return typeof window.Notification === 'function' && Notification.permission === 'granted';
    }
}
