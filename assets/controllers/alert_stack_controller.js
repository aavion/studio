import { Controller } from '@hotwired/stimulus';
import { createAlertElement, updateAlertElement } from '../js/alerts/alert_element.js';
import {
    actionDetailFromElement,
    alertId,
    alertIds,
    alertMode,
    payloadFromAlertElement,
    storableAlertPayload,
} from '../js/alerts/alert_payload.js';

export default class extends Controller {
    static targets = ['alert', 'badge', 'clearAll', 'empty', 'list', 'panel', 'toggle'];
    static values = {
        dismissDelay: { type: Number, default: 8000 },
        storageScope: { type: String, default: 'public' },
    };

    static memoryAlerts = new Map();
    static memoryClosedAlerts = new Map();
    static storageKey = 'system.alerts.active';
    static closedStorageKey = 'system.alerts.closed';

    initialize() {
        this.alerts = new Map();
        this.closedAlertIds = new Set();
        this.connected = false;
    }

    connect() {
        this.connected = true;
        this.ensureAlertState();
        document.addEventListener('pointerdown', this.hideOnOutsidePointerDown, true);
        document.addEventListener('keydown', this.hideOnEscape);
        this.hydrateClosedAlerts();
        this.hydrateServerAlerts();
        this.hydrateStoredAlerts();
        this.renderState();
    }

    disconnect() {
        this.connected = false;
        document.removeEventListener('pointerdown', this.hideOnOutsidePointerDown, true);
        document.removeEventListener('keydown', this.hideOnEscape);
        window.cancelAnimationFrame(this.panelRevealFrame);
        window.clearTimeout(this.panelHideTimer);
        window.clearTimeout(this.hideTimer);
    }

    alertTargetConnected(alert) {
        this.ensureAlertState();
        this.registerAlert(alert, this.connected);
    }

    toggle(event) {
        event.preventDefault();

        if (this.panelTarget.hidden) {
            this.showPanel();

            return;
        }

        this.hidePanel();
    }

    hide(event) {
        event.preventDefault();
        this.hidePanel();
    }

    close(event) {
        event.preventDefault();
        this.closeAlert(event.currentTarget.closest('[data-alert-stack-target="alert"]'));
    }

    closeAll(event) {
        event.preventDefault();
        this.ensureAlertState();

        const ids = [...this.alerts.keys()];
        for (const id of ids) {
            this.closeAlertById(id, false);
        }

        this.persist();
        this.persistClosedAlerts();
        this.renderState({ keepPanelOpen: true });
    }

    action(event) {
        const action = event.currentTarget;
        const alert = action.closest('[data-alert-stack-target="alert"]');
        const eventName = action.dataset.alertActionEvent || '';

        if (eventName) {
            event.preventDefault();
            document.dispatchEvent(new CustomEvent(eventName, {
                detail: actionDetailFromElement(action),
            }));
        }

        this.closeAlert(alert);
    }

    append(event) {
        this.upsertAlert(event.detail || {}, true);
    }

    upsertAlert(payload, store = true, notify = true) {
        this.ensureAlertState();

        for (const id of alertIds(payload.closes)) {
            this.closeAlertById(id, false);
        }

        if (!String(payload.message || '').trim()) {
            if (store) {
                this.persist();
            }

            return null;
        }

        const id = alertId(payload);
        if (this.closedAlertIds.has(id)) {
            if (!payload.reopen) {
                return null;
            }

            this.closedAlertIds.delete(id);
            this.persistClosedAlerts();
        }

        const existing = this.alerts.get(id);
        const normalizedPayload = { ...payload, id };
        const nextSignature = JSON.stringify(storableAlertPayload(normalizedPayload));
        const existingSignature = existing ? JSON.stringify(storableAlertPayload(existing.payload)) : '';

        const existingConnected = existing?.element?.isConnected === true;

        if (existingConnected && existingSignature === nextSignature) {
            if (alertMode(payload) !== 'hidden') {
                this.showPanel();
            }

            if (alertMode(payload) === 'auto') {
                this.scheduleHide();
            }

            this.renderState();

            return existing.element;
        }

        const alert = existing?.element?.isConnected
            ? updateAlertElement(existing.element, normalizedPayload, this.closeLabel)
            : createAlertElement(normalizedPayload, this.closeLabel);

        if (!existing?.element?.isConnected) {
            this.listTarget.append(alert);
        }

        this.registerAlert(alert, false, true);

        if (store) {
            this.persist();
        }

        if (notify && !existingConnected) {
            document.dispatchEvent(new CustomEvent('ui-alert:shown', {
                detail: storableAlertPayload(normalizedPayload),
            }));
        }

        if (alertMode(payload) !== 'hidden') {
            this.showPanel();
        }

        if (alertMode(payload) === 'auto') {
            this.scheduleHide();
        }

        this.renderState();

        return alert;
    }

