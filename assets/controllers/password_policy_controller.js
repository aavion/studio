import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['password', 'confirm', 'username', 'email', 'meter', 'rule', 'match'];
    static values = {
        email: String,
        mismatchMessage: String,
        username: String,
    };

    connect() {
        this.update();
    }

    update() {
        this.updateMeter();
        this.updateConfirmation();
    }

    updateMeter() {
        if (!this.hasPasswordTarget || !this.hasMeterTarget) {
            return;
        }

        const password = this.passwordTarget.value;
        const checks = this.passwordChecks(password);
        const passed = checks.filter((check) => check.passed).length;
        const allPassed = checks.every((check) => check.passed);
        const fullStrength = allPassed && password.length > 10 && this.characterClassCount(password) === 4;
        const strength = password === '' ? 0 : Math.min(5, passed + (fullStrength ? 1 : 0));

        this.meterTarget.value = strength;
        this.meterTarget.dataset.strength = String(strength);
        this.meterTarget.classList.toggle('is-valid', allPassed);
        this.meterTarget.classList.toggle('is-full-strength', fullStrength);

        for (const target of this.ruleTargets) {
            const rule = target.dataset.passwordRule;
            const check = checks.find((item) => item.rule === rule);

            target.classList.toggle('is-ok', Boolean(check?.passed));
        }
    }

    updateConfirmation() {
        if (!this.hasPasswordTarget || !this.hasConfirmTarget) {
            return;
        }

        const password = this.passwordTarget.value;
        const confirmation = this.confirmTarget.value;
        const mismatch = confirmation !== '' && password !== confirmation;

        this.confirmTarget.setCustomValidity(mismatch ? this.mismatchMessageValue : '');

        if (this.hasMatchTarget) {
            this.matchTarget.hidden = !mismatch;
        }
    }

    passwordChecks(password) {
        const username = this.username().toLowerCase();
        const emailLocalPart = this.email().toLowerCase().split('@')[0] || '';
        const loweredPassword = password.toLowerCase();
        const personalIdentifiers = [username, emailLocalPart].filter((value) => value.length >= 3);

        return [
            { rule: 'length', passed: password.length >= 8 },
            { rule: 'classes', passed: this.characterClassCount(password) >= 3 },
            { rule: 'repeated', passed: !/(.)\1{3,}/u.test(password) },
            { rule: 'personal', passed: !personalIdentifiers.some((value) => loweredPassword.includes(value)) },
        ];
    }

    characterClassCount(password) {
        return [
            /[a-z]/.test(password),
            /[A-Z]/.test(password),
            /[0-9]/.test(password),
            /[^a-zA-Z0-9]/.test(password),
        ].filter(Boolean).length;
    }

    username() {
        if (this.hasUsernameTarget) {
            return this.usernameTarget.value.trim();
        }

        return this.usernameValue.trim();
    }

    email() {
        if (this.hasEmailTarget) {
            return this.emailTarget.value.trim();
        }

        return this.emailValue.trim();
    }
}
