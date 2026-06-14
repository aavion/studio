import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'panel'];
    static values = {
        open: { type: Boolean, default: false },
    };

    connect() {
        this.apply();
    }

    toggle() {
        this.openValue = !this.openValue;
    }

    open() {
        this.openValue = true;
    }

    close() {
        this.openValue = false;
    }

    openValueChanged() {
        this.apply();
    }

    apply() {
        for (const panel of this.panelTargets) {
            panel.hidden = !this.openValue;
        }

        for (const trigger of this.triggerTargets) {
            trigger.setAttribute('aria-expanded', String(this.openValue));
        }
    }
}
