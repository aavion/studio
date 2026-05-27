import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        enabled: Boolean,
        redirectOnSuccess: String,
    };

    connect() {
        const stored = this.storedOperation();

        if (this.enabledValue && stored?.statusUrl) {
            this.open();
            this.reset();
            this.poll(stored.statusUrl, Number(stored.cursor || 0));
        }
    }

    async submit(event) {
        if (!this.enabledValue) {
            return;
        }

        event.preventDefault();

        const stored = this.storedOperation();

        if (stored?.statusUrl) {
            this.open();
            this.reset();
            await this.poll(stored.statusUrl, Number(stored.cursor || 0));

            return;
        }

        await this.startOperation();
    }

    async startOperation() {
        if (this.starting) {
            return;
        }

        this.starting = true;
        this.open();
        this.reset();

        const formData = new FormData(this.element);
        formData.set('_operation_live', '1');

        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
                body: formData,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();

            if (!response.ok || !payload.success || !payload.value?.status_url) {
                this.clearStoredOperation();
                this.fail(payload.issues?.[0]?.message || payload.issues?.[0]?.translation_key || this.label('startError'));

                return;
            }

            this.storeOperation(payload.value.status_url, 0);
            await this.poll(payload.value.status_url);
        } catch (error) {
            this.clearStoredOperation();
            this.fail(error instanceof Error ? error.message : this.label('requestError'));
        } finally {
            this.starting = false;
        }
    }

    async poll(statusUrl, cursor = 0) {
        this.polling = true;

        try {
            while (this.polling) {
                const url = new URL(statusUrl, window.location.origin);
                url.searchParams.set('cursor', String(cursor));
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    if (response.status === 404) {
                        this.clearStoredOperation();
                        this.fail(this.label('statusError'));
                        this.retryButton.hidden = false;

                        return;
                    }

                    this.fail(this.label('statusError'), true);

                    return;
                }

                const payload = await response.json();
                cursor = Number(payload.cursor || cursor);
                this.storeOperation(statusUrl, cursor, payload.continue_url || null);
                this.render(payload);

                if (['success', 'requires_review', 'failed'].includes(payload.status)) {
                    this.finish(payload);

                    return;
                }

                await this.sleep(Number(payload.next_poll_ms || 750));
            }
        } catch (error) {
            this.fail(error instanceof Error ? error.message : this.label('requestError'), true);
        }
    }

    render(payload) {
        this.summaryElement.textContent = `${payload.label} - ${this.statusLabel(payload.status)} (${payload.progress?.index || 0}/${payload.progress?.total || 0})`;
        this.emptyElement?.remove();

        for (const entry of payload.entries || []) {
            const item = document.createElement('li');
            item.className = 'studio-action-log-entry';

            const title = document.createElement('strong');
            title.textContent = `[${entry.index}/${entry.total}] ${entry.name}`;
            item.append(title);

            const status = document.createElement('span');
            status.className = `studio-badge studio-badge-${this.tone(entry.status)}`;
            status.textContent = this.statusLabel(entry.status);
            item.append(status);

            for (const issue of entry.issues || []) {
                const message = document.createElement('p');
                message.textContent = issue.message || issue.translation_key || issue.code;
                item.append(message);
            }

            for (const entryMessage of entry.messages || []) {
                const message = document.createElement('p');
                message.textContent = entryMessage.message || entryMessage.translation_key || entryMessage.code;
                item.append(message);
            }

            this.listElement.append(item);
        }

        if (!this.resultRendered && ['success', 'requires_review', 'failed'].includes(payload.status) && payload.result?.issues?.length) {
            const item = document.createElement('li');
            item.className = 'studio-action-log-entry is-result';
            const title = document.createElement('strong');
            title.textContent = this.label('result');
            item.append(title);

            for (const issue of payload.result.issues) {
                const message = document.createElement('p');
                message.textContent = issue.message || issue.translation_key || issue.code;
                item.append(message);
            }
            this.listElement.append(item);
            this.resultRendered = true;
        }
    }

    open() {
        this.rootElement.hidden = false;
        this.finishedStatus = null;
        this.hideButtons();
        this.okButton.onclick = this.ok;
        this.continueButton.onclick = this.continueOperation;
        this.retryButton.onclick = this.retry;
        this.refreshButton.onclick = this.refresh;
        this.cancelButton.onclick = this.cancel;
        this.closeButton.onclick = this.close;
    }

    ok = () => {
        this.clearStoredOperation();

        if (this.hasRedirectOnSuccessValue && this.redirectOnSuccessValue) {
            window.location.assign(this.redirectOnSuccessValue);

            return;
        }

        window.location.reload();
    };

    continueOperation = async () => {
        const stored = this.storedOperation();

        if (!stored?.continueUrl) {
            this.fail(this.label('startError'));

            return;
        }

        this.clearStoredOperation();
        this.reset();

        try {
            const response = await fetch(stored.continueUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();

            if (!response.ok || !payload.success || !payload.value?.status_url) {
                this.fail(payload.issues?.[0]?.message || payload.issues?.[0]?.translation_key || this.label('startError'));

                return;
            }

            this.storeOperation(payload.value.status_url, 0);
            await this.poll(payload.value.status_url);
        } catch (error) {
            this.fail(error instanceof Error ? error.message : this.label('requestError'));
        }
    };

    retry = () => {
        this.clearStoredOperation();
        this.startOperation();
    };

    refresh = () => {
        const stored = this.storedOperation();

        if (!stored?.statusUrl) {
            this.close();

            return;
        }

        this.reset();
        this.poll(stored.statusUrl, Number(stored.cursor || 0));
    };

    cancel = () => {
        this.clearStoredOperation();
        this.close();
    };

    close = () => {
        this.polling = false;
        this.rootElement.hidden = true;
    };

    reset() {
        this.summaryElement.textContent = this.label('starting');
        this.listElement.replaceChildren();
        this.spinnerElement.hidden = false;
        this.resultRendered = false;
        this.hideButtons();
    }

    finish(payload) {
        const status = payload.status;
        this.finishedStatus = status;
        this.polling = false;
        this.spinnerElement.hidden = true;
        this.summaryElement.textContent = status === 'success'
            ? this.label('completed')
            : (status === 'requires_review' ? this.label('requiresReview') : this.label('failed'));
        this.hideButtons();

        if (status === 'success') {
            this.clearStoredOperation();
            this.okButton.hidden = false;

            return;
        }

        if (status === 'requires_review') {
            this.continueButton.hidden = !payload.continue_url;
            this.cancelButton.hidden = false;

            return;
        }

        this.clearStoredOperation();
        this.retryButton.hidden = false;
        this.cancelButton.hidden = false;
    }

    fail(message, refreshable = false) {
        this.polling = false;
        this.spinnerElement.hidden = true;
        this.summaryElement.textContent = message;
        this.hideButtons();

        if (refreshable) {
            this.refreshButton.hidden = false;
            this.closeButton.hidden = false;

            return;
        }

        this.closeButton.hidden = false;
    }

    hideButtons() {
        this.okButton.hidden = true;
        this.continueButton.hidden = true;
        this.retryButton.hidden = true;
        this.refreshButton.hidden = true;
        this.cancelButton.hidden = true;
        this.closeButton.hidden = true;
    }

    sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    tone(status) {
        if (status === 'success') {
            return 'success';
        }

        if (status === 'failed') {
            return 'error';
        }

        if (status === 'requires_review') {
            return 'warning';
        }

        if (status === 'warning') {
            return 'warning';
        }

        return 'neutral';
    }

    storageKey() {
        const formData = new FormData(this.element);
        const formId = formData.get('_form_id') || '';
        const backendAction = formData.get('_backend_action') || '';

        return `studio.operation.${this.element.action}.${formId}.${backendAction}`;
    }

    storedOperation() {
        try {
            const raw = window.sessionStorage.getItem(this.storageKey());

            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    storeOperation(statusUrl, cursor, continueUrl = null) {
        try {
            window.sessionStorage.setItem(this.storageKey(), JSON.stringify({
                statusUrl,
                cursor,
                continueUrl,
                updatedAt: new Date().toISOString(),
            }));
        } catch {
            // Session storage can be unavailable in hardened browser contexts.
        }
    }

    clearStoredOperation() {
        try {
            window.sessionStorage.removeItem(this.storageKey());
        } catch {
            // Session storage can be unavailable in hardened browser contexts.
        }
    }

    get rootElement() {
        return document.querySelector('[data-operation-overlay-root]');
    }

    get summaryElement() {
        return this.rootElement.querySelector('[data-operation-overlay-summary]');
    }

    get listElement() {
        return this.rootElement.querySelector('[data-operation-overlay-list]');
    }

    get emptyElement() {
        return this.rootElement.querySelector('[data-operation-overlay-empty]');
    }

    get okButton() {
        return this.rootElement.querySelector('[data-operation-overlay-ok]');
    }

    get retryButton() {
        return this.rootElement.querySelector('[data-operation-overlay-retry]');
    }

    get continueButton() {
        return this.rootElement.querySelector('[data-operation-overlay-continue]');
    }

    get refreshButton() {
        return this.rootElement.querySelector('[data-operation-overlay-refresh]');
    }

    get cancelButton() {
        return this.rootElement.querySelector('[data-operation-overlay-cancel]');
    }

    get closeButton() {
        return this.rootElement.querySelector('[data-operation-overlay-close]');
    }

    get spinnerElement() {
        return this.rootElement.querySelector('[data-operation-overlay-spinner]');
    }

    label(name) {
        return this.rootElement.dataset[`label${name.charAt(0).toUpperCase()}${name.slice(1)}`] || name;
    }

    statusLabel(status) {
        if (!status) {
            return '';
        }

        const normalized = String(status).replace(/[^a-zA-Z0-9]+(.)/g, (_, character) => character.toUpperCase());

        return this.rootElement.dataset[`labelStatus${normalized.charAt(0).toUpperCase()}${normalized.slice(1)}`] || String(status);
    }
}
