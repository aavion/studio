import { alertId, alertMode, normalizeAlertLevel } from './alert_payload.js';

export function createAlertElement(payload, closeLabel) {
    return updateAlertElement(document.createElement('section'), payload, closeLabel);
}

export function updateAlertElement(alert, payload, closeLabel) {
    const level = normalizeAlertLevel(payload.level || 'info');
    const mode = alertMode(payload);
    const actions = normalizeActions(Array.isArray(payload.actions) ? payload.actions : []);
    alert.className = `system-alert system-alert-${level}`;
    alert.setAttribute('role', ['error', 'exception'].includes(level) ? 'alert' : 'status');
    alert.dataset.alertStackTarget = 'alert';
    alert.dataset.alertId = alertId(payload);
    alert.dataset.alertMode = mode;
    alert.dataset.alertPersistent = mode === 'persistent' || payload.persistent ? 'true' : 'false';
    alert.dataset.alertPayload = JSON.stringify({
        ...payload,
        id: alert.dataset.alertId,
        level,
        mode,
        actions,
    });
    alert.replaceChildren();

    const statusIcon = document.createElement('span');
    statusIcon.className = `system-alert-icon ti ${alertIcon(level)}`;
    statusIcon.setAttribute('aria-hidden', 'true');
    alert.append(statusIcon);

    const content = document.createElement('div');
    content.className = 'system-alert-content';

    const title = String(payload.title || '').trim();
    const message = String(payload.message || '').trim();

    if (payload.loading || title) {
        const header = document.createElement('div');
        header.className = 'system-alert-heading';

        const spinner = document.createElement('span');
        if (payload.loading) {
            spinner.className = 'system-alert-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            header.append(spinner);
        }

        if (title) {
            const titleElement = document.createElement('strong');
            titleElement.className = 'system-alert-title';
            titleElement.textContent = title;
            header.append(titleElement);
        }

        content.append(header);
    }

    if (message) {
        const messageElement = document.createElement('span');
        messageElement.className = 'system-alert-message';
        messageElement.textContent = message;
        content.append(messageElement);
    }

    appendActions(content, actions);
    alert.append(content);
    alert.append(closeButton(closeLabel));

    return alert;
}

function alertIcon(level) {
    const icons = {
        debug: 'ti-bug',
        info: 'ti-info-circle',
        success: 'ti-circle-check',
        warning: 'ti-alert-triangle',
        error: 'ti-alert-circle',
        exception: 'ti-alert-circle',
    };

    return icons[level] || icons.info;
}

function appendActions(content, actions) {
    if (actions.length === 0) {
        return;
    }

    const actionList = document.createElement('div');
    actionList.className = 'system-alert-actions';

    for (const action of actions) {
        actionList.append(actionElement(action));
    }

    content.append(actionList);
}

function actionElement(action) {
    const href = String(action.href || '').trim();
    const element = href ? document.createElement('a') : document.createElement('button');
    element.className = 'system-alert-action';
    element.dataset.action = 'alert-stack#action';
    element.textContent = String(action.label).trim();

    if (href) {
        element.href = href;
        if (action.target) {
            element.target = String(action.target);
            if (element.target === '_blank') {
                element.rel = 'noopener noreferrer';
            }
        }
    } else {
        element.type = 'button';
    }

    if (action.event) {
        element.dataset.alertActionEvent = String(action.event);
    }

    if (action.detail) {
        element.dataset.alertActionDetail = JSON.stringify(action.detail);
    }

    return element;
}

function normalizeActions(actions) {
    return actions.map(normalizeAction).filter(Boolean);
}

function normalizeAction(action) {
    if (!action || typeof action !== 'object') {
        return null;
    }

    const label = String(action.label || '').trim();
    if (!label) {
        return null;
    }

    const href = String(action.href || '').trim();
    if (href) {
        if (!hrefAllowed(href)) {
            return null;
        }

        const normalized = { label, href };
        const target = String(action.target || '').trim();
        if (targetAllowed(target)) {
            normalized.target = target;
        }

        return normalized;
    }

    const event = String(action.event || '').trim();
    if (!event) {
        return null;
    }

    const normalized = { label, event };
    if (action.detail && typeof action.detail === 'object' && !Array.isArray(action.detail)) {
        normalized.detail = action.detail;
    }

    return normalized;
}

function hrefAllowed(href) {
    if (!href || href.includes('\\') || /[\x00-\x1F\x7F]/.test(href)) {
        return false;
    }

    if (href.startsWith('/')) {
        return !href.startsWith('//');
    }

    const lowerHref = href.toLowerCase();
    if ((lowerHref.startsWith('http:') && !lowerHref.startsWith('http://'))
        || (lowerHref.startsWith('https:') && !lowerHref.startsWith('https://'))
    ) {
        return false;
    }

    let parsed;
    try {
        parsed = new URL(href);
    } catch {
        return false;
    }

    return ['http:', 'https:', 'mailto:'].includes(parsed.protocol)
        && (parsed.protocol === 'mailto:' || parsed.hostname.trim() !== '');
}

function targetAllowed(target) {
    return ['_blank', '_self', '_parent', '_top'].includes(target);
}

function closeButton(closeLabel) {
    const button = document.createElement('button');
    button.className = 'system-alert-close';
    button.type = 'button';
    button.dataset.action = 'alert-stack#close';
    button.setAttribute('aria-label', closeLabel);

    const icon = document.createElement('span');
    icon.className = 'ti ti-x';
    icon.setAttribute('aria-hidden', 'true');
    button.append(icon);

    return button;
}
