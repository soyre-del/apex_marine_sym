function initEngineerOperations() {
    const engineerPage = document.querySelector('.engineer-operations-page');
    if (!engineerPage) {
        return;
    }

    const modal = engineerPage.querySelector('[data-engineer-modal]');
    const editModal = document.getElementById('editOwnUpdateModal');
    const deleteModal = document.getElementById('deleteOwnUpdateModal');

    // 1. New Operational Log Modal
    if (modal) {
        const requestIdInput = modal.querySelector('[data-engineer-request-id]');
        const vesselName = modal.querySelector('[data-engineer-vessel]');
        const statusSelect = modal.querySelector('#engineer-status');

        const openModal = (button) => {
            if (requestIdInput) requestIdInput.value = button.dataset.requestId ?? '';
            if (vesselName) vesselName.textContent = button.dataset.vessel ?? '';
            if (statusSelect) statusSelect.value = button.dataset.status ?? 'deployed';
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('engineer-modal-open');
        };

        const closeModal = () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('engineer-modal-open');
        };

        engineerPage.querySelectorAll('[data-engineer-open-log]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                openModal(button);
            };
        });

        engineerPage.querySelectorAll('[data-engineer-close]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                closeModal();
            };
        });

        modal.onclick = (event) => {
            if (event.target === modal) closeModal();
        };
    }

    // 2. Edit Own Log Modal
    if (editModal) {
        const editId = document.getElementById('edit-log-update-id');
        const editVessel = document.getElementById('edit-log-vessel');
        const editMilestone = document.getElementById('edit-log-milestone');
        const editDetails = document.getElementById('edit-log-details');

        engineerPage.querySelectorAll('[data-edit-my-log]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                if (editId) editId.value = button.dataset.updateId ?? '';
                if (editVessel) editVessel.textContent = button.dataset.vessel ?? '';
                if (editMilestone) editMilestone.value = button.dataset.milestone ?? '';
                if (editDetails) editDetails.value = button.dataset.details ?? '';
                editModal.classList.add('is-open');
                editModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('engineer-modal-open');
            };
        });

        engineerPage.querySelectorAll('[data-close-edit-modal]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                editModal.classList.remove('is-open');
                editModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('engineer-modal-open');
            };
        });

        editModal.onclick = (e) => {
            if (e.target === editModal) {
                editModal.classList.remove('is-open');
                editModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('engineer-modal-open');
            }
        };
    }

    // 3. Delete Own Log Modal
    if (deleteModal) {
        const deleteId = document.getElementById('delete-log-update-id');
        const deleteVessel = document.getElementById('delete-log-vessel');
        const deleteMilestone = document.getElementById('delete-log-milestone');

        engineerPage.querySelectorAll('[data-delete-my-log]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                if (deleteId) deleteId.value = button.dataset.updateId ?? '';
                if (deleteVessel) deleteVessel.textContent = button.dataset.vessel ? ('Vessel: ' + button.dataset.vessel) : '';
                if (deleteMilestone) deleteMilestone.textContent = button.dataset.milestone ?? '';
                deleteModal.classList.add('is-open');
                deleteModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('engineer-modal-open');
            };
        });

        engineerPage.querySelectorAll('[data-close-delete-modal]').forEach((button) => {
            button.onclick = (e) => {
                e.preventDefault();
                deleteModal.classList.remove('is-open');
                deleteModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('engineer-modal-open');
            };
        });

        deleteModal.onclick = (e) => {
            if (e.target === deleteModal) {
                deleteModal.classList.remove('is-open');
                deleteModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('engineer-modal-open');
            }
        };
    }

    // Close alerts
    engineerPage.querySelectorAll('[data-engineer-alert-close]').forEach((button) => {
        button.onclick = () => button.closest('.engineer-alert')?.remove();
    });

    document.onkeydown = (event) => {
        if (event.key === 'Escape') {
            [modal, editModal, deleteModal].forEach((m) => {
                if (m && m.classList.contains('is-open')) {
                    m.classList.remove('is-open');
                    m.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('engineer-modal-open');
                }
            });
        }
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEngineerOperations);
} else {
    initEngineerOperations();
}
document.addEventListener('turbo:load', initEngineerOperations);
document.addEventListener('turbo:render', initEngineerOperations);
