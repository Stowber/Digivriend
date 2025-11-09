(function () {
  const form = document.querySelector('[data-customer-profile-form]');
  if (!form) {
    return;
  }

  const fields = form.querySelectorAll('[data-customer-editable]');
  const saveButton = form.querySelector('[data-customer-edit-save]');
  const cancelEditButton = form.querySelector('[data-customer-edit-cancel]');
  const actionRow = form.querySelector('[data-customer-edit-actions]');
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

    if (actionRow) {
      actionRow.hidden = !enable;
      saveButton.hidden = !enable;
    }

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

(function () {
  const companyModal = document.getElementById('customer-company-modal');
  if (!companyModal) {
    return;
  }

  const toggleButton = companyModal.querySelector('[data-company-address-toggle]');
  const addressContainer = companyModal.querySelector('[data-company-address-fields]');
  const addressInputs = addressContainer ? addressContainer.querySelectorAll('input') : [];

  if (!toggleButton || !addressContainer) {
    return;
  }

  const labelAdd = toggleButton.getAttribute('data-label-add') || toggleButton.textContent || '';
  const labelHide = toggleButton.getAttribute('data-label-hide') || toggleButton.textContent || '';

  function setExpanded(expanded) {
    toggleButton.setAttribute('data-address-expanded', expanded ? 'true' : 'false');
    toggleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggleButton.textContent = expanded ? labelHide : labelAdd;

    if (expanded) {
      addressContainer.hidden = false;
      addressContainer.classList.add('is-visible');
    } else {
      addressContainer.hidden = true;
      addressContainer.classList.remove('is-visible');
    }

    addressInputs.forEach((input) => {
      if (expanded) {
        input.removeAttribute('disabled');
      } else {
        input.setAttribute('disabled', 'disabled');
      }
    });
  }

  function applyDefaultsIfNeeded() {
    const defaults = {
      address: addressContainer.getAttribute('data-default-address') || '',
      postal: addressContainer.getAttribute('data-default-postal') || '',
      city: addressContainer.getAttribute('data-default-city') || '',
    };

    addressInputs.forEach((input) => {
      if (input.value.trim() === '') {
        if (input.name === 'company_address') {
          input.value = defaults.address;
        } else if (input.name === 'company_postal_code') {
          input.value = defaults.postal;
        } else if (input.name === 'company_city') {
          input.value = defaults.city;
        }
      }
    });
  }

  const initialExpanded = toggleButton.getAttribute('data-address-expanded') === 'true';
  setExpanded(initialExpanded);

  toggleButton.addEventListener('click', () => {
    const isExpanded = toggleButton.getAttribute('data-address-expanded') === 'true';
    if (!isExpanded) {
      applyDefaultsIfNeeded();
    }
    setExpanded(!isExpanded);
    if (!isExpanded) {
      const firstInput = addressInputs[0];
      if (firstInput) {
        firstInput.focus({ preventScroll: false });
      }
    }
  });
})();