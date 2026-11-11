const initializedPages = new WeakSet();

function initManageClients() {
    const clientsPage = document.querySelector('.clients-page');
    if (!clientsPage || initializedPages.has(clientsPage)) {
        return;
    }
    initializedPages.add(clientsPage);

    let activeModal = null;
    let lastFocusedElement = null;
    let pendingToggleForm = null;

    const openModal = (modal, trigger = document.activeElement) => {
        if (!modal) {
            return;
        }

        activeModal = modal;
        lastFocusedElement = trigger instanceof HTMLElement ? trigger : null;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('clients-modal-open');
        modal.querySelector('input:not([type="hidden"]), select, button')?.focus();
    };

    const closeModal = () => {
        if (!activeModal) {
            return;
        }

        activeModal.classList.remove('is-open');
        activeModal.setAttribute('aria-hidden', 'true');
        activeModal = null;
        document.body.classList.remove('clients-modal-open');
        lastFocusedElement?.focus();
    };

    clientsPage.querySelectorAll('[data-clients-open]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(document.getElementById(button.dataset.clientsOpen), button);
        });
    });

    clientsPage.querySelectorAll('[data-clients-close]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal();
        });
    });

    clientsPage.querySelectorAll('.clients-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    clientsPage.querySelectorAll('[data-clients-edit]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const editId = document.getElementById('edit_client_id');
            const editUsername = document.getElementById('edit_username_display');
            const editFirstName = document.getElementById('edit_first_name');
            const editMiddleName = document.getElementById('edit_middle_name');
            const editSurname = document.getElementById('edit_surname');
            const editEmail = document.getElementById('edit_email');

            if (editId) editId.value = button.dataset.id ?? '';
            if (editUsername) editUsername.value = button.dataset.username ?? '';
            if (editFirstName) editFirstName.value = button.dataset.firstName ?? '';
            if (editMiddleName) editMiddleName.value = button.dataset.middleName ?? '';
            if (editSurname) editSurname.value = button.dataset.surname ?? '';
            if (editEmail) editEmail.value = button.dataset.email ?? '';

            openModal(document.getElementById('editClientModal'), button);
        });
    });

    clientsPage.querySelectorAll('[data-clients-purge]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const purgeId = document.getElementById('purge_user_id');
            const purgeDisplay = document.getElementById('purge_client_name_display');

            if (purgeId) purgeId.value = button.dataset.id ?? '';
            if (purgeDisplay) purgeDisplay.textContent = button.dataset.name ?? '';

            openModal(document.getElementById('purgeClientModal'), button);
        });
    });

    clientsPage.querySelectorAll('[data-clients-toggle]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            pendingToggleForm = button.closest('form');
            const isSuspending = button.dataset.status === '0';
            const clientName = button.dataset.name ?? 'this client';
            const title = document.getElementById('statusToggleTitle');
            const message = document.getElementById('statusToggleMessage');
            const confirmButton = document.getElementById('confirmToggleBtn');

            if (title) {
                title.textContent = isSuspending ? 'Suspend Account' : 'Restore Account';
                title.classList.toggle('clients-text-danger', isSuspending);
                title.classList.toggle('clients-text-success', !isSuspending);
            }
            if (message) {
                message.textContent = isSuspending
                    ? `Suspending ${clientName} revokes their login access immediately. Proceed?`
                    : `Reactivate the account for ${clientName}? They will regain system access.`;
            }
            if (confirmButton) {
                confirmButton.textContent = isSuspending ? 'Suspend' : 'Restore';
                confirmButton.classList.toggle('clients-button-warning', isSuspending);
                confirmButton.classList.toggle('clients-button-success', !isSuspending);
            }
            openModal(document.getElementById('statusToggleModal'), button);
        });
    });

    clientsPage.querySelector('#confirmToggleBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        pendingToggleForm?.requestSubmit();
    });

    clientsPage.querySelectorAll('[data-clients-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.clients-alert')?.remove());
    });

    clientsPage.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeModal) {
            closeModal();
        }
    });

    clientsPage.querySelectorAll('[data-adm-password-toggle]').forEach((btn) => {
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
    document.addEventListener('DOMContentLoaded', initManageClients);
} else {
    initManageClients();
}
document.addEventListener('turbo:load', initManageClients);
document.addEventListener('turbo:render', initManageClients);
