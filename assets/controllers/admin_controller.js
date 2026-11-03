const adminPage = document.querySelector('.admin-page');

if (adminPage) {
    let activeModal = null;
    let lastFocusedElement = null;

    const openModal = (modal, trigger = document.activeElement) => {
        if (!modal) {
            return;
        }

        activeModal = modal;
        lastFocusedElement = trigger instanceof HTMLElement ? trigger : null;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        modal.querySelector('input:not([type="hidden"]), select, button')?.focus();
    };

    const closeModal = () => {
        if (!activeModal) {
            return;
        }

        activeModal.classList.remove('is-open');
        activeModal.setAttribute('aria-hidden', 'true');
        activeModal = null;
        lastFocusedElement?.focus();
    };

    document.querySelectorAll('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(document.getElementById(button.dataset.modalOpen), button);
        });
    });

    document.querySelectorAll('[data-modal-dismiss]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.querySelectorAll('.admin-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.querySelectorAll('[data-edit-engineer]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_user_id').value = button.dataset.id ?? '';
            document.getElementById('edit_eng_name').value = button.dataset.name ?? '';
            document.getElementById('edit_eng_email').value = button.dataset.email ?? '';
            document.getElementById('edit_eng_specialty').value = button.dataset.specialty ?? '';
            document.getElementById('edit_eng_status').value = button.dataset.status ?? 'available';
            openModal(document.getElementById('editEngineerModal'), button);
        });
    });

    document.querySelectorAll('[data-delete-engineer]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('delete_user_id').value = button.dataset.id ?? '';
            document.getElementById('delete_eng_name_display').textContent = button.dataset.name ?? '';
            openModal(document.getElementById('deleteEngineerModal'), button);
        });
    });

    document.querySelectorAll('[data-confirm-restore]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Restore this engineer to active duty?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-alert-dismiss]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.admin-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    const failedAction = adminPage.dataset.failedAction;
    if (failedAction === 'add_engineer') {
        openModal(document.getElementById('addEngineerModal'));
    } else if (failedAction === 'edit_engineer') {
        openModal(document.getElementById('editEngineerModal'));
    }
}
