import { alertId, alertMode, normalizeAlertLevel } from './alert_payload.js';

export function createAlertElement(payload, closeLabel) {
    const level = normalizeAlertLevel(payload.level || 'info');
    const mode = alertMode(payload);
    const alert = document.createElement('section');
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
    });

    const statusIcon = document.createElement('span');
    statusIcon.className = `system-alert-icon ti ${alertIcon(level)}`;
    statusIcon.setAttribute('aria-hidden', 'true');
    alert.append(statusIcon);

    const content = document.createElement('div');
    content.className = 'system-alert-content';

    if (payload.loading) {
        const spinner = document.createElement('span');
        spinner.className = 'system-alert-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        content.append(spinner);
    }

    content.append(document.createTextNode(String(payload.message || '').trim()));
    appendActions(content, Array.isArray(payload.actions) ? payload.actions : []);
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
    const validActions = actions.filter((action) => action && String(action.label || '').trim());

    if (validActions.length === 0) {
        return;
    }

    const actionList = document.createElement('div');
    actionList.className = 'system-alert-actions';

    for (const action of validActions) {
        actionList.append(actionElement(action));
    }

    content.append(actionList);
}

function actionElement(action) {
    const element = action.href ? document.createElement('a') : document.createElement('button');
    element.className = 'system-alert-action';
    element.dataset.action = 'alert-stack#action';
    element.textContent = String(action.label).trim();

    if (action.href) {
        element.href = String(action.href);
        if (action.target) {
            element.target = String(action.target);
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
