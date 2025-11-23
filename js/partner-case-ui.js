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

  function bindAccordions() {
    document.querySelectorAll('[data-accordion]').forEach((container) => {
      container.querySelectorAll('[data-accordion-toggle]').forEach((trigger) => {
        const content = trigger.nextElementSibling;
        if (!content) return;
        trigger.addEventListener('click', () => {
          const expanded = trigger.classList.toggle('is-open');
          content.style.display = expanded ? 'grid' : 'none';
        });
        content.style.display = 'none';
      });
    });
  }

  function updateDrawer(amountField, descriptionField) {
    const drawerAmount = document.querySelector('[data-drawer-amount]');
    const drawerDescription = document.querySelector('[data-drawer-description]');
    if (drawerAmount && amountField) {
      drawerAmount.textContent = `€ ${amountField.value || '0'}`;
    }
    if (drawerDescription && descriptionField) {
      drawerDescription.innerHTML = (descriptionField.value || '').replace(/\n/g, '<br>');
    }
  }

  function bindMicroToggles() {
    document.querySelectorAll('[data-checklist-add]').forEach((button) => {
      const targetDescription = document.querySelector(button.dataset.targetDescription || '');
      const targetAmount = document.querySelector(button.dataset.targetAmount || '');
      const addition = button.dataset.checklistAdd || '';
      const delta = Number(button.dataset.addAmount || 0);
      button.addEventListener('click', () => {
        if (!targetDescription || !targetAmount) return;
        const isActive = button.classList.toggle('is-active');
        const lines = targetDescription.value.split('\n').filter(Boolean);
        if (isActive && addition) {
          lines.push(`• ${addition}`);
        } else {
          const index = lines.findIndex((line) => line.includes(addition));
          if (index > -1) lines.splice(index, 1);
        }
        targetDescription.value = lines.join('\n');
        const currentAmount = Number(targetAmount.value || 0);
        const nextAmount = Math.max(0, isActive ? currentAmount + delta : currentAmount - delta);
        if (!Number.isNaN(nextAmount)) {
          targetAmount.value = nextAmount.toFixed(2);
        }
        updateDrawer(targetAmount, targetDescription);
        showToast('Zaktualizowano zestaw wyceny.');
      });
    });
  }

  function bindDrawer() {
    const openers = document.querySelectorAll('[data-drawer-open]');
    const closers = document.querySelectorAll('[data-drawer-close]');
    const toggleDrawer = (drawer, show) => {
      if (!drawer) return;
      drawer.classList.toggle('is-visible', show);
      drawer.setAttribute('aria-hidden', String(!show));
      document.body.classList.toggle('modal-open', show);
    };

    openers.forEach((opener) => {
      opener.addEventListener('click', () => {
        const drawer = document.getElementById(opener.dataset.drawerOpen);
        toggleDrawer(drawer, true);
      });
    });

    closers.forEach((closer) => {
      closer.addEventListener('click', () => toggleDrawer(closer.closest('.drawer'), false));
    });
  }

  function bindAmountRange() {
    const range = document.querySelector('[data-amount-range]');
    if (!range) return;
    const target = document.querySelector(range.dataset.targetAmount || '');
    if (!target) return;
    range.addEventListener('input', () => {
      target.value = range.value;
      updateDrawer(target, document.querySelector('[data-estimate-description]'));
    });
    target.addEventListener('input', () => {
      range.value = target.value || 0;
      updateDrawer(target, document.querySelector('[data-estimate-description]'));
    });
  }

  function bindLiveDrawerSync() {
    const amountField = document.querySelector('[data-estimate-amount]');
    const descriptionField = document.querySelector('[data-estimate-description]');
    if (amountField) {
      amountField.addEventListener('input', () => updateDrawer(amountField, descriptionField));
    }
    if (descriptionField) {
      descriptionField.addEventListener('input', () => updateDrawer(amountField, descriptionField));
    }
    updateDrawer(amountField, descriptionField);
  }

  function bindArchiveHelpers() {
    const reasonField = document.querySelector('[data-archive-reason]');
    const preview = document.querySelector('[data-archive-preview]');
    const form = document.querySelector('[data-archive-form]');
    const submitButton = document.querySelector('[data-archive-submit]');

    const syncPreview = () => {
      if (!preview) return;
      const text = (reasonField ? reasonField.value : '').trim();
      preview.textContent = text || 'Brak dodatkowego opisu – archiwizujesz czysto.';
    };

    document.querySelectorAll('[data-archive-template]').forEach((button) => {
      button.addEventListener('click', () => {
        const template = button.dataset.archiveTemplate || '';
        if (reasonField) {
          reasonField.value = template;
        }
        syncPreview();
        showToast(button.dataset.toast || 'Dodano powód archiwizacji.');
      });
    });

    if (reasonField) {
      reasonField.addEventListener('input', syncPreview);
      syncPreview();
    }

    if (submitButton && form) {
      submitButton.addEventListener('click', () => {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindTemplateSelects();
    bindDropdowns();
    bindCopyButtons();
    bindPopovers();
    bindAddonChips();
    bindToastOnChange();
    bindAccordions();
    bindMicroToggles();
    bindDrawer();
    bindAmountRange();
    bindLiveDrawerSync();
    bindArchiveHelpers();
  });
})();