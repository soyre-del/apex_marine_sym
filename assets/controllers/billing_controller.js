const modal = document.querySelector('[data-billing-modal]');

if (modal) {
    const requestSelect = modal.querySelector('#billing-request');
    let previouslyFocused = null;

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('billing-modal-open');
        previouslyFocused?.focus();
    };

    document.querySelectorAll('[data-billing-open]').forEach((button) => {
        button.addEventListener('click', () => {
            previouslyFocused = button;
            if (button.dataset.requestId && requestSelect) {
                requestSelect.value = button.dataset.requestId;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('billing-modal-open');
            modal.querySelector('select, input, button')?.focus();
        });
    });

    modal.querySelectorAll('[data-billing-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
}
