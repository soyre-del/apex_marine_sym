document.querySelectorAll('[data-billing-alert-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.client-billing-alert')?.remove());
});
