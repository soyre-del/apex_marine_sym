const clientsPage = document.querySelector('.clients-page');

if (clientsPage) {
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

    document.querySelectorAll('[data-clients-open]').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(document.getElementById(button.dataset.clientsOpen), button);
        });
    });

    document.querySelectorAll('[data-clients-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.querySelectorAll('.clients-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.querySelectorAll('[data-clients-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_client_id').value = button.dataset.id ?? '';
            document.getElementById('edit_name').value = button.dataset.name ?? '';
            document.getElementById('edit_email').value = button.dataset.email ?? '';
            openModal(document.getElementById('editClientModal'), button);
        });
    });

    document.querySelectorAll('[data-clients-purge]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('purge_user_id').value = button.dataset.id ?? '';
            document.getElementById('purge_client_name_display').textContent = button.dataset.name ?? '';
            openModal(document.getElementById('purgeClientModal'), button);
        });
    });

    document.querySelectorAll('[data-clients-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingToggleForm = button.closest('form');
            const isSuspending = button.dataset.status === '0';
            const clientName = button.dataset.name ?? 'this client';
            const title = document.getElementById('statusToggleTitle');
            const message = document.getElementById('statusToggleMessage');
            const confirmButton = document.getElementById('confirmToggleBtn');

            title.textContent = isSuspending ? 'Suspend Account' : 'Restore Account';
            title.classList.toggle('clients-text-danger', isSuspending);
            title.classList.toggle('clients-text-success', !isSuspending);
            message.textContent = isSuspending
                ? `Suspending ${clientName} revokes their login access immediately. Proceed?`
                : `Reactivate the account for ${clientName}? They will regain system access.`;
            confirmButton.textContent = isSuspending ? 'Suspend' : 'Restore';
            confirmButton.classList.toggle('clients-button-warning', isSuspending);
            confirmButton.classList.toggle('clients-button-success', !isSuspending);
            openModal(document.getElementById('statusToggleModal'), button);
        });
    });

    document.getElementById('confirmToggleBtn')?.addEventListener('click', () => {
        pendingToggleForm?.requestSubmit();
    });

    document.querySelectorAll('[data-clients-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.clients-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}
