(function () {
  function $(selector, context) {
    return (context || document).querySelector(selector);
  }

  function $all(selector, context) {
    return Array.prototype.slice.call((context || document).querySelectorAll(selector));
  }

  const modal = $('[data-intake-modal]');
  if (!modal) {
    return;
  }

  const openButton = $('[data-intake-open]');
  const closeButtons = $all('[data-intake-close]', modal);
  const form = $('#intakeForm', modal);
  if (!form) {
    return;
  }

  const resultSection = $('[data-result]', modal);
  const feedback = $('[data-feedback]', modal);
  const translations = {
    loading: modal.dataset.loadingMessage || 'Registration in progress…',
    error: modal.dataset.errorMessage || 'Registration failed. Please try again.',
    success: modal.dataset.successMessage || 'Intake saved successfully.',
    exception: modal.dataset.exceptionMessage || 'An error occurred while saving. Check the details and try again.',
    unknown: modal.dataset.unknownValue || 'Unknown'
  };
  const resultPlaceholder = resultSection ? resultSection.getAttribute('data-placeholder') || '-' : '-';

  const stepperList = $('[data-stepper]', form);
  const stepperItems = stepperList ? $all('[data-stepper-item]', form) : [];
  const stepperBack = $('[data-stepper-back]', form);

  const appointmentHidden = $('[data-appointment-target]', form);
  const appointmentDateInput = $('[data-appointment-date]', form);
  const appointmentTimeInput = $('[data-appointment-time]', form);

  const stepOrder = ['customer', 'visit'];
  const panels = stepOrder.reduce(function (acc, key) {
    acc[key] = $('.intake-form__panel[data-step="' + key + '"]', form);
    return acc;
  }, {});

  const quickIssueButtons = $all('[data-issue-value]', form);
  const quickIssuesSelected = new Set();

  const customerSearchInput = $('[data-customer-search]', form);
  const customerResults = $('[data-customer-results]', form);
  const customerEmpty = $('[data-customer-empty]', form);
  const customerSelected = $('[data-customer-selected]', form);
  const customerIdInput = $('[data-customer-id]', form);
  const customerSelectedFields = {
    code: $('[data-selected-code]', form),
    name: $('[data-selected-name]', form),
    email: $('[data-selected-email]', form),
    phone: $('[data-selected-phone]', form),
    address: $('[data-selected-address]', form)
  };
  const customerClearButton = $('[data-customer-clear]', form);
  const customerSelectedEmptyText = customerSelected
    ? customerSelected.getAttribute('data-selected-empty') || translations.unknown
    : translations.unknown;
  const customerRequiredMessage = customerIdInput
    ? customerIdInput.getAttribute('data-customer-required-message') || translations.error
    : translations.error;
  let customerSearchTimer = null;
  let customerSearchSequence = 0;

  let activeStep = stepOrder[0];
  let isSubmitting = false;

  function syncAppointment() {
    if (!appointmentHidden) {
      return;
    }
    const dateValue = appointmentDateInput ? appointmentDateInput.value : '';
    const timeValue = appointmentTimeInput ? appointmentTimeInput.value : '';
    if (dateValue && timeValue) {
      appointmentHidden.value = dateValue + 'T' + timeValue;
    } else {
      appointmentHidden.value = '';
    }
  }

  function updateStepper() {
    if (!stepperItems || stepperItems.length === 0) {
      return;
    }
    const activeIndex = stepOrder.indexOf(activeStep);
    stepperItems.forEach(function (item) {
      const stepKey = item.getAttribute('data-step');
      const stepIndex = stepOrder.indexOf(stepKey);
      const bullet = $('.intake-stepper__bullet', item);
      if (bullet && stepIndex >= 0) {
        bullet.textContent = String(stepIndex + 1);
      }
      item.classList.remove('intake-stepper__item--active', 'intake-stepper__item--complete');
      item.removeAttribute('aria-current');
      if (stepIndex === activeIndex) {
        item.classList.add('intake-stepper__item--active');
        item.setAttribute('aria-current', 'step');
      } else if (stepIndex !== -1 && stepIndex < activeIndex) {
        item.classList.add('intake-stepper__item--complete');
        item.setAttribute('aria-current', 'false');
      }
    });
    if (stepperBack) {
      const isFirstStep = activeStep === stepOrder[0];
      stepperBack.hidden = isFirstStep;
      stepperBack.disabled = isSubmitting || isFirstStep;
    }
  }

  function setStep(stepKey) {
    if (!panels[stepKey]) {
      return;
    }
    Object.keys(panels).forEach(function (key) {
      if (panels[key]) {
        panels[key].hidden = key !== stepKey;
      }
    });
    activeStep = stepKey;
    updateStepper();
  }

  function resetForm() {
    form.reset();
    form.hidden = false;
    if (resultSection) {
      resultSection.hidden = true;
    }
    clearFeedback();
    if (appointmentHidden) {
      appointmentHidden.value = '';
    }
    if (customerSearchTimer) {
      clearTimeout(customerSearchTimer);
      customerSearchTimer = null;
    }
    clearCustomerSelection(true);
    quickIssuesSelected.clear();
    quickIssueButtons.forEach(function (button) {
      button.classList.remove('is-selected');
    });
    isSubmitting = false;
    setStep(stepOrder[0]);
    syncAppointment();
  }

  function openModal() {
    modal.hidden = false;
    document.body.classList.add('has-open-modal');
    resetForm();
  }

  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('has-open-modal');
  }

  function validatePanel(panel, stepKey) {
    if (!panel) {
      return true;
    }
    const inputs = $all('input, select, textarea', panel);
    for (let i = 0; i < inputs.length; i += 1) {
      const input = inputs[i];
      if (!input.checkValidity()) {
        input.reportValidity();
        return false;
      }
    }
    if (stepKey === 'customer') {
      if (!customerIdInput || !customerIdInput.value) {
        showFeedback(customerRequiredMessage, 'error');
        if (customerSearchInput) {
          customerSearchInput.focus();
        }
        return false;
      }
    }
    if (stepKey === 'visit') {
      syncAppointment();
      if (appointmentHidden && !appointmentHidden.value) {
        if (appointmentDateInput && !appointmentDateInput.value) {
          appointmentDateInput.reportValidity();
          return false;
        }
        if (appointmentTimeInput && !appointmentTimeInput.value) {
          appointmentTimeInput.reportValidity();
          return false;
        }
        return false;
      }
    }
    return true;
  }

  function clearFeedback() {
    if (!feedback) {
      return;
    }
    feedback.hidden = true;
    feedback.textContent = '';
    feedback.classList.remove('intake-feedback--error', 'intake-feedback--success');
  }

  function showFeedback(message, type) {
    if (!feedback) {
      return;
    }
    feedback.hidden = false;
    feedback.textContent = message;
    feedback.classList.remove('intake-feedback--error', 'intake-feedback--success');
    if (type === 'error') {
      feedback.classList.add('intake-feedback--error');
    } else if (type === 'success') {
      feedback.classList.add('intake-feedback--success');
    }
  }

  function clearCustomerSelection(silent) {
    if (customerIdInput) {
      customerIdInput.value = '';
    }
    if (customerSearchInput) {
      customerSearchInput.value = '';
      customerSearchInput.setAttribute('aria-expanded', 'false');
    }
    if (customerResults) {
      customerResults.innerHTML = '';
    }
    if (customerEmpty) {
      customerEmpty.hidden = true;
    }
    if (customerSelected) {
      customerSelected.hidden = true;
    }
    Object.keys(customerSelectedFields).forEach(function (key) {
      const field = customerSelectedFields[key];
      if (field) {
        field.textContent = customerSelectedEmptyText;
      }
    });
    if (!silent) {
      clearFeedback();
    }
  }

  function renderCustomerResults(results) {
    if (!customerResults || !customerSearchInput) {
      return;
    }
    customerResults.innerHTML = '';
    if (customerEmpty) {
      customerEmpty.hidden = results.length !== 0;
    }
    if (results.length === 0) {
      customerSearchInput.setAttribute('aria-expanded', 'false');
      return;
    }
    customerSearchInput.setAttribute('aria-expanded', 'true');
    results.forEach(function (entry) {
      if (!entry || !entry.id) {
        return;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'customer-picker__result';
      button.setAttribute('role', 'option');
      const name = document.createElement('strong');
      name.textContent = entry.name || translations.unknown;
      const meta = document.createElement('span');
      const metaParts = [];
      if (entry.code) {
        metaParts.push(entry.code);
      }
      if (entry.email) {
        metaParts.push(entry.email);
      } else if (entry.phone) {
        metaParts.push(entry.phone);
      }
      meta.textContent = metaParts.join(' • ');
      button.appendChild(name);
      button.appendChild(meta);
      button.addEventListener('click', function () {
        selectCustomer(entry);
      });
      customerResults.appendChild(button);
    });
  }

  function selectCustomer(entry) {
    if (!customerIdInput) {
      return;
    }
    customerIdInput.value = entry && entry.id ? String(entry.id) : '';
    if (customerSearchInput) {
      customerSearchInput.value = entry.name || '';
      customerSearchInput.setAttribute('aria-expanded', 'false');
    }
    if (customerResults) {
      customerResults.innerHTML = '';
    }
    if (customerEmpty) {
      customerEmpty.hidden = true;
    }
    if (customerSelected) {
      customerSelected.hidden = false;
    }
    const addressParts = [];
    if (entry.address) {
      addressParts.push(entry.address);
    }
    const cityParts = [];
    if (entry.postal_code) {
      cityParts.push(entry.postal_code);
    }
    if (entry.city) {
      cityParts.push(entry.city);
    }
    if (cityParts.length > 0) {
      addressParts.push(cityParts.join(' '));
    }
    if (customerSelectedFields.code) {
      customerSelectedFields.code.textContent = entry.code || customerSelectedEmptyText;
    }
    if (customerSelectedFields.name) {
      customerSelectedFields.name.textContent = entry.name || customerSelectedEmptyText;
    }
    if (customerSelectedFields.email) {
      customerSelectedFields.email.textContent = entry.email || customerSelectedEmptyText;
    }
    if (customerSelectedFields.phone) {
      customerSelectedFields.phone.textContent = entry.phone || customerSelectedEmptyText;
    }
    if (customerSelectedFields.address) {
      customerSelectedFields.address.textContent = addressParts.join(' • ') || customerSelectedEmptyText;
    }
    clearFeedback();
  }

  function requestCustomerSearch(query) {
    if (!customerSearchInput) {
      return;
    }
    if (customerResults) {
      customerResults.innerHTML = '';
    }
    if (customerEmpty) {
      customerEmpty.hidden = true;
    }
    if (!query || query.length < 2) {
      customerSearchInput.setAttribute('aria-expanded', 'false');
      return;
    }
    const sequence = ++customerSearchSequence;
    fetch('customer-search.php?q=' + encodeURIComponent(query))
      .then(function (response) {
        if (!response.ok) {
          return { data: [] };
        }
        return response.json();
      })
      .then(function (payload) {
        if (sequence !== customerSearchSequence) {
          return;
        }
        const results = payload && Array.isArray(payload.data) ? payload.data : [];
        renderCustomerResults(results);
      })
      .catch(function () {
        if (sequence !== customerSearchSequence) {
          return;
        }
        renderCustomerResults([]);
      });
  }

  function setSubmitting(state) {
    isSubmitting = state;
    const buttons = $all('button', form);
    buttons.forEach(function (button) {
      button.disabled = state;
    });
    updateStepper();
  }

  if (customerSearchInput) {
    customerSearchInput.addEventListener('input', function () {
      if (customerSearchTimer) {
        clearTimeout(customerSearchTimer);
      }
      const query = customerSearchInput.value.trim();
      clearFeedback();
      customerSearchTimer = setTimeout(function () {
        requestCustomerSearch(query);
      }, 250);
    });
    customerSearchInput.addEventListener('focus', function () {
      const query = customerSearchInput.value.trim();
      if (query.length >= 2) {
        requestCustomerSearch(query);
      }
    });
    customerSearchInput.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (customerResults) {
          customerResults.innerHTML = '';
        }
        if (customerEmpty) {
          customerEmpty.hidden = true;
        }
        customerSearchInput.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if (customerClearButton) {
    customerClearButton.addEventListener('click', function () {
      clearCustomerSelection(false);
      if (customerSearchInput) {
        customerSearchInput.focus();
      }
    });
  }

  function handleNextStep(event) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }
    clearFeedback();
    const currentPanel = panels[activeStep];
    if (!validatePanel(currentPanel, activeStep)) {
      return;
    }
    const currentIndex = stepOrder.indexOf(activeStep);
    const nextStep = stepOrder[currentIndex + 1];
    if (nextStep) {
      setStep(nextStep);
    }
  }

  function handlePreviousStep(event) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }
    const currentIndex = stepOrder.indexOf(activeStep);
    const prevStep = stepOrder[currentIndex - 1];
    if (prevStep) {
      setStep(prevStep);
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    syncAppointment();
    const currentPanel = panels[activeStep];
    if (!validatePanel(currentPanel, activeStep)) {
      return;
    }

    const formData = new FormData(form);
    if (quickIssuesSelected.size > 0) {
      const existingDescription = (formData.get('problem_description') || '').toString().trim();
      const quickText = Array.from(quickIssuesSelected).join('\n');
      const combinedDescription = existingDescription ? existingDescription + '\n\n' + quickText : quickText;
      formData.set('problem_description', combinedDescription);
    }
    formData.delete('appointment_date');
    formData.delete('appointment_time');
    formData.delete('customer_search');

    const payload = {};
    formData.forEach(function (value, key) {
      payload[key] = value;
    });

    setSubmitting(true);
    showFeedback(translations.loading, 'success');

    try {
      const response = await fetch('store-intake.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await response.json();

      if (!response.ok || !data || !data.success) {
        let message = translations.error;
        if (data && data.error) {
          if (typeof data.error === 'string') {
            message = data.error;
          } else if (typeof data.error === 'object') {
            const values = Object.values(data.error).flat().map(String);
            if (values.length > 0) {
              message = values.join(' ');
            }
          }
        }
        showFeedback(message, 'error');
        return;
      }

      if (resultSection) {
        resultSection.hidden = false;
        const referenceNode = $('[data-result-reference]', resultSection);
        const appointmentNode = $('[data-result-appointment]', resultSection);
        const caseLink = $('[data-result-case]', resultSection);
        const pdfLink = $('[data-result-pdf]', resultSection);

        if (referenceNode) {
          referenceNode.textContent = data.reference_code || translations.unknown;
        }
        if (appointmentNode) {
          appointmentNode.textContent = data.appointment_at_formatted || resultPlaceholder;
        }
        if (caseLink && data.case_url) {
          caseLink.href = data.case_url;
        }
        if (pdfLink && data.pdf_url) {
          pdfLink.href = data.pdf_url;
        }
      }

      showFeedback(translations.success, 'success');
      form.hidden = true;
    } catch (error) {
      console.error(error);
      showFeedback(translations.exception, 'error');
    } finally {
      setSubmitting(false);
    }
  }

  if (openButton) {
    openButton.addEventListener('click', openModal);
  }

  closeButtons.forEach(function (button) {
    button.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) {
      closeModal();
    }
  });

  modal.addEventListener('keyup', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });

  const nextButtons = $all('[data-next-step]', form);
  nextButtons.forEach(function (button) {
    button.addEventListener('click', handleNextStep);
  });

  const prevButtons = $all('[data-prev-step]', form);
  prevButtons.forEach(function (button) {
    button.addEventListener('click', handlePreviousStep);
  });

  if (stepperBack) {
    stepperBack.addEventListener('click', handlePreviousStep);
  }

  stepperItems.forEach(function (item) {
    item.addEventListener('click', function () {
      if (isSubmitting) {
        return;
      }
      const stepKey = item.getAttribute('data-step');
      const targetIndex = stepOrder.indexOf(stepKey);
      const activeIndex = stepOrder.indexOf(activeStep);
      if (targetIndex !== -1 && targetIndex < activeIndex) {
        setStep(stepKey);
      }
    });
  });

  if (appointmentDateInput) {
    appointmentDateInput.addEventListener('input', syncAppointment);
    appointmentDateInput.addEventListener('change', syncAppointment);
  }

  if (appointmentTimeInput) {
    appointmentTimeInput.addEventListener('input', syncAppointment);
    appointmentTimeInput.addEventListener('change', syncAppointment);
  }

  quickIssueButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      if (isSubmitting) {
        return;
      }
      const value = button.getAttribute('data-issue-value');
      if (!value) {
        return;
      }
      if (quickIssuesSelected.has(value)) {
        quickIssuesSelected.delete(value);
        button.classList.remove('is-selected');
      } else {
        quickIssuesSelected.add(value);
        button.classList.add('is-selected');
      }
    });
  });

  setStep(activeStep);
  syncAppointment();
  updateStepper();

  form.addEventListener('submit', handleSubmit);
})();