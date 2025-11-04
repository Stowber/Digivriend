(function () {
  const quickActionButtons = document.querySelectorAll('.checklist-quick-action');
  const modalForms = document.querySelectorAll('.checklist-modal-form');

  const resetFormState = (form) => {
    const descriptionField = form.querySelector('input[name="description"]');
    const errorContainer = form.querySelector('.checklist-modal__error');
    if (descriptionField) {
      descriptionField.value = '';
    }
    if (errorContainer) {
      errorContainer.textContent = '';
      errorContainer.hidden = true;
    }
    const optionElements = form.querySelectorAll('.checklist-modal__option');
    optionElements.forEach((option) => option.classList.remove('is-selected'));
    form.querySelectorAll('[data-custom-input]').forEach((section) => {
      section.hidden = true;
      const input = section.querySelector('input, textarea');
      if (input) {
        input.value = '';
        input.removeAttribute('required');
      }
    });
  };

const updateCustomSections = (form, selectedRadio) => {
  const sections = form.querySelectorAll('[data-custom-input]');
  sections.forEach((section) => {
    const targetId = section.getAttribute('data-custom-input');
    const input = section.querySelector('input, textarea');
    const shouldShow = Boolean(
      selectedRadio && targetId && selectedRadio.getAttribute('data-input-target') === targetId
    );
    section.hidden = !shouldShow;
    if (input) {
      if (shouldShow) {
        input.setAttribute('required', 'required');
      } else {
        input.removeAttribute('required');
        input.value = '';
      }
    }
  });
};

  modalForms.forEach((form) => {
    const descriptionField = form.querySelector('input[name="description"]');
    const errorContainer = form.querySelector('.checklist-modal__error');
    const optionElements = form.querySelectorAll('.checklist-modal__option');

    const setSelectedOption = (radio) => {
      optionElements.forEach((option) => {
        option.classList.toggle('is-selected', !!radio && option.contains(radio));
      });
    };

    form.addEventListener('reset', () => {
      resetFormState(form);
    });

    form.querySelectorAll('input[type="radio"][name="preset_option"]').forEach((radio) => {
      radio.addEventListener('change', () => {
        if (errorContainer) {
          errorContainer.textContent = '';
          errorContainer.hidden = true;
        }
        updateCustomSections(form, radio);
        setSelectedOption(radio);
        if (descriptionField) {
          if (radio.hasAttribute('data-requires-input')) {
            descriptionField.value = '';
          } else {
            const preset = radio.getAttribute('data-description') || radio.value;
            descriptionField.value = preset;
          }
        }
      });
    });

    form.addEventListener('submit', (event) => {
      const selected = form.querySelector('input[type="radio"][name="preset_option"]:checked');
      if (!selected) {
        event.preventDefault();
        if (errorContainer) {
          errorContainer.textContent = 'Wybierz jedną z opcji, aby dodać zadanie.';
          errorContainer.hidden = false;
        }
        return;
      }

      const requiresInput = selected.hasAttribute('data-requires-input');
      if (requiresInput) {
        const targetId = selected.getAttribute('data-input-target');
        const customSection = targetId
          ? form.querySelector(`[data-custom-input="${targetId}"]`)
          : null;
        const customInput = customSection ? customSection.querySelector('input, textarea') : null;
        if (!customInput || customInput.value.trim() === '') {
          event.preventDefault();
          if (errorContainer) {
            errorContainer.textContent = 'Podaj opis zadania dla wybranej opcji.';
            errorContainer.hidden = false;
          }
          if (customInput) {
            customInput.focus();
          }
          return;
        }
        if (descriptionField) {
          descriptionField.value = customInput.value.trim();
        }
      } else if (descriptionField) {
        const preset = selected.getAttribute('data-description') || selected.value;
        descriptionField.value = preset;
      }
    });

    resetFormState(form);
  });

  quickActionButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const targetId = button.getAttribute('data-modal-target');
      const checklistId = button.getAttribute('data-checklist');
      if (!targetId) {
        return;
      }
      const modal = document.getElementById(targetId);
      if (!modal) {
        return;
      }
      const form = modal.querySelector('.checklist-modal-form');
      if (!form) {
        return;
      }

      form.reset();
      const checklistField = form.querySelector('input[name="checklist_id"]');
      if (checklistField) {
        checklistField.value = checklistId || '';
      }
      const firstRadio = form.querySelector('input[type="radio"][name="preset_option"]');
      if (firstRadio) {
        firstRadio.focus({ preventScroll: true });
      }
    });
  });
})();