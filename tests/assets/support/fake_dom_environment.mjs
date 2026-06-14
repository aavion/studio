import {
    FakeDialogElement,
    FakeDocument,
    FakeElement,
    FakeEventTarget,
    FakeInputElement,
    FakeSelectElement,
    FakeTextAreaElement,
} from './fake_dom_elements.mjs';

export function installDom({ origin = 'http://127.0.0.1:8000', pathname = '/admin' } = {}) {
    const document = new FakeDocument();
    const sessionStorage = createStorage();
    const window = new FakeEventTarget();
    window.location = { origin, pathname };
    window.sessionStorage = sessionStorage;
    window.setTimeout = setTimeout;
    window.clearTimeout = clearTimeout;
    window.requestAnimationFrame = (callback) => setTimeout(callback, 0);
    window.cancelAnimationFrame = clearTimeout;

    globalThis.document = document;
    globalThis.window = window;
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {},
    });
    globalThis.CustomEvent = class CustomEvent {
        constructor(type, options = {}) {
            this.type = type;
            this.bubbles = Boolean(options.bubbles);
            this.detail = options.detail;
            this.defaultPrevented = false;
            this.target = null;
            this.currentTarget = null;
        }

        preventDefault() {
            this.defaultPrevented = true;
        }
    };
    globalThis.Element = FakeElement;
    globalThis.HTMLInputElement = FakeInputElement;
    globalThis.HTMLSelectElement = FakeSelectElement;
    globalThis.HTMLTextAreaElement = FakeTextAreaElement;
    globalThis.HTMLDialogElement = FakeDialogElement;
    globalThis.CSS = { escape: (value) => String(value).replace(/"/g, '\\"') };

    return { document, sessionStorage, window };
}

export function event({ target = null, currentTarget = target, params = {}, submitter = undefined } = {}) {
    return {
        target,
        currentTarget,
        params,
        submitter,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

export function createStorage() {
    const entries = new Map();

    return {
        getItem: (key) => entries.get(key) ?? null,
        setItem: (key, value) => entries.set(key, String(value)),
        removeItem: (key) => entries.delete(key),
        clear: () => entries.clear(),
        entries,
    };
}
