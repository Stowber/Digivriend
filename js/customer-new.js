document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-customer-form]');
  if (!form) {
    return;
  }

  const typeInput = form.querySelector('[data-customer-type-input]');
  const typeButtons = Array.from(form.querySelectorAll('[data-customer-type-option]'));
  const dialog = document.querySelector('[data-customer-type-dialog]');
  const dialogChoices = dialog ? Array.from(dialog.querySelectorAll('[data-customer-type-choice]')) : [];
  const dialogCloseButtons = dialog ? Array.from(dialog.querySelectorAll('[data-customer-type-close]')) : [];
  const openDialogButton = document.querySelector('[data-customer-type-open]');
  const typeLabel = document.querySelector('[data-customer-type-label]');
  const fullNameInput = form.querySelector('#customerFullName');
  const companyContactInput = form.querySelector('input[name="company_contact_person"]');
  const companyNameInput = form.querySelector('input[name="company_name"]');
  const body = document.body;

  const PRIVATE_LABEL = typeLabel ? typeLabel.getAttribute('data-private-label') || '' : '';
  const BUSINESS_LABEL = typeLabel ? typeLabel.getAttribute('data-business-label') || '' : '';

  const LOCK_CLASS = 'is-dialog-open';

  const normalizeType = (value) => (value === 'business' ? 'business' : 'private');

  const setBodyLock = (locked) => {
    if (locked) {
      body.classList.add(LOCK_CLASS);
      body.style.overflow = 'hidden';
    } else {
      body.classList.remove(LOCK_CLASS);
      body.style.overflow = '';
    }
  };

  const hideDialog = () => {
    if (!dialog) {
      return;
    }

    dialog.classList.remove('is-visible');
    dialog.setAttribute('aria-hidden', 'true');
    setBodyLock(false);
  };

  const showDialog = () => {
    if (!dialog) {
      return;
    }

    dialog.classList.add('is-visible');
    dialog.setAttribute('aria-hidden', 'false');
    setBodyLock(true);
  };

  const updateLabel = (type) => {
    if (!typeLabel) {
      return;
    }

    const text = type === 'business' && BUSINESS_LABEL !== '' ? BUSINESS_LABEL : PRIVATE_LABEL;
    if (text !== '') {
      typeLabel.textContent = text;
    }
  };

  const focusCompanySection = () => {
    if (companyNameInput) {
      companyNameInput.focus();
    }
  };

  const updateType = (newType, options = {}) => {
    const normalizedType = normalizeType(newType);

    if (typeInput) {
      typeInput.value = normalizedType;
    }

    form.setAttribute('data-customer-type', normalizedType);

    typeButtons.forEach((button) => {
      const buttonType = normalizeType(button.getAttribute('data-customer-type-option'));
      const isActive = buttonType === normalizedType;
      button.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });

    updateLabel(normalizedType);

    if (normalizedType === 'business' && companyContactInput && companyContactInput.value.trim() === '' && fullNameInput) {
      companyContactInput.value = fullNameInput.value;
    }

    if (options.closeDialog !== false) {
      hideDialog();
    }

    if (options.focusCompany && normalizedType === 'business') {
      window.requestAnimationFrame(() => focusCompanySection());
    }
  };

  typeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const buttonType = button.getAttribute('data-customer-type-option');
      updateType(buttonType, { closeDialog: false, focusCompany: true });
    });
  });

  dialogChoices.forEach((choice) => {
    choice.addEventListener('click', () => {
      const choiceType = choice.getAttribute('data-customer-type-choice');
      updateType(choiceType, { focusCompany: true });
    });
  });

  dialogCloseButtons.forEach((button) => {
    button.addEventListener('click', () => {
      hideDialog();
    });
  });

  if (dialog) {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        hideDialog();
      }
    });
  }

  if (openDialogButton) {
    openDialogButton.addEventListener('click', () => {
      showDialog();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hideDialog();
    }
  });

  // Ensure the correct label is applied on load.
  if (typeInput) {
    updateLabel(normalizeType(typeInput.value));
  }

  if (dialog && dialog.classList.contains('is-visible')) {
    setBodyLock(true);
  }
});