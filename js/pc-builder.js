(function () {
  function ready() {
    const buildItems = Array.from(document.querySelectorAll('[data-build-item]'));
    const searchInput = document.querySelector('[data-build-search]');
    const counterElement = document.querySelector('[data-build-count]');
    const statusButtons = Array.from(document.querySelectorAll('[data-filter-status]'));
    const statusCountsElements = Array.from(document.querySelectorAll('[data-status-count]'));
    const activeStatuses = new Set();

    if (counterElement) {
      counterElement.setAttribute('data-total', String(buildItems.length));
      counterElement.textContent = String(buildItems.length);
    }

    function computeStatusCounts() {
      const counts = {};
      buildItems.forEach((item) => {
        const status = (item.dataset.status || '').toLowerCase();
        if (!counts[status]) {
          counts[status] = 0;
        }
        counts[status] += 1;
      });
      return counts;
    }

    function refreshStatusCounts() {
      const counts = computeStatusCounts();
      statusCountsElements.forEach((element) => {
        const key = (element.dataset.statusCount || '').toLowerCase();
        if (key !== '' && counts[key] !== undefined) {
          element.textContent = String(counts[key]);
        }
      });
      statusButtons.forEach((button) => {
        const key = (button.dataset.filterStatus || '').toLowerCase();
        const badge = button.querySelector('small');
        if (badge) {
          badge.textContent = String(counts[key] ?? 0);
        }
      });
    }

    function applyFilters() {
      const query = (searchInput instanceof HTMLInputElement ? searchInput.value : '').trim().toLowerCase();
      let visible = 0;
      buildItems.forEach((item) => {
        const status = (item.dataset.status || '').toLowerCase();
        const searchIndex = item.dataset.search || '';
        const matchesStatus = activeStatuses.size === 0 || activeStatuses.has(status);
        const matchesSearch = query === '' || searchIndex.includes(query);
        const shouldShow = matchesStatus && matchesSearch;
        item.style.display = shouldShow ? '' : 'none';
        if (shouldShow) {
          visible += 1;
        }
      });
      if (counterElement) {
        counterElement.textContent = String(visible);
      }
    }

    statusButtons.forEach((button) => {
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', () => {
        const statusKey = (button.dataset.filterStatus || '').toLowerCase();
        const isActive = button.dataset.active === 'true';
        if (isActive) {
          button.dataset.active = 'false';
          activeStatuses.delete(statusKey);
        } else {
          button.dataset.active = 'true';
          activeStatuses.add(statusKey);
        }
        button.setAttribute('aria-pressed', button.dataset.active || 'false');
        button.classList.toggle('is-active', button.dataset.active === 'true');
        applyFilters();
      });
    });

    if (searchInput instanceof HTMLInputElement) {
      searchInput.addEventListener('input', applyFilters);
      searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          searchInput.value = '';
          applyFilters();
        }
      });
    }

    refreshStatusCounts();
    applyFilters();

    const caseSelect = document.querySelector('[data-case-select]');
    const summaryField = document.querySelector('[data-build-summary]');
    if (caseSelect instanceof HTMLSelectElement && summaryField instanceof HTMLTextAreaElement) {
      const updateSummary = () => {
        const selected = caseSelect.selectedOptions[0];
        if (!selected) {
          return;
        }
        const autoSummary = selected.getAttribute('data-auto-summary') || '';
        const current = summaryField.value.trim();
        const lastFilled = summaryField.getAttribute('data-autofilled') || '';
        if (autoSummary !== '' && (current === '' || current === lastFilled)) {
          summaryField.value = autoSummary;
          summaryField.setAttribute('data-autofilled', autoSummary);
        }
      };
      caseSelect.addEventListener('change', updateSummary);
      updateSummary();
    }

    document.querySelectorAll('[data-copy-reference]').forEach((button) => {
      button.addEventListener('click', () => {
        const reference = button.getAttribute('data-copy-reference') || '';
        if (reference === '') {
          return;
        }
        const finish = () => {
          button.setAttribute('data-copied', 'true');
          setTimeout(() => {
            button.removeAttribute('data-copied');
          }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(reference).then(finish).catch(() => {
            const fallback = document.createElement('textarea');
            fallback.value = reference;
            fallback.setAttribute('readonly', 'true');
            fallback.style.position = 'absolute';
            fallback.style.left = '-9999px';
            document.body.appendChild(fallback);
            fallback.select();
            document.execCommand('copy');
            document.body.removeChild(fallback);
            finish();
          });
        } else {
          const fallback = document.createElement('textarea');
          fallback.value = reference;
          fallback.setAttribute('readonly', 'true');
          fallback.style.position = 'absolute';
          fallback.style.left = '-9999px';
          document.body.appendChild(fallback);
          fallback.select();
          document.execCommand('copy');
          document.body.removeChild(fallback);
          finish();
        }
      });
    });

    document.querySelectorAll('[data-component-form]').forEach((form) => {
      const select = form.querySelector('select[name="item_id"]');
      const notes = form.querySelector('input[name="notes"]');
      if (!(select instanceof HTMLSelectElement) || !(notes instanceof HTMLInputElement)) {
        return;
      }
      const buildContainer = form.closest('[data-build-item]');
      const buildReference = buildContainer ? buildContainer.getAttribute('data-build-reference') || '' : '';
      const updateNotes = () => {
        const option = select.selectedOptions[0];
        if (!option) {
          return;
        }
        const itemName = option.getAttribute('data-item-name') || '';
        const suggestionParts = [];
        if (itemName !== '') {
          suggestionParts.push(itemName);
        }
        if (buildReference !== '') {
          suggestionParts.push(buildReference);
        }
        const suggestion = suggestionParts.join(' → ');
        const current = notes.value.trim();
        const lastFilled = notes.getAttribute('data-autofilled') || '';
        if (suggestion !== '' && (current === '' || current === lastFilled)) {
          notes.value = suggestion;
          notes.setAttribute('data-autofilled', suggestion);
        }
      };
      select.addEventListener('change', updateNotes);
      notes.addEventListener('input', () => {
        notes.removeAttribute('data-autofilled');
      });
    });

    document.querySelectorAll('[data-leftover-form]').forEach((form) => {
      const referenceInput = form.querySelector('input[name="reference_code"]');
      const referenceButton = form.querySelector('[data-generate-reference]');
      const locationInput = form.querySelector('input[name="location"]');
      const buildReference = form.getAttribute('data-build-reference') || '';

      const generateCode = () => {
        if (!(referenceInput instanceof HTMLInputElement)) {
          return;
        }
        const now = new Date();
        const stamp = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}`;
        const random = Math.random().toString(36).slice(2, 6).toUpperCase();
        const parts = [];
        if (buildReference !== '') {
          parts.push(buildReference);
        } else {
          parts.push('PCB');
        }
        parts.push('LO');
        parts.push(stamp);
        parts.push(random);
        const suggestion = parts.join('-');
        referenceInput.value = suggestion;
        referenceInput.setAttribute('data-autofilled', suggestion);
        referenceInput.dispatchEvent(new Event('change', { bubbles: true }));
      };

      if (referenceButton instanceof HTMLElement) {
        referenceButton.addEventListener('click', (event) => {
          event.preventDefault();
          generateCode();
        });
      }

      if (referenceInput instanceof HTMLInputElement) {
        referenceInput.addEventListener('input', () => {
          referenceInput.removeAttribute('data-autofilled');
        });
      }

      if (locationInput instanceof HTMLInputElement) {
        const baseLocation = locationInput.value.trim();
        const suggestion = baseLocation !== '' && buildReference !== '' ? `${baseLocation} / ${buildReference}` : '';
        if (suggestion !== '' && (locationInput.value.trim() === baseLocation || locationInput.value.trim() === '')) {
          locationInput.value = suggestion;
          locationInput.setAttribute('data-autofilled', suggestion);
        }
        locationInput.addEventListener('input', () => {
          locationInput.removeAttribute('data-autofilled');
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();