(function () {
  const body = document.body;
  const openButtons = document.querySelectorAll('[data-modal-target]');
  const closeButtons = document.querySelectorAll('[data-modal-close]');

  function openModal(modal) {
    if (!modal) {
      return;
    }
    modal.classList.add('is-visible');
    body.classList.add('modal-open');
    const firstFocusable = modal.querySelector('button, [href], input, select, textarea');
    if (firstFocusable) {
      firstFocusable.focus({ preventScroll: true });
    }
  }

  function closeModal(modal) {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-visible');
    if (!document.querySelector('.modal.is-visible')) {
      body.classList.remove('modal-open');
    }
  }

  openButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const targetId = button.getAttribute('data-modal-target');
      if (!targetId) {
        return;
      }
      const modal = document.getElementById(targetId);
      openModal(modal);
    });
  });

  closeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const modal = button.closest('.modal');
      closeModal(modal);
    });
  });

  document.addEventListener('click', (event) => {
    const overlay = event.target.closest('.modal');
    if (overlay && !event.target.closest('.modal__panel')) {
      closeModal(overlay);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.modal.is-visible').forEach((modal) => closeModal(modal));
    }
  });
})();