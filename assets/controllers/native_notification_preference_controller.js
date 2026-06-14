import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];
    static values = {
        deniedMessage: String,
    };

    async submit(event) {
        if (this.submitting || !this.hasInputTarget || !this.inputTarget.checked) {
            return;
        }

        if (!this.canRequestPermission) {
            event.preventDefault();
            this.inputTarget.checked = false;
            this.showDeniedMessage();
            this.submitWithCurrentState(event);

            return;
        }

        if (!this.needsPermission) {
            return;
        }

        event.preventDefault();

        const permission = await window.Notification.requestPermission();
        if (permission !== 'granted') {
            this.inputTarget.checked = false;
            this.showDeniedMessage();
        }

        this.submitWithCurrentState(event);
    }

    submitWithCurrentState(event) {
        this.submitting = true;
        this.element.requestSubmit(event.submitter || undefined);
    }

    showDeniedMessage() {
        const message = String(this.deniedMessageValue || '').trim();
        if (!message) {
            return;
        }

        const stack = document.querySelector('[data-controller~="alert-stack"]');
        const target = stack || document;

        target.dispatchEvent(new CustomEvent('ui-alert:received', {
            bubbles: true,
            detail: {
                level: 'warning',
                message,
                mode: 'auto',
            },
        }));
    }

    get needsPermission() {
        return this.canRequestPermission && window.Notification.permission === 'default';
    }

    get canRequestPermission() {
        const notification = window.Notification;

        return typeof notification === 'function'
            && typeof notification.requestPermission === 'function'
            && notification.permission !== 'denied';
    }
}
