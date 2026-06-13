import { Controller } from '@hotwired/stimulus';
import { LivePoller } from '../js/live/live_poll.js';

export default class extends Controller {
    static values = {
        enabled: Boolean,
        redirectOnSuccess: String,
    };

    static storedOperationMaxAgeMs = 60 * 60 * 1000;

    connect() {
        document.addEventListener('operation-overlay:show', this.showFromAlert);
        document.addEventListener('ui-alert:closed', this.alertClosed);

        const stored = this.storedOperation();

        if (this.enabledValue && stored?.statusUrl) {
            this.prepareOverlay();
            this.updateOperationAlert({
                status: stored.status || 'queued',
                progress: stored.progress || null,
            });
            this.reset();
            this.poll(stored.statusUrl, Number(stored.cursor || 0));
        }
    }

    disconnect() {
        document.removeEventListener('operation-overlay:show', this.showFromAlert);
        document.removeEventListener('ui-alert:closed', this.alertClosed);
        this.livePoller?.stop();
    }

    async submit(event) {
        if (!this.enabledValue) {
            return;
        }

        event.preventDefault();

        const stored = this.storedOperation();

        if (stored?.statusUrl) {
            this.prepareOverlay();
            this.reset();
            await this.poll(stored.statusUrl, Number(stored.cursor || 0));

            return;
        }

        await this.startOperation(event.submitter || null);
    }

    async startOperation(submitter = null) {
        if (this.starting) {
            return;
        }

        this.starting = true;
        this.prepareOverlay();
        this.reset();
        this.updateOperationAlert({
            status: 'queued',
            progress: null,
        });

        const formData = new FormData(this.element);
        if (submitter?.name) {
            formData.set(submitter.name, submitter.value || '');
        }
        if (!formData.get('_setup_action')) {
            const applyButton = this.element.querySelector('button[name="_setup_action"][value="apply"]');
            if (applyButton) {
                formData.set('_setup_action', 'apply');
            }
        }
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
            const payload = await this.readJson(response);

            if (!response.ok || !payload.success || !payload.value?.status_url) {
                this.clearStoredOperation();
                this.fail(payload.issues?.[0]?.message || payload.issues?.[0]?.translation_key || this.label('startError'));

                return;
            }

            this.storeOperation(payload.value.status_url, 0, null, 'queued', null);
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
        this.livePoller = new LivePoller({
            interval: 750,
            onPayload: (payload, nextCursor) => {
                this.storeOperation(
                    statusUrl,
                    nextCursor,
                    payload.continue_url || null,
                    payload.status || null,
                    payload.progress || null,
                    payload.label || payload.operation || null,
                );
                this.render(payload);
            },
            onError: (response, error) => {
                if (response?.status === 404) {
                    this.clearStoredOperation();
                    this.fail(this.label('statusError'));
                    this.retryButton.hidden = false;

                    return;
                }

                this.fail(error instanceof Error ? error.message : this.label('statusError'), true);
            },
            onDone: (payload) => {
                if (payload) {
                    this.finish(payload);
                }
            },
        });

        await this.livePoller.poll(statusUrl, cursor);
    }

