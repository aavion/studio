import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['alert'];
    static values = {
        dismissDelay: { type: Number, default: 8000 },
    };

    connect() {
        for (const alert of this.alertTargets) {
            this.schedule(alert);
        }
    }

    alertTargetConnected(alert) {
        this.schedule(alert);
    }

    close(event) {
        event.preventDefault();
        this.dismiss(event.currentTarget.closest('[data-alert-stack-target="alert"]'), false);
    }

    schedule(alert) {
        if (!alert || alert.dataset.alertPersistent === 'true' || alert.dataset.alertScheduled === 'true') {
            return;
        }

        alert.dataset.alertScheduled = 'true';
        window.setTimeout(() => this.dismiss(alert, true), this.dismissDelayValue);
    }

    dismiss(alert, animated) {
        if (!alert || alert.dataset.alertDismissing === 'true') {
            return;
        }

        alert.dataset.alertDismissing = 'true';

        if (!animated) {
            alert.remove();
            return;
        }

        if ('function' === typeof alert.animate) {
            const animation = alert.animate([
                { opacity: 1, transform: 'translateY(0) scale(1)' },
                { opacity: 0, transform: 'translateY(-0.35rem) scale(0.99)' },
            ], {
                duration: 1200,
                easing: 'ease',
                fill: 'forwards',
            });

            animation.finished.finally(() => alert.remove());

            return;
        }

        alert.classList.add('is-leaving');
        window.setTimeout(() => alert.remove(), 1200);
    }
}
