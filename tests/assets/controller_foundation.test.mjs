import test from 'node:test';
import assert from 'node:assert/strict';

import { loadStimulusController } from './support/controller_loader.mjs';
import {
    FakeElement,
    FakeFormElement,
    FakeInputElement,
    FakeDialogElement,
    event,
    installDom,
} from './support/fake_dom.mjs';

const { default: ClipboardController } = await loadStimulusController('assets/controllers/clipboard_controller.js');
const { default: CookieConsentController } = await loadStimulusController('assets/controllers/cookie_consent_controller.js');
const { default: DialogController } = await loadStimulusController('assets/controllers/dialog_controller.js');
const { default: DisclosureController } = await loadStimulusController('assets/controllers/disclosure_controller.js');
const { default: FilterFormController } = await loadStimulusController('assets/controllers/filter_form_controller.js');
const { default: TabsController } = await loadStimulusController('assets/controllers/tabs_controller.js');

test('disclosure updates panels and trigger state when the open value changes', () => {
    installDom();

    const controller = new DisclosureController();
    const panel = new FakeElement();
    const trigger = new FakeElement('button');
    controller.panelTargets = [panel];
    controller.triggerTargets = [trigger];
    defineReactiveValue(controller, 'open', false);

    controller.connect();
    assert.equal(panel.hidden, true);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');

    controller.toggle();
    assert.equal(panel.hidden, false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
});

test('tabs select the active tab and hide inactive panels', () => {
    installDom();

    const controller = new TabsController();
    const overviewTab = tab('overview');
    const logsTab = tab('logs');
    logsTab.setAttribute('aria-selected', 'true');
    const overviewPanel = panel('overview');
    const logsPanel = panel('logs');
    controller.tabTargets = [overviewTab, logsTab];
    controller.panelTargets = [overviewPanel, logsPanel];
    defineReactiveValue(controller, 'selected', '');

    controller.connect();
    assert.equal(logsTab.getAttribute('aria-selected'), 'true');
    assert.equal(overviewPanel.hidden, true);
    assert.equal(logsPanel.hidden, false);

    controller.select(event({ currentTarget: overviewTab, params: {} }));
    assert.equal(overviewTab.getAttribute('aria-selected'), 'true');
    assert.equal(logsTab.tabIndex, -1);
    assert.equal(overviewPanel.hidden, false);
    assert.equal(logsPanel.hidden, true);
});

test('dialog controller opens scoped dialogs and closes from child actions', () => {
    installDom();

    const controller = new DialogController();
    const root = new FakeElement();
    const dialog = new FakeDialogElement();
    const closeButton = new FakeElement('button');
    dialog.id = 'confirm';
    dialog.append(closeButton);
    root.append(dialog);
    controller.element = root;
    controller.hasDialogTarget = false;

    const openEvent = event({ params: { id: 'confirm' } });
    controller.open(openEvent);
    assert.equal(openEvent.defaultPrevented, true);
    assert.equal(dialog.open, true);

    controller.close(event({ target: closeButton, params: {} }));
    assert.equal(dialog.open, false);
});

test('clipboard copies explicit text and exposes status feedback', async () => {
    installDom();

    let copied = '';
    globalThis.navigator.clipboard = {
        writeText: async (text) => {
            copied = text;
        },
    };

    const controller = new ClipboardController();
    const status = new FakeElement();
    controller.hasTextValue = true;
    controller.textValue = 'copy-me';
    controller.hasStatusTarget = true;
    controller.statusTarget = status;
    controller.successValue = 'Copied';
    controller.failureValue = 'Failed';

    const copyEvent = event();
    await controller.copy(copyEvent);

    assert.equal(copyEvent.defaultPrevented, true);
    assert.equal(copied, 'copy-me');
    assert.equal(status.textContent, 'Copied');
    assert.equal(status.hidden, false);
});

test('clipboard fallback uses a temporary textarea and removes it again', async () => {
    const { document } = installDom();
    globalThis.navigator.clipboard = null;

    const controller = new ClipboardController();
    await controller.copyText('fallback-copy');

    const textarea = document.created.find((element) => element.tagName === 'TEXTAREA');
    assert.equal(document.lastCommand, 'copy');
    assert.equal(textarea.value, 'fallback-copy');
    assert.equal(textarea.selected, true);
    assert.equal(textarea.isConnected, false);
});

test('filter form submit stores focus state, resets pagination, and submits the form', () => {
    const { sessionStorage } = installDom({ pathname: '/admin/logs' });

    const controller = new FilterFormController();
    const form = new FakeFormElement();
    form.method = 'get';
    form.setAttribute('action', '/admin/logs');
    const page = new FakeInputElement();
    page.name = 'page';
    page.value = '4';
    const search = new FakeInputElement('search');
    search.name = 'q';
    search.selectionStart = 3;
    search.selectionEnd = 7;
    form.append(page, search);
    controller.element = form;
    document.activeElement = search;

    controller.submitNow();

    assert.equal(page.value, '1');
    assert.equal(form.submitted, true);
    assert.match(controller.storageKey, /^system\.filter-form\.focus\./);
    assert.equal(JSON.parse([...sessionStorage.entries.values()][0]).name, 'q');
});

test('filter form restores focus and selection from session storage', () => {
    const { sessionStorage, window } = installDom({ pathname: '/admin/logs' });
    window.requestAnimationFrame = (callback) => callback();

    const controller = new FilterFormController();
    const form = new FakeFormElement();
    form.method = 'get';
    form.setAttribute('action', '/admin/logs');
    const search = new FakeInputElement('search');
    search.name = 'q';
    form.append(search);
    controller.element = form;
    sessionStorage.setItem(controller.storageKey, JSON.stringify({ name: 'q', selectionStart: 2, selectionEnd: 5 }));

    controller.connect();

    assert.equal(search.focused, true);
    assert.equal(search.selectionStart, 2);
    assert.equal(search.selectionEnd, 5);
    assert.equal(sessionStorage.getItem(controller.storageKey), null);
});

test('cookie consent opens from a trigger and can reject optional choices', () => {
    installDom();

    const controller = new CookieConsentController();
    const overlay = new FakeElement();
    const details = new FakeElement();
    const closeButton = new FakeElement('button');
    const option = new FakeInputElement('checkbox');
    option.checked = true;
    overlay.hidden = true;
    details.hidden = true;
    overlay.append(closeButton);
    controller.element = overlay;
    controller.hasDetailsTarget = true;
    controller.detailsTarget = details;
    controller.optionTargets = [option];
    controller.openFromTrigger = null;
    controller.connect();

    const trigger = new FakeElement('button');
    trigger.dataset.cookieConsentOpen = '';
    const click = new CustomEvent('click', { bubbles: true });
    click.target = trigger;
    document.dispatchEvent(click);
    controller.rejectOptional();

    assert.equal(overlay.hidden, false);
    assert.equal(details.hidden, false);
    assert.equal(closeButton.focused, true);
    assert.equal(option.checked, false);
});

function tab(id) {
    const element = new FakeElement('button');
    element.dataset.tabsId = id;

    return element;
}

function panel(id) {
    const element = new FakeElement('section');
    element.dataset.tabsId = id;

    return element;
}

function defineReactiveValue(controller, name, initialValue) {
    let value = initialValue;
    Object.defineProperty(controller, `${name}Value`, {
        get: () => value,
        set: (next) => {
            value = next;
            controller[`${name}ValueChanged`]?.();
        },
    });
}
