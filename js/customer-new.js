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
  const companyNameInput = form.querySelector('input[name="company_name"]');
  const billingToggle = form.querySelector('[data-company-billing-toggle]');
  const companySection = form.querySelector('[data-company-fields]');
  const companyLockableInputs = Array.from(form.querySelectorAll('[data-company-lockable]'));

  const suggestionsContainer = form.querySelector('[data-customer-suggestions]');
  const suggestionsList = suggestionsContainer ? suggestionsContainer.querySelector('[data-customer-suggestions-list]') : null;
  const modalTriggerButton = document.querySelector('[data-existing-modal-trigger]');
  const existingModal = document.getElementById('existing-customer-modal');
  const modalConfirmButton = existingModal ? existingModal.querySelector('[data-existing-customer-confirm]') : null;
  const modalDeclineButton = existingModal ? existingModal.querySelector('[data-existing-customer-decline]') : null;
  const modalFields = existingModal
    ? {
        code: existingModal.querySelector('[data-existing-customer-code]'),
        name: existingModal.querySelector('[data-existing-customer-name]'),
        email: existingModal.querySelector('[data-existing-customer-email]'),
        phone: existingModal.querySelector('[data-existing-customer-phone]'),
        address: existingModal.querySelector('[data-existing-customer-address]'),
      }
    : {};

  const ignoredMatches = new Set();
  let lastFetchedMatches = [];
  let currentSuggestions = [];
  let activeMatch = null;
  let abortController = null;

  const debounce = (fn, delay = 250) => {
    let timer = null;
    return (...args) => {
      if (timer) {
        window.clearTimeout(timer);
      }
      timer = window.setTimeout(() => {
        fn(...args);
      }, delay);
    };
  };

  const normalizeType = (value) => (value === 'business' ? 'business' : 'private');
  const isBusinessType = () => form.getAttribute('data-customer-type') === 'business';

  const focusCompanySection = () => {
    if (companyNameInput) {
      companyNameInput.focus();
    }
  };

  const syncPairs = [
    { personal: form.querySelector('input[name="full_name"]'), company: form.querySelector('input[name="company_contact_person"]') },
    { personal: form.querySelector('input[name="email"]'), company: form.querySelector('input[name="company_email"]') },
    { personal: form.querySelector('input[name="phone"]'), company: form.querySelector('input[name="company_phone"]') },
    { personal: form.querySelector('input[name="address"]'), company: form.querySelector('input[name="company_address"]') },
    { personal: form.querySelector('input[name="postal_code"]'), company: form.querySelector('input[name="company_postal_code"]') },
    { personal: form.querySelector('input[name="city"]'), company: form.querySelector('input[name="company_city"]') },
  ].filter((pair) => pair.personal && pair.company);

  const syncCompanyValues = () => {
    if (!isBusinessType() || (billingToggle && billingToggle.checked)) {
      return;
    }

    syncPairs.forEach(({ personal, company }) => {
      if (personal && company) {
        company.value = personal.value;
      }
    });
  };

  const updateCompanyLockState = () => {
    const shouldLock = isBusinessType() && (!billingToggle || !billingToggle.checked);

    if (companySection) {
      companySection.classList.toggle('is-locked', shouldLock);
    }

    companyLockableInputs.forEach((input) => {
      if (shouldLock) {
        input.setAttribute('readonly', 'readonly');
        input.classList.add('is-readonly');
      } else {
        input.removeAttribute('readonly');
        input.classList.remove('is-readonly');
      }
    });

    if (shouldLock) {
      syncCompanyValues();
    }
  };

  syncPairs.forEach(({ personal }) => {
    if (!personal) {
      return;
    }

    personal.addEventListener('input', () => {
      syncCompanyValues();
    });
  });

  if (billingToggle) {
    billingToggle.addEventListener('change', () => {
      updateCompanyLockState();
      syncCompanyValues();
    });
  }

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

    if (billingToggle) {
      billingToggle.disabled = normalizedType !== 'business';
      if (normalizedType !== 'business') {
        billingToggle.checked = false;
      }
    }

    updateCompanyLockState();
    syncCompanyValues();

    if (normalizedType === 'business' && options.focusCompany) {
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

  const getFallbackValue = (element) => {
    if (!element) {
      return '—';
    }
    return element.getAttribute('data-empty-value') || '—';
  };

  const setModalField = (element, value) => {
    if (!element) {
      return;
    }
    const fallback = getFallbackValue(element);
    const sanitized = typeof value === 'string' ? value.trim() : String(value ?? '').trim();
    element.textContent = sanitized !== '' ? sanitized : fallback;
  };

  const formatAddress = (match) => {
    if (!match) {
      return '';
    }
    const parts = [];
    if (match.address) {
      parts.push(match.address);
    }
    const locality = [match.postal_code, match.city].filter((part) => part && String(part).trim() !== '').join(' ');
    if (locality) {
      parts.push(locality.trim());
    }
    return parts.join(', ');
  };

  const openExistingCustomerModal = (match) => {
    if (!match) {
      return;
    }

    activeMatch = match;

    setModalField(modalFields.code, match.code);
    setModalField(modalFields.name, match.name);
    setModalField(modalFields.email, match.email);
    setModalField(modalFields.phone, match.phone);
    setModalField(modalFields.address, formatAddress(match));

    if (modalTriggerButton) {
      modalTriggerButton.click();
    } else if (existingModal) {
      existingModal.classList.add('is-visible');
      document.body.classList.add('modal-open');
    }
  };

  const applySuggestions = (matches) => {
    if (!suggestionsContainer || !suggestionsList) {
      return;
    }

    lastFetchedMatches = Array.isArray(matches) ? matches : [];
    const filtered = lastFetchedMatches.filter((match) => match && !ignoredMatches.has(String(match.id)));

    suggestionsList.innerHTML = '';

    if (filtered.length === 0) {
      suggestionsContainer.hidden = true;
      currentSuggestions = [];
      return;
    }

    suggestionsContainer.hidden = false;
    currentSuggestions = filtered;

    filtered.forEach((match, index) => {
      const item = document.createElement('li');
      item.className = 'customer-suggestions__item';

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'customer-suggestions__option';
      button.setAttribute('data-suggestion-index', String(index));

      const nameSpan = document.createElement('span');
      nameSpan.className = 'customer-suggestions__name';
      nameSpan.textContent = match.name || getFallbackValue(modalFields.name);
      button.appendChild(nameSpan);

      const metaParts = [];
      if (match.email) {
        metaParts.push(match.email);
      }
      if (match.phone) {
        metaParts.push(match.phone);
      }
      const locality = [match.postal_code, match.city].filter((part) => part && String(part).trim() !== '').join(' ');
      if (locality) {
        metaParts.push(locality.trim());
      }

      if (metaParts.length > 0) {
        const metaSpan = document.createElement('span');
        metaSpan.className = 'customer-suggestions__meta';
        metaSpan.textContent = metaParts.join(' • ');
        button.appendChild(metaSpan);
      }

      item.appendChild(button);
      suggestionsList.appendChild(item);
    });
  };

  const fetchSuggestions = (query) => {
    if (!suggestionsContainer || !suggestionsList) {
      return;
    }

    const trimmed = query.trim();
    if (trimmed.length < 2) {
      applySuggestions([]);
      return;
    }

    if (abortController && typeof abortController.abort === 'function') {
      abortController.abort();
    }

    if (window.AbortController) {
      abortController = new AbortController();
    }

    const options = abortController ? { signal: abortController.signal } : {};

    fetch(`customer-search.php?q=${encodeURIComponent(trimmed)}`, options)
      .then((response) => {
        if (!response.ok) {
          throw new Error('Request failed');
        }
        return response.json();
      })
      .then((payload) => {
        const matches = Array.isArray(payload?.data) ? payload.data : [];
        applySuggestions(matches);
      })
      .catch((error) => {
        if (error.name === 'AbortError') {
          return;
        }
        applySuggestions([]);
      });
  };

  const debouncedFetchSuggestions = debounce(fetchSuggestions, 250);

  if (fullNameInput) {
    fullNameInput.addEventListener('input', () => {
      debouncedFetchSuggestions(fullNameInput.value || '');
    });

    fullNameInput.addEventListener('blur', () => {
      if (!fullNameInput.value || fullNameInput.value.trim().length < 2) {
        applySuggestions([]);
      }
    });
  }

  if (suggestionsList) {
    suggestionsList.addEventListener('click', (event) => {
      const button = event.target.closest('[data-suggestion-index]');
      if (!button) {
        return;
      }
      const index = Number.parseInt(button.getAttribute('data-suggestion-index') || '', 10);
      if (Number.isNaN(index) || !currentSuggestions[index]) {
        return;
      }
      openExistingCustomerModal(currentSuggestions[index]);
    });
  }

  if (modalConfirmButton) {
    modalConfirmButton.addEventListener('click', () => {
      if (!activeMatch || typeof activeMatch.id === 'undefined') {
        return;
      }
      const customerId = Number.parseInt(activeMatch.id, 10);
      if (Number.isNaN(customerId) || customerId <= 0) {
        return;
      }
      window.location.href = `customer.php?id=${encodeURIComponent(String(customerId))}`;
    });
  }

  if (modalDeclineButton) {
    modalDeclineButton.addEventListener('click', () => {
      if (activeMatch && typeof activeMatch.id !== 'undefined') {
        ignoredMatches.add(String(activeMatch.id));
      }
      activeMatch = null;
      applySuggestions(lastFetchedMatches);
    });
  }

  updateType(normalizeType(typeInput ? typeInput.value : 'private'));
});