import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'status'];
    static values = {
        text: String,
        success: String,
        failure: String,
    };

    async copy(event) {
        event.preventDefault();

        try {
            await this.copyText(this.text);
            this.status = this.successValue || '';
        } catch (error) {
            this.status = this.failureValue || '';
        }
    }

    async copyText(text) {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.insetBlockStart = '-100vh';
        document.body.append(input);
        input.select();

        try {
            if (!document.execCommand('copy')) {
                throw new Error('Copy command failed.');
            }
        } finally {
            input.remove();
        }
    }

    get text() {
        if (this.hasTextValue) {
            return this.textValue;
        }

        if (this.hasSourceTarget) {
            return 'value' in this.sourceTarget
                ? this.sourceTarget.value
                : this.sourceTarget.textContent.trim();
        }

        return this.element.textContent.trim();
    }

    set status(message) {
        if (this.hasStatusTarget && message) {
            this.statusTarget.textContent = message;
            this.statusTarget.removeAttribute('hidden');
        }
    }
}
