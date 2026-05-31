import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['role', 'groups'];
    static values = {
        options: Object,
    };

    connect() {
        this.refresh();
    }

    refresh() {
        const role = this.roleTarget.value;
        const groups = this.optionsValue[role] || [];

        this.groupsTarget.querySelectorAll('label').forEach((label) => label.remove());

        groups.forEach((group, index) => {
            const optionId = `invite_groups-${index + 1}`;
            const label = document.createElement('label');
            const input = document.createElement('input');
            const text = document.createElement('span');

            label.className = 'studio-backend-checkbox-label';
            label.setAttribute('for', optionId);
            input.id = optionId;
            input.className = 'studio-backend-checkbox';
            input.type = 'checkbox';
            input.name = 'groups[]';
            input.value = group.identifier;
            text.textContent = group.label || group.identifier;
            label.append(input, text);
            this.groupsTarget.append(label);
        });
    }
}
