export function alertId(payload) {
    const id = String(payload.id || '').trim();

    if (id) {
        return id;
    }

    return `alert:${Date.now()}:${Math.random().toString(16).slice(2)}`;
}

export function alertIds(value) {
    if (Array.isArray(value)) {
        return value.map((id) => String(id || '').trim()).filter(Boolean);
    }

    const id = String(value || '').trim();

    return id ? [id] : [];
}

export function alertMode(payload) {
    const mode = String(payload.mode || '').toLowerCase();

    return ['auto', 'hidden', 'persistent'].includes(mode) ? mode : 'auto';
}

export function normalizeAlertLevel(level) {
    const normalized = String(level).toLowerCase();
    const aliases = {
        danger: 'error',
        error: 'error',
        exception: 'exception',
        notice: 'info',
        success: 'success',
        warn: 'warning',
        warning: 'warning',
        debug: 'debug',
    };

    return aliases[normalized] || 'info';
}

export function storableAlertPayload(payload) {
    return {
        id: payload.id,
        title: payload.title,
        message: payload.message,
        level: normalizeAlertLevel(payload.level || 'info'),
        mode: alertMode(payload),
        persistent: Boolean(payload.persistent),
        loading: Boolean(payload.loading),
        actions: Array.isArray(payload.actions) ? payload.actions : [],
    };
}

export function payloadFromAlertElement(alert) {
    try {
        const payload = JSON.parse(alert.dataset.alertPayload || '{}');

        return {
            ...payload,
            id: alert.dataset.alertId || payload.id,
            mode: alert.dataset.alertMode || payload.mode || 'auto',
        };
    } catch {
        return {
            id: alert.dataset.alertId || '',
            title: alert.querySelector('.system-alert-title')?.textContent || '',
            message: alert.querySelector('.system-alert-message')?.textContent
                || alert.querySelector('.system-alert-content')?.textContent
                || '',
            level: [...alert.classList].find((name) => name.startsWith('system-alert-'))?.replace('system-alert-', '') || 'info',
            mode: alert.dataset.alertMode || 'auto',
            persistent: alert.dataset.alertPersistent === 'true',
            actions: [],
        };
    }
}

export function actionDetailFromElement(action) {
    try {
        return JSON.parse(action.dataset.alertActionDetail || '{}');
    } catch {
        return {};
    }
}
