import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dialog'];

    open(event) {
        event.preventDefault();

        const dialog = this.dialog(event.params.id);

        if (!dialog || dialog.open) {
            return;
        }

        dialog.showModal();
    }

    close(event) {
        event.preventDefault();

        const dialog = this.dialog(event.params.id) || event.target.closest('dialog');

        if (dialog?.open) {
            dialog.close();
        }
    }

    closeOnBackdrop(event) {
        if (event.target === event.currentTarget && event.currentTarget instanceof HTMLDialogElement) {
            event.currentTarget.close();
        }
    }

    dialog(id) {
        if (id) {
            const scoped = this.element.querySelector(`#${CSS.escape(id)}`);

            return scoped instanceof HTMLDialogElement ? scoped : null;
        }

        return this.hasDialogTarget ? this.dialogTarget : null;
    }
}
