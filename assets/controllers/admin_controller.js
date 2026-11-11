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
            const editFirstName = document.getElementById('edit_eng_first_name');
            const editMiddleName = document.getElementById('edit_eng_middle_name');
            const editSurname = document.getElementById('edit_eng_surname');
            const editEmail = document.getElementById('edit_eng_email');
            const editSpecialty = document.getElementById('edit_eng_specialty');
            const editStatus = document.getElementById('edit_eng_status');

            if (editId) editId.value = button.dataset.id ?? '';
            if (editFirstName) editFirstName.value = button.dataset.firstName ?? '';
            if (editMiddleName) editMiddleName.value = button.dataset.middleName ?? '';
            if (editSurname) editSurname.value = button.dataset.surname ?? '';
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

    document.querySelectorAll('[data-adm-password-toggle]').forEach((btn) => {
        if (btn.dataset.toggleBound === 'true') {
            return;
        }
        btn.dataset.toggleBound = 'true';

        btn.addEventListener('click', () => {
            const wrap = btn.closest('.adm-password-wrap');
            const input = wrap?.querySelector('input');
            if (!input) return;

            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');

            const eyeIcon = btn.querySelector('.icon-eye');
            const eyeOffIcon = btn.querySelector('.icon-eye-off');
            const textSpan = btn.querySelector('.adm-password-toggle-text');

            if (eyeIcon) eyeIcon.hidden = isHidden;
            if (eyeOffIcon) eyeOffIcon.hidden = !isHidden;
            if (textSpan) textSpan.textContent = isHidden ? 'Hide' : 'Show';
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminPage);
} else {
    initAdminPage();
}
document.addEventListener('turbo:load', initAdminPage);
document.addEventListener('turbo:render', initAdminPage);
