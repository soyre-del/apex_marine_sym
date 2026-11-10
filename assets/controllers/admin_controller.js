function initAdminPage() {
    const adminPage = document.querySelector('.admin-page');
    if (!adminPage || adminPage.dataset.adminModalsInitialized === 'true') {
        return;
    }
    adminPage.dataset.adminModalsInitialized = 'true';

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
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = button.dataset.modalOpen;
            const targetModal = document.getElementById(targetId);
            if (targetModal) {
                openModal(targetModal, button);
            }
        });
    });

    document.querySelectorAll('[data-modal-dismiss]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal();
        });
    });

    document.querySelectorAll('.admin-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.querySelectorAll('[data-edit-engineer]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const editId = document.getElementById('edit_user_id');
            const editName = document.getElementById('edit_eng_name');
            const editEmail = document.getElementById('edit_eng_email');
            const editSpecialty = document.getElementById('edit_eng_specialty');
            const editStatus = document.getElementById('edit_eng_status');

            if (editId) editId.value = button.dataset.id ?? '';
            if (editName) editName.value = button.dataset.name ?? '';
            if (editEmail) editEmail.value = button.dataset.email ?? '';
            if (editSpecialty) editSpecialty.value = button.dataset.specialty ?? '';
            if (editStatus) editStatus.value = button.dataset.status ?? 'available';

            openModal(document.getElementById('editEngineerModal'), button);
        });
    });

    document.querySelectorAll('[data-delete-engineer]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const delId = document.getElementById('delete_user_id');
            const delDisplay = document.getElementById('delete_eng_name_display');

            if (delId) delId.value = button.dataset.id ?? '';
            if (delDisplay) delDisplay.textContent = button.dataset.name ?? '';

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
        button.addEventListener('click', () => button.closest('.admin-alert, .adm-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeModal) {
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminPage);
} else {
    initAdminPage();
}
document.addEventListener('turbo:load', initAdminPage);
document.addEventListener('turbo:render', initAdminPage);