    render(payload) {
        if (!['success', 'requires_review', 'failed'].includes(payload.status)) {
            this.setSummary(this.label('waiting'), 'running');
            this.updateOperationAlert(payload);
        }
        this.emptyElement?.remove();

        for (const entry of payload.entries || []) {
            this.renderEntry(entry);
        }

        if (!this.resultRendered && ['success', 'requires_review', 'failed'].includes(payload.status) && payload.result?.issues?.length) {
            const item = document.createElement('li');
            item.className = 'system-backend-action-log-entry is-result';
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

        this.scrollLogToEnd();
    }

    renderEntry(entry) {
        const key = this.entryKey(entry);
        const item = this.stepElements.get(key) || document.createElement('li');
        item.className = 'system-backend-action-log-entry';
        item.dataset.operationEntryKey = key;
        item.replaceChildren();

        const title = document.createElement('strong');
        title.textContent = `[${entry.index}/${entry.total}] ${this.actionLabel(entry.name)}`;
        item.append(title);

        const status = document.createElement('span');
        status.className = `system-badge system-badge-${this.tone(entry.status)}`;
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

        if (!this.stepElements.has(key)) {
            this.listElement.append(item);
            this.stepElements.set(key, item);
        }
    }

    open() {
        this.rootElement.hidden = false;
        this.wireControls();
    }

    prepareOverlay() {
        this.finishedStatus = null;
        this.wireControls();
        this.hideButtons();
        this.rootElement.hidden = true;
    }

    wireControls() {
        this.okButton.onclick = this.ok;
        this.continueButton.onclick = this.continueOperation;
        this.retryButton.onclick = this.retry;
        this.refreshButton.onclick = this.refresh;
        this.cancelButton.onclick = this.cancel;
        this.closeButton.onclick = this.close;
        this.closeIconButton.onclick = this.close;
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

        this.reset();

        try {
            const response = await fetch(stored.continueUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await this.readJson(response);

            if (!response.ok || !payload.success || !payload.value?.status_url) {
                this.fail(payload.issues?.[0]?.message || payload.issues?.[0]?.translation_key || this.label('startError'));

                return;
            }

            this.storeOperation(payload.value.status_url, 0, null, 'queued', null, payload.value.label || payload.value.operation || null);
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
        this.livePoller?.stop();
        this.rootElement.hidden = true;
    };

    reset() {
        this.setSummary(this.label('starting'), 'running');
        this.listElement.replaceChildren();
        this.spinnerElement.hidden = false;
        this.resultRendered = false;
        this.stepElements = new Map();
        this.hideButtons();
    }

    finish(payload) {
        const status = payload.status;
        this.finishedStatus = status;
        this.polling = false;
        this.spinnerElement.hidden = true;
        this.updateOperationAlert(payload);
        this.setSummary(
            status === 'success'
                ? this.label('completed')
                : (status === 'requires_review' ? this.label('requiresReview') : this.label('failed')),
            status === 'success' ? 'success' : (status === 'requires_review' ? 'warning' : 'error'),
        );
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
        this.updateOperationAlert({
            status: 'failed',
            result: {
                issues: [{ message }],
            },
        });
        this.setSummary(message, 'error');
        this.hideButtons();

        if (refreshable) {
            this.refreshButton.hidden = false;
            this.showCloseControls();

            return;
        }

        this.showCloseControls();
    }

    hideButtons() {
        this.okButton.hidden = true;
        this.continueButton.hidden = true;
        this.retryButton.hidden = true;
        this.refreshButton.hidden = true;
        this.cancelButton.hidden = true;
        this.closeButton.hidden = true;
        this.closeIconButton.hidden = true;
    }

    showCloseControls() {
        this.closeButton.hidden = false;
        this.closeIconButton.hidden = false;
    }

    async readJson(response) {
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error(this.label('requestError'));
        }

        return response.json();
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

        return `system.operation.${this.element.action}.${formId}.${backendAction}`;
    }

    storedOperation() {
        try {
            const raw = window.sessionStorage.getItem(this.storageKey());

            const stored = raw ? JSON.parse(raw) : null;

            if (!stored || this.storedOperationExpired(stored) || this.storedOperationTerminal(stored)) {
                this.clearStoredOperation();

                return null;
            }

            return stored;
        } catch {
            return null;
        }
    }

    storeOperation(statusUrl, cursor, continueUrl = null, status = null, progress = null, label = null) {
        try {
            window.sessionStorage.setItem(this.storageKey(), JSON.stringify({
                statusUrl,
                cursor,
                continueUrl,
                status,
                progress,
                label,
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

    storedOperationExpired(stored) {
        const updatedAt = Date.parse(stored.updatedAt || '');

        return Number.isNaN(updatedAt) || Date.now() - updatedAt > this.constructor.storedOperationMaxAgeMs;
    }

    storedOperationTerminal(stored) {
        return ['success', 'failed'].includes(stored.status) || (stored.status === 'requires_review' && !stored.continueUrl);
    }

    showFromAlert = (event) => {
        const storageKey = event.detail?.storageKey || '';

        if (storageKey && storageKey !== this.storageKey()) {
            return;
        }

        this.suppressRunningAlert = true;
        this.open();
    };

    alertClosed = (event) => {
        if (event.detail?.id === this.operationAlertId()) {
            this.suppressRunningAlert = true;
        }
    };

    updateOperationAlert(payload) {
        const status = String(payload.status || 'queued');
        const terminal = ['success', 'requires_review', 'failed'].includes(status);

        if (!terminal && this.suppressRunningAlert) {
            return;
        }

        if (terminal) {
            this.suppressRunningAlert = false;
        }

        const issue = payload.result?.issues?.[0] || null;
        const title = this.operationTitle(payload);
        const message = terminal
            ? (status === 'success'
                ? this.label('completed')
                : (status === 'requires_review'
                    ? this.label('requiresReview')
                    : (issue?.message || issue?.translation_key || issue?.code || this.label('failed'))))
            : this.runningMessage(payload);

        this.dispatchAlert({
            id: this.operationAlertId(),
            title,
            level: status === 'success' ? 'success' : (status === 'requires_review' ? 'warning' : (status === 'failed' ? 'error' : 'info')),
            message,
            mode: terminal && status === 'success' ? 'auto' : 'persistent',
            loading: !terminal,
            actions: [{
                label: this.label('showDetails'),
                event: 'operation-overlay:show',
                detail: {
                    storageKey: this.storageKey(),
                },
            }],
        });
    }

    operationTitle(payload) {
        const label = String(payload.label || '').trim();

        if (label) {
            return label;
        }

        const operation = String(payload.operation || '').trim();

        return operation ? this.actionLabel(operation) : this.label('operation');
    }

    runningMessage(payload) {
        const progress = payload.progress || {};
        const total = Number(progress.total || 0);
        const index = Number(progress.index || 0);

        if (total > 0) {
            return `${this.label('waiting')} [${Math.max(0, index)}/${total}]`;
        }

        return this.label('waiting');
    }

    dispatchAlert(payload) {
        const stack = document.querySelector('[data-controller~="alert-stack"]');

        if (!stack) {
            return;
        }

        stack.dispatchEvent(new CustomEvent('ui-alert:received', {
            bubbles: true,
            detail: payload,
        }));
    }

    operationAlertId() {
        return `operation:${this.storageKey()}`;
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

    get closeIconButton() {
        return this.rootElement.querySelector('[data-operation-overlay-close-icon]');
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

    entryKey(entry) {
        return `${entry.index || 0}:${entry.total || 0}:${entry.name || ''}`;
    }

    actionLabel(name) {
        const labels = this.actionLabels();

        if (labels[name]) {
            return labels[name];
        }

        return String(name || this.label('entry'))
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, (character) => character.toUpperCase());
    }

    actionLabels() {
        if (this.cachedActionLabels) {
            return this.cachedActionLabels;
        }

        try {
            this.cachedActionLabels = JSON.parse(this.rootElement.dataset.actionLabels || '{}');
        } catch {
            this.cachedActionLabels = {};
        }

        return this.cachedActionLabels;
    }

    scrollLogToEnd() {
        const target = this.logScrollElement || this.listElement;
        target.scrollTop = target.scrollHeight;
        window.requestAnimationFrame(() => {
            target.scrollTop = target.scrollHeight;
        });
    }

    get logScrollElement() {
        return this.rootElement.querySelector('[data-operation-overlay-scroll]');
    }

    setSummary(message, state = 'neutral') {
        this.summaryElement.textContent = message;
        this.summaryElement.dataset.operationState = state;
    }
}
