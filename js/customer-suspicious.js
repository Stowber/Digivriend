(function () {
  const modal = document.getElementById('customer-suspicious-modal');
  if (!modal) {
    return;
  }

  const form = modal.querySelector('[data-suspicious-form]');
  const reasonField = modal.querySelector('#suspicious-modal-reason');
  const trigger = document.querySelector('[data-suspicious-trigger]');
  const confirmMessage = form ? form.getAttribute('data-confirm-message') : '';

  function openModalOnLoad() {
    if (modal.getAttribute('data-open-on-load') !== 'true') {
      return;
    }

    modal.classList.add('is-visible');
    document.body.classList.add('modal-open');
    if (reasonField) {
      reasonField.focus({ preventScroll: true });
    }
  }

  if (form && confirmMessage) {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(confirmMessage)) {
        event.preventDefault();
      }
    });
  }

  if (trigger && reasonField) {
    trigger.addEventListener('click', () => {
      window.setTimeout(() => {
        reasonField.focus({ preventScroll: true });
      }, 150);
    });
  }

  openModalOnLoad();
})();