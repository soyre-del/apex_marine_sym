const pendingPage = document.querySelector('.pending-page');

if (pendingPage) {
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
        button.addEventListener('click', () => {
            const requestId = button.dataset.request;
            const template = document.getElementById(`engineers-for-${requestId}`);
            if (!template) {
                return;
            }

            document.getElementById('modalVesselName').textContent = button.dataset.vessel ?? '';
            document.getElementById('modalReqSpecialty').textContent = button.dataset.service ?? '';
            document.getElementById('matchingEngineers').replaceChildren(template.content.cloneNode(true));
            openModal(document.getElementById('assignModal'), button);
        });
    });

    document.querySelectorAll('[data-pending-task-action]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingTaskForm = button.closest('form');
            const terminating = button.dataset.pendingTaskAction === 'terminate';
            const vesselName = button.dataset.vessel ?? 'this vessel';
            const title = document.getElementById('taskActionTitle');
            const message = document.getElementById('taskActionMessage');
            const confirmButton = document.getElementById('confirmTaskActionBtn');
            const modal = document.getElementById('taskActionContent');

            title.textContent = terminating ? 'Terminate Task' : 'Resolve Task';
            message.textContent = terminating
                ? `Are you sure you want to terminate the request for ${vesselName}?`
                : `Confirm that the dispatch for ${vesselName} has been resolved out-of-band.`;
            confirmButton.textContent = terminating ? 'Terminate' : 'Resolve';
            confirmButton.classList.toggle('pending-button-danger', terminating);
            confirmButton.classList.toggle('pending-button-success', !terminating);
            modal.classList.toggle('is-danger', terminating);
            modal.classList.toggle('is-success', !terminating);
            openModal(document.getElementById('taskActionModal'), button);
        });
    });

    document.getElementById('confirmTaskActionBtn')?.addEventListener('click', () => {
        pendingTaskForm?.requestSubmit();
    });

    document.querySelectorAll('[data-pending-close]').forEach((button) => {
        button.addEventListener('click', closeModal);
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
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}
