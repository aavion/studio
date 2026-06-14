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
    };

    static memoryAlerts = [];
    static memoryClosedAlerts = [];
    static storageKey = 'system.alerts.active';
    static closedStorageKey = 'system.alerts.closed';

    initialize() {
        this.alerts = new Map();
        this.closedAlertIds = new Set();
    }

    connect() {
        this.ensureAlertState();
        this.hydrateClosedAlerts();
        this.hydrateStoredAlerts();
        this.hydrateServerAlerts();
        this.renderState();
    }

    alertTargetConnected(alert) {
        this.ensureAlertState();
        this.registerAlert(alert, true);
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

    upsertAlert(payload, store = true) {
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

        if (existing?.element?.isConnected && existingSignature === nextSignature) {
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

        document.dispatchEvent(new CustomEvent('ui-alert:shown', {
            detail: storableAlertPayload(normalizedPayload),
        }));

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

        if (!this.removeAlertById(id)) {
            return;
        }

        if (store) {
            this.persist();
        }

        this.renderState();
    }

    hydrateStoredAlerts() {
        for (const payload of this.readStoredAlerts()) {
            this.upsertAlert({ ...payload, mode: 'hidden' }, false);
        }

        this.persist();
    }

    hydrateServerAlerts() {
        for (const alert of this.alertTargets) {
            const payload = payloadFromAlertElement(alert);
            const id = alertId(payload);
            if (this.closedAlertIds.has(id)) {
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
        this.hideTimer = window.setTimeout(() => this.hidePanel(), this.dismissDelayValue);
    }

    showPanel() {
        if (this.activeCount === 0) {
            return;
        }

        this.panelTarget.hidden = false;
    }

    hidePanel() {
        this.panelTarget.hidden = true;
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
            this.panelTarget.hidden = false;

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
            window.sessionStorage.setItem(this.constructor.storageKey, JSON.stringify(payloads));
        } catch {
            this.constructor.memoryAlerts = payloads;
        }
    }

    readStoredAlerts() {
        try {
            const raw = window.sessionStorage.getItem(this.constructor.storageKey);
            const parsed = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.filter((payload) => payload && typeof payload === 'object') : [];
        } catch {
            return this.constructor.memoryAlerts;
        }
    }

    hydrateClosedAlerts() {
        this.closedAlertIds = new Set(this.readClosedAlertIds());
    }

    persistClosedAlerts() {
        const ids = [...this.closedAlertIds].slice(-200);
        this.closedAlertIds = new Set(ids);

        try {
            window.sessionStorage.setItem(this.constructor.closedStorageKey, JSON.stringify(ids));
        } catch {
            this.constructor.memoryClosedAlerts = ids;
        }
    }

    readClosedAlertIds() {
        try {
            const raw = window.sessionStorage.getItem(this.constructor.closedStorageKey);
            const parsed = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed.map((id) => String(id || '').trim()).filter(Boolean) : [];
        } catch {
            return this.constructor.memoryClosedAlerts;
        }
    }

    get activeCount() {
        this.ensureAlertState();

        return this.alerts.size;
    }

    get closeLabel() {
        return this.element.dataset.alertCloseLabel || 'Close notification';
    }

    ensureAlertState() {
        if (!(this.alerts instanceof Map)) {
            this.alerts = new Map();
        }
    }

    removeAlertById(id) {
        if (!id || !this.alerts.has(id)) {
            return false;
        }

        const entry = this.alerts.get(id);
        entry.element?.remove();
        this.alerts.delete(id);
        this.closedAlertIds.add(id);
        this.persistClosedAlerts();
        document.dispatchEvent(new CustomEvent('ui-alert:closed', {
            detail: { id },
        }));

        return true;
    }
}
