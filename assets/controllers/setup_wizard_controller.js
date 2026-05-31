import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['driver', 'sqliteFields', 'serverFields', 'prefixField'];

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

    updateDatabaseFields() {
        if (!this.hasDriverTarget) {
            return;
        }

        const sqlite = this.driverTarget.value === 'sqlite';

        this.toggleTarget(this.sqliteFieldsTargets, sqlite);
        this.toggleTarget(this.serverFieldsTargets, !sqlite);
        this.toggleTarget(this.prefixFieldTargets, !sqlite);
    }

    toggleTarget(targets, visible) {
        for (const target of targets) {
            target.hidden = !visible;
            for (const control of target.querySelectorAll('input, select, textarea')) {
                control.disabled = !visible;
            }
        }
    }
}
