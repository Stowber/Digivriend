document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('[data-customer-form]');
  if (!form) {
    return;
  }

  const typeInput = form.querySelector('[data-customer-type-input]');
  const typeButtons = Array.from(document.querySelectorAll('[data-customer-type-option]'));
  const switchContainer = document.querySelector('[data-customer-type-switch]');
  const switchIndicator = switchContainer ? switchContainer.querySelector('[data-customer-type-indicator]') : null;
  const switchButtons = switchContainer ? Array.from(switchContainer.querySelectorAll('[data-customer-type-option]')) : [];
  const fullNameInput = form.querySelector('#customerFullName');
  const companyContactInput = form.querySelector('input[name="company_contact_person"]');
  const companyNameInput = form.querySelector('input[name="company_name"]');

  const normalizeType = (value) => (value === 'business' ? 'business' : 'private');

  const focusCompanySection = () => {
    if (companyNameInput) {
      companyNameInput.focus();
    }
  };

  const updateIndicator = (type) => {
    if (!switchContainer || !switchIndicator) {
      return;
    }

    const options = Array.from(switchContainer.querySelectorAll('[data-customer-type-option]'));
    const index = options.findIndex((option) => normalizeType(option.getAttribute('data-customer-type-option')) === type);

  if (index >= 0) {
      switchIndicator.style.transform = `translateX(${index * 100}%)`;
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
      button.classList.toggle('is-active', isActive);
      button.tabIndex = isActive ? 0 : -1;
    });

    updateIndicator(normalizedType);

    if (normalizedType === 'business' && companyContactInput && companyContactInput.value.trim() === '' && fullNameInput) {
      companyContactInput.value = fullNameInput.value;
    }

    if (options.focusCompany && normalizedType === 'business') {
      window.requestAnimationFrame(() => focusCompanySection());
    }
  };

  typeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const buttonType = button.getAttribute('data-customer-type-option');
      updateType(buttonType, { focusCompany: true });
    });
  });

  if (switchContainer) {
    switchContainer.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
      }

  event.preventDefault();

  const buttons = switchButtons.length > 0 ? switchButtons : typeButtons;
      if (buttons.length === 0) {
        return;
      }

      const activeIndex = buttons.findIndex((button) => button.classList.contains('is-active'));
      const direction = event.key === 'ArrowLeft' ? -1 : 1;
      const nextIndex = activeIndex === -1
        ? (direction === -1 ? buttons.length - 1 : 0)
        : (activeIndex + direction + buttons.length) % buttons.length;

  const nextButton = buttons[nextIndex];
      if (nextButton) {
        nextButton.focus();
        updateType(nextButton.getAttribute('data-customer-type-option'));
      }
    });
  }

  updateType(normalizeType(typeInput ? typeInput.value : 'private'));
});