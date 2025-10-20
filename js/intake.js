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
  const panels = {
    customer: $('[data-step="customer"]', form),
    visit: $('[data-step="visit"]', form)
  };
  const resultSection = $('[data-result]', modal);
  const feedback = $('[data-feedback]', modal);
  const stepOrder = ['customer', 'visit'];
  let activeStep = 'customer';
  let isSubmitting = false;

  function openModal() {
    if (!modal) {
      return;
    }
    modal.hidden = false;
    document.body.classList.add('has-open-modal');
    resetForm();
  }

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.hidden = true;
    document.body.classList.remove('has-open-modal');
  }

  function resetForm() {
    if (!form) {
      return;
    }
    form.reset();
    form.hidden = false;
    activeStep = 'customer';
    Object.keys(panels).forEach(function (key) {
      if (panels[key]) {
        panels[key].hidden = key !== activeStep;
      }
    });
    if (resultSection) {
      resultSection.hidden = true;
    }
    if (feedback) {
      feedback.hidden = true;
      feedback.textContent = '';
      feedback.classList.remove('intake-feedback--error', 'intake-feedback--success');
    }
  }

  function validatePanel(panel) {
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
    return true;
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

  function setSubmitting(state) {
    isSubmitting = state;
    if (!form) {
      return;
    }
    const buttons = $all('button', form);
    buttons.forEach(function (button) {
      button.disabled = state;
    });
  }

  function advanceStep(nextStep) {
    if (!panels[nextStep]) {
      return;
    }
    if (panels[activeStep]) {
      panels[activeStep].hidden = true;
    }
    panels[nextStep].hidden = false;
    activeStep = nextStep;
  }

  function handleNextStep(event) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }
    const currentPanel = panels[activeStep];
    if (!validatePanel(currentPanel)) {
      return;
    }
    const currentIndex = stepOrder.indexOf(activeStep);
    const nextStep = stepOrder[currentIndex + 1];
    if (nextStep) {
      advanceStep(nextStep);
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
      advanceStep(prevStep);
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    const currentPanel = panels[activeStep];
    if (!validatePanel(currentPanel)) {
      return;
    }

    const formData = new FormData(form);
    const payload = {};
    formData.forEach(function (value, key) {
      payload[key] = value;
    });

    setSubmitting(true);
    showFeedback('Registratie wordt verwerkt…', 'success');

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
        let message = 'Registratie is niet gelukt. Probeer opnieuw.';
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
          referenceNode.textContent = data.reference_code || 'Onbekend';
        }
        if (appointmentNode) {
          appointmentNode.textContent = data.appointment_at_formatted || '-';
        }
        if (caseLink && data.case_url) {
          caseLink.href = data.case_url;
        }
        if (pdfLink && data.pdf_url) {
          pdfLink.href = data.pdf_url;
        }
      }

      showFeedback('Intake is succesvol vastgelegd.', 'success');
      if (form) {
        form.hidden = true;
      }
    } catch (error) {
      console.error(error);
      showFeedback('Er is een fout opgetreden tijdens het opslaan. Controleer de gegevens en probeer opnieuw.', 'error');
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

  form.addEventListener('submit', handleSubmit);
})();