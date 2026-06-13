import { Controller } from '@hotwired/stimulus';
import { createAlertElement } from '../js/alerts/alert_element.js';
import {
    actionDetailFromElement,
    alertId,
    alertIds,
    alertMode,
    payloadFromAlertElement,
    storableAlertPayload,
} from '../js/alerts/alert_payload.js';

export default class extends Controller {
    static targets = ['alert', 'badge', 'list', 'panel', 'toggle'];
    static values = {
        dismissDelay: { type: Number, default: 8000 },
    };

    static memoryAlerts = [];
    static storageKey = 'system.alerts.active';

    connect() {
        this.alerts = new Map();
        this.hydrateStoredAlerts();
        this.hydrateServerAlerts();
        this.renderState();
    }

    alertTargetConnected(alert) {
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
        const existing = this.alerts.get(id);
        const alert = createAlertElement({ ...payload, id }, this.closeLabel);

        if (existing?.element?.isConnected) {
            existing.element.replaceWith(alert);
        } else {
            this.listTarget.append(alert);
        }

        this.registerAlert(alert, false);

        if (store) {
            this.persist();
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

    registerAlert(alert, store = true) {
        if (!alert || alert.dataset.alertRegistered === 'true') {
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
        if (!id || !this.alerts.has(id)) {
            return;
        }

        const entry = this.alerts.get(id);
        entry.element?.remove();
        this.alerts.delete(id);
        document.dispatchEvent(new CustomEvent('ui-alert:closed', {
            detail: { id },
        }));

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

    renderState() {
        const count = this.activeCount;
        this.toggleTarget.hidden = count === 0;
        this.badgeTarget.hidden = count === 0;
        this.badgeTarget.textContent = String(count);

        if (count === 0) {
            this.hidePanel();
        }
    }

    persist() {
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

    get activeCount() {
        return this.alerts.size;
    }

    get closeLabel() {
        return this.element.dataset.alertCloseLabel || 'Close notification';
    }
}
