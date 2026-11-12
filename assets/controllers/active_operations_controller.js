function initActiveOperations() {
    const modal = document.getElementById('resolveWarningModal');
    if (!modal || modal.dataset.opsInitialized === 'true') {
        return;
    }
    modal.dataset.opsInitialized = 'true';

    let pendingForm = null;
    let confirmedForm = null;
    const proceedButton = document.getElementById('confirmResolveBtn');
    const dismissButtons = modal.querySelectorAll('[data-modal-dismiss]');

    const closeModal = () => {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        pendingForm = null;
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('active-ops-form')) {
            return;
        }

        if (confirmedForm === form) {
            confirmedForm = null;
            return;
        }

        const status = form.querySelector('.active-ops-status')?.value;
        if (status !== 'resolved' && status !== 'cancelled') {
            return;
        }

        event.preventDefault();
        pendingForm = form;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        proceedButton?.focus();
    });

    proceedButton?.addEventListener('click', () => {
        if (!pendingForm) {
            return;
        }

        const form = pendingForm;
        confirmedForm = form;
        closeModal();
        form.requestSubmit();
    });

    dismissButtons.forEach((button) => button.addEventListener('click', closeModal));

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });

    const clearModal = document.getElementById('clearHistoryModal');
    const openClearBtn = document.getElementById('openClearHistoryBtn');
    const confirmClearBtn = document.getElementById('confirmClearHistoryBtn');
    const clearForm = document.getElementById('clearHistoryForm');

    if (clearModal && openClearBtn) {
        const closeClearModal = () => {
            clearModal.classList.remove('show');
            clearModal.setAttribute('aria-hidden', 'true');
        };

        openClearBtn.addEventListener('click', () => {
            clearModal.classList.add('show');
            clearModal.setAttribute('aria-hidden', 'false');
            confirmClearBtn?.focus();
        });

        clearModal.querySelectorAll('[data-modal-dismiss]').forEach((btn) => {
            btn.addEventListener('click', closeClearModal);
        });

        clearModal.addEventListener('click', (event) => {
            if (event.target === clearModal) {
                closeClearModal();
            }
        });

        confirmClearBtn?.addEventListener('click', () => {
            closeClearModal();
            clearForm?.submit();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && clearModal.classList.contains('show')) {
                closeClearModal();
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initActiveOperations);
} else {
    initActiveOperations();
}
document.addEventListener('turbo:load', initActiveOperations);
document.addEventListener('turbo:render', initActiveOperations);
