function initEngineerOperations() {
    const engineerPage = document.querySelector('.engineer-operations-page');
    if (!engineerPage || engineerPage.dataset.engineerInitialized === 'true') {
        return;
    }
    engineerPage.dataset.engineerInitialized = 'true';

    const taskModal = engineerPage.querySelector('[data-engineer-modal]');
    const editModal = engineerPage.querySelector('#editOwnUpdateModal');
    let activeModal = null;
    let lastFocusedElement = null;

    const openModal = (modal, trigger) => {
        if (!modal) {
            return;
        }

        activeModal = modal;
        lastFocusedElement = trigger instanceof HTMLElement ? trigger : null;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('engineer-modal-open');
        modal.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
    };

    const closeModal = (modal) => {
        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (activeModal === modal) {
            activeModal = null;
            document.body.classList.remove('engineer-modal-open');
            lastFocusedElement?.focus();
        }
    };

    engineerPage.querySelectorAll('[data-engineer-open-log]').forEach((button) => {
        button.addEventListener('click', () => {
            const requestIdInput = taskModal?.querySelector('[data-engineer-request-id]');
            const vesselName = taskModal?.querySelector('[data-engineer-vessel]');
            const statusSelect = taskModal?.querySelector('#engineer-status');

            if (requestIdInput) requestIdInput.value = button.dataset.requestId ?? '';
            if (vesselName) vesselName.textContent = button.dataset.vessel ?? '';
            if (statusSelect) statusSelect.value = button.dataset.status ?? 'deployed';
            openModal(taskModal, button);
        });
    });

    engineerPage.querySelectorAll('[data-edit-my-log]').forEach((button) => {
        button.addEventListener('click', () => {
            const updateId = editModal?.querySelector('#edit-log-update-id');
            const vesselName = editModal?.querySelector('#edit-log-vessel');
            const milestone = editModal?.querySelector('#edit-log-milestone');
            const details = editModal?.querySelector('#edit-log-details');

            if (updateId) updateId.value = button.dataset.updateId ?? '';
            if (vesselName) vesselName.textContent = button.dataset.vessel ?? '';
            if (milestone) milestone.value = button.dataset.milestone ?? '';
            if (details) details.value = button.dataset.details ?? '';
            openModal(editModal, button);
        });
    });

    engineerPage.querySelectorAll('[data-engineer-close], [data-close-edit-modal]').forEach((button) => {
        button.addEventListener('click', () => closeModal(button.closest('.engineer-modal')));
    });

    engineerPage.querySelectorAll('.engineer-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    engineerPage.querySelectorAll('[data-engineer-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.engineer-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeModal) {
            closeModal(activeModal);
        }
    });

    const dateElement = engineerPage.querySelector('#eng-date');
    if (dateElement) {
        dateElement.textContent = new Intl.DateTimeFormat(undefined, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        }).format(new Date());
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEngineerOperations);
} else {
    initEngineerOperations();
}
document.addEventListener('turbo:load', initEngineerOperations);
document.addEventListener('turbo:render', initEngineerOperations);
