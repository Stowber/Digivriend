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