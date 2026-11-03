const engineerPage = document.querySelector('.engineer-operations-page');

if (engineerPage) {
    const modal = engineerPage.querySelector('[data-engineer-modal]');
    const requestIdInput = modal.querySelector('[data-engineer-request-id]');
    const vesselName = modal.querySelector('[data-engineer-vessel]');
    const statusSelect = modal.querySelector('#engineer-status');
    let lastFocusedElement = null;

    const openModal = (button) => {
        lastFocusedElement = button;
        requestIdInput.value = button.dataset.requestId ?? '';
        vesselName.textContent = button.dataset.vessel ?? '';
        statusSelect.value = button.dataset.status ?? 'deployed';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('engineer-modal-open');
        modal.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
    };

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('engineer-modal-open');
        lastFocusedElement?.focus();
    };

    engineerPage.querySelectorAll('[data-engineer-open-log]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });

    engineerPage.querySelectorAll('[data-engineer-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    engineerPage.querySelectorAll('[data-engineer-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.engineer-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
}
