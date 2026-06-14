import { selectorListMatches } from './fake_dom_selectors.mjs';

export class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    removeEventListener(type, listener) {
        this.listeners.set(type, (this.listeners.get(type) || []).filter((entry) => entry !== listener));
    }

    dispatchEvent(event) {
        event.target ??= this;
        event.currentTarget = this;

        for (const listener of this.listeners.get(event.type) || []) {
            listener.call(this, event);
        }

        if (event.bubbles && this.parentElement) {
            this.parentElement.dispatchEvent(event);
        }

        return !event.defaultPrevented;
    }
}

export class FakeClassList {
    constructor(owner) {
        this.owner = owner;
        this.names = new Set();
    }

    add(...names) {
        for (const name of names) {
            this.names.add(name);
        }
        this.sync();
    }

    remove(...names) {
        for (const name of names) {
            this.names.delete(name);
        }
        this.sync();
    }

    contains(name) {
        return this.names.has(name);
    }

    [Symbol.iterator]() {
        return this.names[Symbol.iterator]();
    }

    sync() {
        this.owner._className = [...this.names].join(' ');
    }
}

export class FakeElement extends FakeEventTarget {
    constructor(tagName = 'div') {
        super();
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.dataset = {};
        this.style = {};
        this.attributes = new Map();
        this.classList = new FakeClassList(this);
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.textContent = '';
        this.value = '';
        this.type = '';
        this.name = '';
        this.id = '';
        this.method = 'get';
        this.parentElement = null;
        this.isConnected = true;
        this.focused = false;
    }

    get className() {
        return this._className || '';
    }

    set className(value) {
        this._className = String(value || '');
        this.classList.names = new Set(this._className.split(/\s+/).filter(Boolean));
    }

    append(...children) {
        for (const child of children.flat()) {
            child.parentElement = this;
            this.children.push(child);
        }
    }

    replaceChildren(...children) {
        for (const child of this.children) {
            child.parentElement = null;
        }
        this.children = [];
        this.append(...children);
    }

    remove() {
        this.isConnected = false;
        if (!this.parentElement) {
            return;
        }
        this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
        this.parentElement = null;
    }

    contains(element) {
        if (element === this) {
            return true;
        }

        return this.children.some((child) => child.contains?.(element));
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));

        if (name === 'id') {
            this.id = String(value);
        }

        if (name === 'class') {
            this.className = String(value);
        }

        if (name.startsWith('data-')) {
            this.dataset[dataName(name)] = String(value);
        }
    }

    getAttribute(name) {
        if (name === 'id') {
            return this.id || null;
        }

        if (name === 'class') {
            return this.className || null;
        }

        return this.attributes.get(name) ?? null;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    querySelectorAll(selector) {
        return this.descendants().filter((element) => selectorListMatches(element, selector));
    }

    closest(selector) {
        let current = this;

        while (current) {
            if (selectorListMatches(current, selector)) {
                return current;
            }
            current = current.parentElement;
        }

        return null;
    }

    focus() {
        this.focused = true;
        globalThis.document.activeElement = this;
    }

    select() {
        this.selected = true;
    }

    setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    }

    descendants() {
        return this.children.flatMap((child) => [child, ...child.descendants()]);
    }
}

export class FakeInputElement extends FakeElement {
    constructor(type = 'text') {
        super('input');
        this.type = type;
        this.selectionStart = null;
        this.selectionEnd = null;
    }
}

export class FakeSelectElement extends FakeElement {
    constructor() {
        super('select');
    }
}

export class FakeTextAreaElement extends FakeElement {
    constructor() {
        super('textarea');
    }
}

export class FakeDialogElement extends FakeElement {
    constructor() {
        super('dialog');
        this.open = false;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
    }
}

export class FakeFormElement extends FakeElement {
    constructor() {
        super('form');
        this.submitted = false;
        this.submitter = null;
    }

    requestSubmit(submitter = undefined) {
        this.submitted = true;
        this.submitter = submitter;
    }
}

export class FakeDocument extends FakeElement {
    constructor() {
        super('#document');
        this.body = new FakeElement('body');
        this.append(this.body);
        this.activeElement = null;
        this.hidden = false;
        this.created = [];
    }

    createElement(tagName) {
        const normalized = tagName.toLowerCase();
        const element = normalized === 'input'
            ? new FakeInputElement()
            : normalized === 'textarea'
                ? new FakeTextAreaElement()
                : normalized === 'select'
                    ? new FakeSelectElement()
                    : normalized === 'dialog'
                        ? new FakeDialogElement()
                        : normalized === 'form'
                            ? new FakeFormElement()
                            : new FakeElement(normalized);
        this.created.push(element);

        return element;
    }

    execCommand(command) {
        this.lastCommand = command;

        return true;
    }
}

function dataName(attribute) {
    return attribute.slice(5).replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
}
