function initBillingPage() {
    const modal = document.querySelector('[data-billing-modal]');
    if (!modal || modal.dataset.billingInitialized === 'true') {
        return;
    }
    modal.dataset.billingInitialized = 'true';

    const requestSelect = modal.querySelector('#billing-request');
    const clientSelect = modal.querySelector('#billing-client');
    const amountInput = modal.querySelector('#billing-amount');
    let previouslyFocused = null;

    const syncClientFromRequest = () => {
        if (!requestSelect || !clientSelect) return;
        const selectedOption = requestSelect.options[requestSelect.selectedIndex];
        if (selectedOption && selectedOption.dataset.clientId) {
            clientSelect.value = selectedOption.dataset.clientId;
        }
    };

    if (requestSelect && !requestSelect.dataset.boundSync) {
        requestSelect.dataset.boundSync = '1';
        requestSelect.addEventListener('change', syncClientFromRequest);
    }

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('billing-modal-open');
        previouslyFocused?.focus();
    };

    document.querySelectorAll('[data-billing-open]').forEach((button) => {
        if (button.dataset.boundOpen === '1') return;
        button.dataset.boundOpen = '1';
        button.addEventListener('click', (e) => {
            e.preventDefault();
            previouslyFocused = button;
            if (button.dataset.requestId && requestSelect) {
                requestSelect.value = button.dataset.requestId;
                syncClientFromRequest();
            } else if (requestSelect && !requestSelect.value && requestSelect.options.length > 1) {
                requestSelect.selectedIndex = 1;
                syncClientFromRequest();
            }
            if (button.dataset.clientId && clientSelect) {
                clientSelect.value = button.dataset.clientId;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('billing-modal-open');
            if (amountInput && requestSelect && requestSelect.value) {
                amountInput.focus();
            } else {
                modal.querySelector('select, input, button')?.focus();
            }
        });
    });

    modal.querySelectorAll('[data-billing-close]').forEach((button) => {
        if (button.dataset.boundClose === '1') return;
        button.dataset.boundClose = '1';
        button.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal();
        });
    });

    modal.querySelectorAll('[data-billing-preset]').forEach((button) => {
        if (button.dataset.boundPreset === '1') return;
        button.dataset.boundPreset = '1';
        button.addEventListener('click', (e) => {
            e.preventDefault();
            if (amountInput) {
                amountInput.value = button.dataset.billingPreset;
                amountInput.focus();
            }
        });
    });

    if (!modal.dataset.boundBackdrop) {
        modal.dataset.boundBackdrop = '1';
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBillingPage);
} else {
    initBillingPage();
}
document.addEventListener('turbo:load', initBillingPage);
document.addEventListener('turbo:render', initBillingPage);
