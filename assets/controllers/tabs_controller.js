import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = {
        selected: String,
    };

    connect() {
        const selected = this.selectedValue || this.tabTargets.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.tabsId;
        this.selectedValue = selected || this.tabTargets[0]?.dataset.tabsId || '';
    }

    select(event) {
        const id = event.params.id || event.currentTarget?.dataset.tabsId || '';

        if (id) {
            this.selectedValue = id;
        }
    }

    selectedValueChanged() {
        this.apply();
    }

    apply() {
        for (const tab of this.tabTargets) {
            const active = tab.dataset.tabsId === this.selectedValue;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        }

        for (const panel of this.panelTargets) {
            panel.hidden = panel.dataset.tabsId !== this.selectedValue;
        }
    }
}
