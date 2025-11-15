(function () {
  const modal = document.querySelector('[data-case-appointment-modal]');
  if (!modal) {
    return;
  }

  const body = document.body;
  const typeRadios = Array.prototype.slice.call(
    modal.querySelectorAll('input[name="appointment_type"]')
  );
  const typeCards = Array.prototype.slice.call(
    modal.querySelectorAll('.appointment-type-card')
  );
  const titleInput = modal.querySelector('[data-field="title"]');
  const durationSelect = modal.querySelector('[data-field="duration"]');
  const statusSelect = modal.querySelector('[data-field="status"]');
  const colorInput = modal.querySelector('[data-field="color"]');
  const confirmationMethodSelect = modal.querySelector('[data-field="confirmation-method"]');
  const confirmationStatusSelect = modal.querySelector('[data-field="confirmation-status"]');
  const colorPreview = modal.querySelector('[data-color-preview]');
  const autofocusTarget = modal.querySelector('[data-autofocus]');

  function updateCardStates() {
    typeCards.forEach((card) => {
      const input = card.querySelector('input[name="appointment_type"]');
      if (!input) {
        return;
      }
      if (input.checked) {
        card.classList.add('appointment-type-card--active');
      } else {
        card.classList.remove('appointment-type-card--active');
      }
    });
  }

  function updateColorPreview(color) {
    if (!colorPreview) {
      return;
    }
    const fallback = '#2563EB';
    const nextColor = color && color.trim() !== '' ? color : fallback;
    colorPreview.style.setProperty('--appointment-color', nextColor);
    const displayValue = nextColor.toUpperCase();
    colorPreview.setAttribute('data-color-value', displayValue);
    colorPreview.textContent = displayValue;
  }

  function applyPreset(radio) {
    if (!radio) {
      return;
    }
    const presetTitle = radio.getAttribute('data-default-title') || '';
    const presetDuration = radio.getAttribute('data-default-duration') || '';
    const presetStatus = radio.getAttribute('data-default-status') || '';
    const presetColor = radio.getAttribute('data-default-color') || '';
    const presetConfirmationMethod = radio.getAttribute('data-default-confirmation-method') || '';
    const presetConfirmationStatus = radio.getAttribute('data-default-confirmation-status') || '';

    if (titleInput && (titleInput.dataset.manual !== 'true' || titleInput.value.trim() === '')) {
      titleInput.value = presetTitle;
    }

    if (durationSelect && (durationSelect.dataset.manual !== 'true' || !durationSelect.value)) {
      if (presetDuration) {
        const optionExists = Array.prototype.slice
          .call(durationSelect.options)
          .some((option) => option.value === presetDuration);
        if (optionExists) {
          durationSelect.value = presetDuration;
        }
      }
    }

    if (statusSelect && presetStatus) {
      const statusExists = Array.prototype.slice
        .call(statusSelect.options)
        .some((option) => option.value === presetStatus);
      if (statusExists) {
        statusSelect.value = presetStatus;
      }
    }

    if (colorInput && presetColor) {
      colorInput.value = presetColor;
      updateColorPreview(presetColor);
    }

    if (confirmationMethodSelect) {
      const methodExists = Array.prototype.slice
        .call(confirmationMethodSelect.options)
        .some((option) => option.value === presetConfirmationMethod);
      confirmationMethodSelect.value = methodExists ? presetConfirmationMethod : '';
    }

    if (confirmationStatusSelect) {
      const statusExists = Array.prototype.slice
        .call(confirmationStatusSelect.options)
        .some((option) => option.value === presetConfirmationStatus);
      confirmationStatusSelect.value = statusExists ? presetConfirmationStatus : '';
    }
  }

  function markManual(event) {
    if (event && event.target) {
      event.target.dataset.manual = 'true';
    }
  }

  typeRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) {
        return;
      }
      applyPreset(radio);
      updateCardStates();
    });
  });

  if (titleInput) {
    titleInput.addEventListener('input', markManual);
  }

  if (durationSelect) {
    durationSelect.addEventListener('change', markManual);
  }

  if (colorInput) {
    colorInput.addEventListener('input', () => {
      updateColorPreview(colorInput.value);
    });
    updateColorPreview(colorInput.value);
  } else {
    updateColorPreview('');
  }

  updateCardStates();

  if (modal.dataset.openOnLoad === 'true') {
    requestAnimationFrame(() => {
      modal.classList.add('is-visible');
      body.classList.add('modal-open');
      modal.setAttribute('aria-hidden', 'false');
      if (autofocusTarget && typeof autofocusTarget.focus === 'function') {
        autofocusTarget.focus({ preventScroll: true });
      }
    });
  }
})();