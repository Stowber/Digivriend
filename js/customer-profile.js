(function () {
  const form = document.querySelector('[data-customer-profile-form]');
  if (!form) {
    return;
  }

  const fields = form.querySelectorAll('[data-customer-editable]');
  const saveButton = form.querySelector('[data-customer-edit-save]');
  const cancelEditButton = form.querySelector('[data-customer-edit-cancel]');
  const editTrigger = document.querySelector('[data-customer-edit-trigger]');
  const modal = document.getElementById('customer-edit-modal');
  const confirmButton = modal ? modal.querySelector('[data-customer-edit-confirm]') : null;

  let isEditing = false;

  function setEditingState(enable) {
    isEditing = enable;
    fields.forEach((field) => {
      if (enable) {
        field.removeAttribute('disabled');
      } else {
        field.setAttribute('disabled', 'disabled');
      }
    });

    if (saveButton) {
      saveButton.disabled = !enable;
    }

    if (cancelEditButton) {
      cancelEditButton.hidden = !enable;
      cancelEditButton.disabled = !enable;
    }

    if (editTrigger) {
      editTrigger.disabled = enable;
    }
  }

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-visible');
    if (!document.querySelector('.modal.is-visible')) {
      document.body.classList.remove('modal-open');
    }
  }

  setEditingState(false);

  if (confirmButton) {
    confirmButton.addEventListener('click', () => {
      closeModal();
      setEditingState(true);
      const firstField = fields[0];
      if (firstField) {
        firstField.focus({ preventScroll: false });
      }
    });
  }

  if (cancelEditButton) {
    cancelEditButton.addEventListener('click', (event) => {
      event.preventDefault();
      form.reset();
      setEditingState(false);
      if (editTrigger) {
        editTrigger.focus({ preventScroll: false });
      }
    });
  }

  if (editTrigger) {
    editTrigger.addEventListener('click', () => {
      if (isEditing && modal) {
        // Prevent reopening the modal when editing is already enabled.
        modal.classList.remove('is-visible');
      }
    });
  }
})();