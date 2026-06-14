import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static fallbackStorage = new Map();
    static storagePrefix = 'studio.filter-form.focus.';

    static values = {
        autoSubmit: { type: Boolean, default: true },
        debounce: { type: Number, default: 450 },
    };

    connect() {
        this.restoreFocus();
    }

    queue(event) {
        if (!this.autoSubmitValue || this.submitting) {
            return;
        }

        window.clearTimeout(this.timer);

        const target = event.target;
        const delay = target instanceof HTMLInputElement && target.type === 'search'
            ? this.debounceValue
            : 0;

        this.timer = window.setTimeout(() => this.submitNow(), delay);
    }

    submit(event) {
        this.resetPage();
        this.rememberFocus();
        this.submitting = true;
        this.element.setAttribute('aria-busy', 'true');

        for (const button of this.element.querySelectorAll('button[type="submit"]')) {
            button.disabled = true;
        }
    }

    submitNow() {
        this.resetPage();
        this.rememberFocus();
        this.element.requestSubmit();
    }

    resetPage() {
        const page = this.element.querySelector('input[name="page"]');

        if (page instanceof HTMLInputElement) {
            page.value = '1';
        }
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }

    rememberFocus() {
        const active = document.activeElement;

        if (!this.element.contains(active) || !(active instanceof HTMLInputElement || active instanceof HTMLSelectElement || active instanceof HTMLTextAreaElement)) {
            return;
        }

        const name = active.name || active.id;

        if (!name) {
            return;
        }

        this.storeFocusState(JSON.stringify({
            name,
            selectionStart: typeof active.selectionStart === 'number' ? active.selectionStart : null,
            selectionEnd: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
        }));
    }

    restoreFocus() {
        const raw = this.takeFocusState();

        if (!raw) {
            return;
        }

        let state;

        try {
            state = JSON.parse(raw);
        } catch (error) {
            return;
        }

        if (!state || typeof state.name !== 'string') {
            return;
        }

        window.requestAnimationFrame(() => {
            const field = this.element.querySelector(`[name="${CSS.escape(state.name)}"], #${CSS.escape(state.name)}`);

            if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
                return;
            }

            field.focus({ preventScroll: true });

            if (typeof state.selectionStart === 'number' && typeof state.selectionEnd === 'number' && 'setSelectionRange' in field) {
                field.setSelectionRange(state.selectionStart, state.selectionEnd);
            }
        });
    }

    get storageKey() {
        const action = this.element.getAttribute('action') || window.location.pathname;

        return `${this.constructor.storagePrefix}${window.location.pathname}.${this.element.method}.${action}`;
    }

    storeFocusState(value) {
        try {
            window.sessionStorage.setItem(this.storageKey, value);
            return;
        } catch (error) {
            this.constructor.fallbackStorage.set(this.storageKey, value);
        }
    }

    takeFocusState() {
        try {
            const value = window.sessionStorage.getItem(this.storageKey);
            window.sessionStorage.removeItem(this.storageKey);

            return value;
        } catch (error) {
            const value = this.constructor.fallbackStorage.get(this.storageKey) || null;
            this.constructor.fallbackStorage.delete(this.storageKey);

            return value;
        }
    }
}
