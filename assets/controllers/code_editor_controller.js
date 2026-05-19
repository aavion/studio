import { Controller } from '@hotwired/stimulus';
import { EditorState } from '@codemirror/state';
import { EditorView, basicSetup } from 'codemirror';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { php } from '@codemirror/lang-php';

const languageExtensions = {
    css,
    html,
    javascript,
    js: javascript,
    jsx: () => javascript({ jsx: true }),
    json,
    markdown,
    md: markdown,
    php,
    ts: () => javascript({ typescript: true }),
    tsx: () => javascript({ jsx: true, typescript: true }),
    typescript: () => javascript({ typescript: true }),
};

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        doc: String,
        language: String,
        lineWrapping: Boolean,
        readOnly: Boolean,
        tabSize: Number,
    };

    connect() {
        this.sourceElement = this.element.matches('textarea') ? this.element : null;
        this.mountElement = this.sourceElement ? document.createElement('div') : this.element;
        const doc = this.initialDocument();

        this.mountElement.classList.add('cm-wrapper');

        if (this.sourceElement) {
            this.sourceElement.hidden = true;
            this.sourceElement.after(this.mountElement);
        } else {
            this.element.textContent = '';
        }

        this.view = new EditorView({
            state: EditorState.create({
                doc,
                extensions: this.extensions(),
            }),
            parent: this.mountElement,
        });
    }

    disconnect() {
        if (this.view) {
            this.view.destroy();
            this.view = null;
        }

        if (this.sourceElement) {
            this.sourceElement.hidden = false;
            this.mountElement.remove();
            this.sourceElement = null;
            this.mountElement = null;
        }
    }

    initialDocument() {
        if (this.hasDocValue) {
            return this.docValue;
        }

        if (this.sourceElement) {
            return this.sourceElement.value;
        }

        return this.element.textContent.trim();
    }

    extensions() {
        const extensions = [
            basicSetup,
            EditorState.tabSize.of(this.hasTabSizeValue ? this.tabSizeValue : 4),
            EditorState.readOnly.of(this.readOnlyValue),
            EditorView.editable.of(!this.readOnlyValue),
            EditorView.updateListener.of((update) => this.syncSource(update)),
        ];

        const language = this.languageExtension();

        if (language) {
            extensions.push(language);
        }

        if (this.lineWrappingValue) {
            extensions.push(EditorView.lineWrapping);
        }

        return extensions;
    }

    languageExtension() {
        if (!this.hasLanguageValue) {
            return null;
        }

        const languageFactory = languageExtensions[this.languageValue.toLowerCase()];

        return languageFactory ? languageFactory() : null;
    }

    syncSource(update) {
        if (!this.sourceElement || !update.docChanged) {
            return;
        }

        this.sourceElement.value = update.state.doc.toString();
        this.sourceElement.dispatchEvent(new Event('input', { bubbles: true }));
        this.sourceElement.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
