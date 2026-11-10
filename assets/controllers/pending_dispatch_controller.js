function initPendingDispatch() {
    const pendingPage = document.querySelector('.pending-page');
    if (!pendingPage || pendingPage.dataset.pendingInitialized === 'true') {
        return;
    }
    pendingPage.dataset.pendingInitialized = 'true';

    let activeModal = null;
    let lastFocusedElement = null;
    let pendingTaskForm = null;

    const openModal = (modal, trigger = document.activeElement) => {
        if (!modal) {
            return;
        }

        activeModal = modal;
        lastFocusedElement = trigger instanceof HTMLElement ? trigger : null;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pending-modal-open');
        modal.querySelector('input:not([type="hidden"]), button')?.focus();
    };

    const closeModal = () => {
        if (!activeModal) {
            return;
        }

        activeModal.classList.remove('is-open');
        activeModal.setAttribute('aria-hidden', 'true');
        activeModal = null;
        document.body.classList.remove('pending-modal-open');
        lastFocusedElement?.focus();
    };

    document.querySelectorAll('[data-pending-assignment]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const requestId = button.dataset.request;
            const template = document.getElementById(`engineers-for-${requestId}`);
            if (!template) {
                return;
            }

            const vesselEl = document.getElementById('modalVesselName');
            const specialtyEl = document.getElementById('modalReqSpecialty');
            const matchEl = document.getElementById('matchingEngineers');

            if (vesselEl) vesselEl.textContent = button.dataset.vessel ?? '';
            if (specialtyEl) specialtyEl.textContent = button.dataset.service ?? '';
            if (matchEl) matchEl.replaceChildren(template.content.cloneNode(true));
            openModal(document.getElementById('assignModal'), button);
        });
    });

    document.querySelectorAll('[data-pending-task-action]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            pendingTaskForm = button.closest('form');
            const terminating = button.dataset.pendingTaskAction === 'terminate';
            const vesselName = button.dataset.vessel ?? 'this vessel';
            const title = document.getElementById('taskActionTitle');
            const message = document.getElementById('taskActionMessage');
            const confirmButton = document.getElementById('confirmTaskActionBtn');
            const modal = document.getElementById('taskActionContent');

            if (title) title.textContent = terminating ? 'Terminate Task' : 'Resolve Task';
            if (message) {
                message.textContent = terminating
                    ? `Are you sure you want to terminate the request for ${vesselName}?`
                    : `Confirm that the dispatch for ${vesselName} has been resolved out-of-band.`;
            }
            if (confirmButton) {
                confirmButton.textContent = terminating ? 'Terminate' : 'Resolve';
                confirmButton.classList.toggle('pending-button-danger', terminating);
                confirmButton.classList.toggle('pending-button-success', !terminating);
            }
            if (modal) {
                modal.classList.toggle('is-danger', terminating);
                modal.classList.toggle('is-success', !terminating);
            }
            openModal(document.getElementById('taskActionModal'), button);
        });
    });

    document.getElementById('confirmTaskActionBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        pendingTaskForm?.requestSubmit();
    });

    document.querySelectorAll('[data-pending-close]').forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            closeModal();
        });
    });

    document.querySelectorAll('.pending-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.querySelectorAll('[data-pending-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.pending-alert')?.remove());
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeModal) {
            closeModal();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPendingDispatch);
} else {
    initPendingDispatch();
}
document.addEventListener('turbo:load', initPendingDispatch);
document.addEventListener('turbo:render', initPendingDispatch);