    registerAlert(alert, store = true, refresh = false) {
        this.ensureAlertState();

        if (!alert || (alert.dataset.alertRegistered === 'true' && !refresh)) {
            return;
        }

        const payload = payloadFromAlertElement(alert);
        const id = alertId(payload);
        alert.dataset.alertId = id;
        alert.dataset.alertRegistered = 'true';
        this.alerts.set(id, {
            id,
            element: alert,
            payload: { ...payload, id },
        });

        if (store) {
            this.persist();
        }

        this.renderState();
    }

    closeAlert(alert, store = true) {
        if (!alert) {
            return;
        }

        this.closeAlertById(alert.dataset.alertId || '', store);
    }

    closeAlertById(id, store = true) {
        this.ensureAlertState();

        if (!this.removeAlertById(id, true)) {
            return;
        }

        if (store) {
            this.persist();
        }

        this.renderState();
    }

    hydrateStoredAlerts() {
        for (const payload of this.readStoredAlerts()) {
            if (this.alerts.has(alertId(payload))) {
                continue;
            }

            this.upsertAlert({ ...payload, mode: 'hidden' }, false, false);
        }

        this.persist();
    }

    hydrateServerAlerts() {
        for (const alert of this.alertTargets) {
            const payload = payloadFromAlertElement(alert);
            const id = alertId(payload);
            if (this.closedAlertIds.has(id)) {
                this.alerts.delete(id);
                alert.remove();

                continue;
            }

            this.registerAlert(alert, false);

            if (alert.dataset.alertMode !== 'hidden') {
                this.showPanel();
            }

            if (alert.dataset.alertMode === 'auto') {
                this.scheduleHide();
            }
        }

        this.persist();
    }

    scheduleHide() {
        window.clearTimeout(this.hideTimer);
        this.hideTimer = window.setTimeout(() => this.dismissAutoAlerts(), this.dismissDelayValue);
    }

    dismissAutoAlerts() {
        this.ensureAlertState();

        let changed = false;

        for (const [id, entry] of this.alerts.entries()) {
            const payload = entry.payload || {};
            if (alertMode(payload) !== 'auto' || payload.persistent) {
                continue;
            }

            changed = this.removeAlertById(id, true, false) || changed;
        }

        if (!changed) {
            this.hidePanel();

            return;
        }

        this.persist();
        this.renderState();
    }

    showPanel() {
        if (this.activeCount === 0) {
            return;
        }

        this.revealPanel();
    }

    revealPanel() {
        window.cancelAnimationFrame(this.panelRevealFrame);
        window.clearTimeout(this.hideTimer);
        window.clearTimeout(this.panelHideTimer);
        this.panelTarget.hidden = false;
        this.panelTarget.classList.remove('is-closing');
        this.panelRevealFrame = window.requestAnimationFrame(() => {
            this.panelRevealFrame = null;
            this.panelTarget.classList.add('is-open');
        });
    }

