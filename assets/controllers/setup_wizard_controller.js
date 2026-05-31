import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['driver', 'sqliteFields', 'serverFields', 'prefix', 'username', 'email', 'password', 'passwordMeter', 'passwordRule'];

    connect() {
        this.updateDatabaseFields();
        this.updatePasswordMeter();
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

    updatePasswordMeter() {
        if (!this.hasPasswordTarget || !this.hasPasswordMeterTarget) {
            return;
        }

        const password = this.passwordTarget.value;
        const checks = this.passwordChecks(password);
        const passed = checks.filter((check) => check.passed).length;
        const strength = password === '' ? 0 : Math.min(4, passed);

        this.passwordMeterTarget.value = strength;
        this.passwordMeterTarget.dataset.strength = String(strength);

        for (const target of this.passwordRuleTargets) {
            const rule = target.dataset.passwordRule;
            const check = checks.find((item) => item.rule === rule);

            target.classList.toggle('is-ok', Boolean(check?.passed));
        }
    }

    passwordChecks(password) {
        const username = this.hasUsernameTarget ? this.usernameTarget.value.trim().toLowerCase() : '';
        const email = this.hasEmailTarget ? this.emailTarget.value.trim().toLowerCase() : '';
        const emailLocalPart = email.split('@')[0] || '';
        const loweredPassword = password.toLowerCase();
        const classCount = [
            /[a-z]/.test(password),
            /[A-Z]/.test(password),
            /[0-9]/.test(password),
            /[^a-zA-Z0-9]/.test(password),
        ].filter(Boolean).length;
        const personalIdentifiers = [username, emailLocalPart].filter((value) => value.length >= 3);

        return [
            { rule: 'length', passed: password.length >= 8 },
            { rule: 'classes', passed: classCount >= 3 },
            { rule: 'repeated', passed: !/(.)\1{3,}/u.test(password) },
            { rule: 'personal', passed: !personalIdentifiers.some((value) => loweredPassword.includes(value)) },
        ];
    }
}
