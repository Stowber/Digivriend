(function () {
  function closeAllPopovers() {
    document.querySelectorAll('.field-help__popover').forEach(function (popover) {
      popover.setAttribute('hidden', 'hidden');
    });
    document.querySelectorAll('.field-help').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    if (target.matches('.field-help')) {
      event.preventDefault();
      var controls = target.getAttribute('aria-controls');
      var popover = controls ? document.getElementById(controls) : null;

      if (!popover) {
        return;
      }

      var isOpen = popover.hasAttribute('hidden') === false;
      closeAllPopovers();

      if (!isOpen) {
        popover.removeAttribute('hidden');
        target.setAttribute('aria-expanded', 'true');
      }

      return;
    }

    if (!target.closest('.field-help__popover')) {
      closeAllPopovers();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAllPopovers();
    }
  });
})();