    hidePanel() {
        if (this.panelTarget.hidden) {
            return;
        }

        window.cancelAnimationFrame(this.panelRevealFrame);
        this.panelRevealFrame = null;
        this.panelTarget.classList.remove('is-open');
        this.panelTarget.classList.add('is-closing');
        window.clearTimeout(this.panelHideTimer);
        this.panelHideTimer = window.setTimeout(() => {
            this.panelTarget.hidden = true;
            this.panelTarget.classList.remove('is-closing');
        }, 180);
    }

    renderState(options = {}) {
        const count = this.activeCount;
        this.toggleTarget.hidden = count === 0;
        this.badgeTarget.hidden = count === 0;
        this.badgeTarget.textContent = String(count);

        if (this.hasClearAllTarget) {
            this.clearAllTarget.hidden = count === 0;
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = count !== 0;
        }

        if (count === 0 && options.keepPanelOpen) {
            this.revealPanel();

            return;
        }

        if (count === 0) {
            this.hidePanel();
        }
    }

    persist() {
        this.ensureAlertState();

        const payloads = [...this.alerts.values()].map((entry) => storableAlertPayload(entry.payload));

        try {
            window.sessionStorage.setItem(this.storageKey, JSON.stringify(payloads));
        } catch {
            this.constructor.memoryAlerts.set(this.storageKey, payloads);
        }
    }

    readStoredAlerts() {
        try {
            const raw = window.sessionStorage.getItem(this.storageKey);
            const parsed = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.filter((payload) => payload && typeof payload === 'object') : [];
        } catch {
            return this.constructor.memoryAlerts.get(this.storageKey) || [];
        }
    }

    hydrateClosedAlerts() {
        this.closedAlertIds = new Set(this.readClosedAlertIds());
    }

    persistClosedAlerts() {
        const ids = [...this.closedAlertIds].slice(-200);
        this.closedAlertIds = new Set(ids);

        try {
            window.sessionStorage.setItem(this.closedStorageKey, JSON.stringify(ids));
        } catch {
            this.constructor.memoryClosedAlerts.set(this.closedStorageKey, ids);
        }
    }

    readClosedAlertIds() {
        try {
            const raw = window.sessionStorage.getItem(this.closedStorageKey);
            const parsed = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.map((id) => String(id || '').trim()).filter(Boolean) : [];
        } catch {
            return this.constructor.memoryClosedAlerts.get(this.closedStorageKey) || [];
        }
    }

    get activeCount() {
        this.ensureAlertState();

        return this.alerts.size;
    }

    get closeLabel() {
        return this.element.dataset.alertCloseLabel || 'Close notification';
    }

    get storageKey() {
        return `${this.constructor.storageKey}.${this.normalizedStorageScope}`;
    }

    get closedStorageKey() {
        return `${this.constructor.closedStorageKey}.${this.normalizedStorageScope}`;
    }

    get normalizedStorageScope() {
        return String(this.storageScopeValue || 'public').replace(/[^a-zA-Z0-9_.:-]/g, '_').slice(0, 120) || 'public';
    }

    ensureAlertState() {
        if (!(this.alerts instanceof Map)) {
            this.alerts = new Map();
        }
    }

    hideOnOutsidePointerDown = (event) => {
        if (this.panelTarget.hidden || this.element.contains(event.target)) {
            return;
        }

        this.hidePanel();
    };

    hideOnEscape = (event) => {
        if (event.key !== 'Escape' || this.panelTarget.hidden) {
            return;
        }

        this.hidePanel();
    };

    removeAlertById(id, rememberClosed = true, dispatchClosedEvent = true) {
        if (!id || !this.alerts.has(id)) {
            return false;
        }

        const entry = this.alerts.get(id);
        entry.element?.remove();
        this.alerts.delete(id);
        if (rememberClosed) {
            this.closedAlertIds.add(id);
            this.persistClosedAlerts();
        }
        if (dispatchClosedEvent) {
            document.dispatchEvent(new CustomEvent('ui-alert:closed', {
                detail: { id },
            }));
        }

        return true;
    }
}
