import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['details', 'option'];

    connect() {
        this.openFromTrigger = (event) => {
            const trigger = event.target instanceof Element
                ? event.target.closest('[data-cookie-consent-open]')
                : null;

            if (!trigger) {
                return;
            }

            event.preventDefault();
            this.open();
        };

        document.addEventListener('click', this.openFromTrigger);
    }

    disconnect() {
        document.removeEventListener('click', this.openFromTrigger);
    }

    open() {
        this.element.hidden = false;

        if (this.hasDetailsTarget) {
            this.detailsTarget.hidden = false;
        }

        this.element.querySelector('button, input, a')?.focus();
    }

    close(event) {
        event?.preventDefault();
        this.element.hidden = true;
    }

    toggleDetails(event) {
        event.preventDefault();
        this.detailsTarget.hidden = !this.detailsTarget.hidden;
    }

    rejectOptional() {
        for (const option of this.optionTargets) {
            option.checked = false;
        }
    }
}
