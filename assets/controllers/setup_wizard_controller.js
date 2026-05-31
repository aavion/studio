import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['driver', 'sqliteFields', 'serverFields', 'prefix'];

    connect() {
        this.updateDatabaseFields();
    }

    driverChanged() {
        this.updateDatabaseFields();
    }

    submitOnChange(event) {
        event.preventDefault();
        this.element.requestSubmit();
    }

    submit(event) {
        this.appendPrefixSeparator();
    }

    updateDatabaseFields() {
        if (!this.hasDriverTarget) {
            return;
        }

        const sqlite = this.driverTarget.value === 'sqlite';

        this.toggleTarget(this.sqliteFieldsTargets, sqlite);
        this.toggleTarget(this.serverFieldsTargets, !sqlite);
    }

    toggleTarget(targets, visible) {
        for (const target of targets) {
            target.hidden = !visible;
            for (const control of target.querySelectorAll('input, select, textarea')) {
                control.disabled = !visible;
            }
        }
    }

    appendPrefixSeparator() {
        if (!this.hasPrefixTarget) {
            return;
        }

        const value = this.prefixTarget.value.trim();

        if (value !== '' && !value.endsWith('_')) {
            this.prefixTarget.value = `${value}_`;
        }
    }

}
