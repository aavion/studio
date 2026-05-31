import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['driver', 'sqliteFields', 'serverFields'];

    connect() {
        this.updateDatabaseFields();
    }

    driverChanged() {
        this.updateDatabaseFields();
    }

    submitOnChange(event) {
        event.preventDefault();
        this.setAction('set_language');
        this.element.requestSubmit();
    }

    submit() {
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

    setAction(action) {
        let input = this.element.querySelector('input[name="_setup_action"]');

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_setup_action';
            this.element.append(input);
        }

        input.value = action;
    }

}
