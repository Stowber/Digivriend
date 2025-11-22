(function () {
  const toastStack = document.querySelector('[data-toast-stack]');

  function showToast(message) {
    if (!toastStack) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `<span class="toast__dot"></span><p class="toast__message">${message}</p>`;
    toastStack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    setTimeout(() => {
      toast.classList.remove('is-visible');
      setTimeout(() => toast.remove(), 300);
    }, 3200);
  }

  function handleTemplateSelect(select) {
    const targetAmount = document.querySelector(select.dataset.targetAmount);
    const targetDescription = document.querySelector(select.dataset.targetDescription);
    select.addEventListener('change', () => {
      const option = select.selectedOptions[0];
      if (!option) return;
      const amount = option.dataset.amount;
      const description = option.dataset.description;
      if (amount && targetAmount) {
        targetAmount.value = amount;
      }
      if (description && targetDescription) {
        targetDescription.value = description;
      }
      if (amount || description) {
        showToast('Zastosowano wybrany szablon.');
      }
    });
  }

  function bindTemplateSelects() {
    document.querySelectorAll('[data-template-select]').forEach(handleTemplateSelect);
  }

  function bindDropdowns() {
    document.querySelectorAll('[data-dropdown]').forEach((wrapper) => {
      const trigger = wrapper.querySelector('[data-dropdown-toggle]');
      const panel = wrapper.querySelector('.dropdown-panel');
      if (!trigger || !panel) return;
      trigger.addEventListener('click', () => {
        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        trigger.setAttribute('aria-expanded', String(!expanded));
        wrapper.classList.toggle('is-open');
      });
      document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) {
          trigger.setAttribute('aria-expanded', 'false');
          wrapper.classList.remove('is-open');
        }
      });
    });
  }

  function bindCopyButtons() {
    document.querySelectorAll('[data-copy-target]').forEach((button) => {
      button.addEventListener('click', () => {
        const target = document.querySelector(button.dataset.copyTarget);
        if (!target) return;
        const text = target.innerText || target.textContent || '';
        if (!text) return;
        if (navigator.clipboard) {
          navigator.clipboard.writeText(text.trim()).then(() => {
            showToast('Skopiowano do schowka.');
          });
        } else {
          const textarea = document.createElement('textarea');
          textarea.value = text.trim();
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand('copy');
          textarea.remove();
          showToast('Skopiowano do schowka.');
        }
      });
    });
  }

  function bindPopovers() {
    const popovers = document.querySelectorAll('.floating-popover');
    const closePopover = (popover) => {
      popover.hidden = true;
      popover.classList.remove('is-visible');
    };

    document.querySelectorAll('[data-open-popover]').forEach((trigger) => {
      trigger.addEventListener('click', () => {
        const target = document.getElementById(trigger.dataset.openPopover);
        if (!target) return;
        popovers.forEach(closePopover);
        target.hidden = false;
        requestAnimationFrame(() => target.classList.add('is-visible'));
      });
    });

    document.querySelectorAll('[data-popover-close]').forEach((button) => {
      button.addEventListener('click', () => {
        const popover = button.closest('.floating-popover');
        if (popover) closePopover(popover);
      });
    });
  }

  function bindAddonChips() {
    document.querySelectorAll('.addon-chips').forEach((container) => {
      const targetSelector = container.dataset.addonTarget;
      const targetField = targetSelector ? document.querySelector(targetSelector) : null;
      container.querySelectorAll('[data-addon]').forEach((chip) => {
        chip.addEventListener('click', () => {
          if (!targetField) return;
          const textToAdd = chip.dataset.addon || '';
          const current = targetField.value.trim();
          chip.classList.toggle('is-active');
          if (chip.classList.contains('is-active')) {
            const newValue = current ? `${current}\n- ${textToAdd}` : `- ${textToAdd}`;
            targetField.value = newValue;
          } else {
            targetField.value = current.replace(new RegExp(`- ${textToAdd}\\n?`, 'g'), '').trim();
          }
          showToast('Zaktualizowano opis dodatkami.');
        });
      });
    });
  }

  function bindToastOnChange() {
    document.querySelectorAll('[data-toast-on-change]').forEach((input) => {
      input.addEventListener('change', () => showToast('Zapisano szybkie ustawienie.'));
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindTemplateSelects();
    bindDropdowns();
    bindCopyButtons();
    bindPopovers();
    bindAddonChips();
    bindToastOnChange();
  });
})();