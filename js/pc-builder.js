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

    function parseStageKeywords(raw) {
      if (!raw) {
        return [];
      }
      let parsed;
      try {
        parsed = JSON.parse(raw);
      } catch (error) {
        parsed = String(raw).split(',');
      }
      if (!Array.isArray(parsed)) {
        parsed = [parsed];
      }
      return parsed
        .map((value) => String(value).trim().toLowerCase())
        .filter((value) => value !== '');
    }

    function setupAssembly(assembly, context) {
      if (!(assembly instanceof HTMLElement)) {
        return;
      }

      const itemSelect = context.select instanceof HTMLSelectElement ? context.select : null;
      const notesInput = context.notes instanceof HTMLInputElement ? context.notes : null;
      const updateNotes = typeof context.updateNotes === 'function' ? context.updateNotes : null;
      const defaultPlaceholder = typeof context.defaultPlaceholder === 'string' ? context.defaultPlaceholder : '';

      const stageButtons = Array.from(assembly.querySelectorAll('[data-stage]'));
      const slotButtons = Array.from(assembly.querySelectorAll('[data-slot]'));
      const stageTitle = assembly.querySelector('[data-stage-title]');
      const stageDescription = assembly.querySelector('[data-stage-description]');
      const stageSubtitle = assembly.querySelector('[data-stage-subtitle]');
      const showAllButton = assembly.querySelector('[data-show-all-options]');
      const noSuggestions = assembly.querySelector('[data-no-suggestions]');

      const defaultTitle = stageTitle ? stageTitle.getAttribute('data-default') || stageTitle.textContent || '' : '';
      const defaultDescription = stageDescription ? stageDescription.getAttribute('data-default') || stageDescription.textContent || '' : '';

      const options = itemSelect ? Array.from(itemSelect.options) : [];
      let activeStageKey = '';
      let activeStageLabel = '';
      let activeStageNote = '';

      const updateAssemblyState = () => {
        if (activeStageKey !== '') {
          assembly.setAttribute('data-active-stage', activeStageKey);
        } else {
          assembly.removeAttribute('data-active-stage');
        }
        if (activeStageLabel !== '') {
          assembly.setAttribute('data-active-stage-label', activeStageLabel);
        } else {
          assembly.removeAttribute('data-active-stage-label');
        }
        if (activeStageNote !== '') {
          assembly.setAttribute('data-active-stage-note', activeStageNote);
        } else {
          assembly.removeAttribute('data-active-stage-note');
        }
      };

      const applyFilter = (keywords) => {
        if (!itemSelect) {
          return;
        }
        const normalized = Array.isArray(keywords) ? keywords : [];
        let visibleCount = 0;
        options.forEach((option) => {
          if (option.value === '') {
            option.hidden = false;
            option.disabled = false;
            return;
          }
          const optionName = (option.getAttribute('data-item-name') || option.textContent || '').toLowerCase();
          let matches = true;
          if (normalized.length > 0) {
            matches = normalized.some((keyword) => keyword !== '' && optionName.includes(keyword));
          }
          option.hidden = normalized.length > 0 && !matches;
          option.disabled = normalized.length > 0 && !matches;
          if (!option.disabled) {
            visibleCount += 1;
          }
        });
        if (normalized.length > 0 && visibleCount === 0) {
          if (noSuggestions instanceof HTMLElement) {
            noSuggestions.hidden = false;
          }
        } else if (noSuggestions instanceof HTMLElement) {
          noSuggestions.hidden = true;
        }
        if (itemSelect.value !== '' && itemSelect.selectedOptions.length > 0 && itemSelect.selectedOptions[0].disabled) {
          itemSelect.value = '';
        }
      };

      const resetStage = () => {
        activeStageKey = '';
        activeStageLabel = '';
        activeStageNote = '';
        stageButtons.forEach((button) => {
          button.dataset.active = 'false';
          button.setAttribute('aria-pressed', 'false');
          button.classList.remove('is-active');
        });
        slotButtons.forEach((slot) => {
          slot.classList.remove('is-active');
        });
        if (stageTitle) {
          stageTitle.textContent = defaultTitle;
        }
        if (stageDescription) {
          stageDescription.textContent = defaultDescription;
        }
        if (stageSubtitle) {
          stageSubtitle.textContent = '';
        }
        if (notesInput) {
          notesInput.setAttribute('placeholder', defaultPlaceholder);
        }
        if (noSuggestions instanceof HTMLElement) {
          noSuggestions.hidden = true;
        }
        applyFilter([]);
        updateAssemblyState();
        if (updateNotes) {
          updateNotes();
        }
      };

      const setStage = (button, focus = false) => {
        if (!(button instanceof HTMLElement)) {
          resetStage();
          return;
        }
        const stageKey = button.getAttribute('data-stage') || '';
        const stageLabel = button.getAttribute('data-stage-label') || '';
        const stageDescriptionText = button.getAttribute('data-stage-description') || '';
        const stageSubtitleText = button.getAttribute('data-stage-subtitle') || '';
        const stageNote = button.getAttribute('data-stage-note') || '';
        const keywords = parseStageKeywords(button.getAttribute('data-stage-keywords') || '');

        activeStageKey = stageKey;
        activeStageLabel = stageLabel;
        activeStageNote = stageNote;

        stageButtons.forEach((stageButton) => {
          const isCurrent = stageButton === button;
          stageButton.dataset.active = isCurrent ? 'true' : 'false';
          stageButton.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
          stageButton.classList.toggle('is-active', isCurrent);
        });

        slotButtons.forEach((slot) => {
          const slotKey = slot.getAttribute('data-slot') || '';
          slot.classList.toggle('is-active', slotKey === stageKey);
        });

        if (stageTitle) {
          stageTitle.textContent = stageLabel !== '' ? stageLabel : defaultTitle;
        }
        if (stageDescription) {
          stageDescription.textContent = stageDescriptionText !== '' ? stageDescriptionText : defaultDescription;
        }
        if (stageSubtitle) {
          stageSubtitle.textContent = stageSubtitleText;
        }
        if (notesInput) {
          const placeholder = stageNote !== '' ? stageNote : (stageLabel !== '' ? stageLabel : defaultPlaceholder);
          notesInput.setAttribute('placeholder', placeholder);
        }

        applyFilter(keywords);
        updateAssemblyState();
        if (updateNotes) {
          updateNotes();
        }
        if (focus) {
          button.focus();
        }
      };

      stageButtons.forEach((button) => {
        button.dataset.active = 'false';
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => {
          const stageKey = button.getAttribute('data-stage') || '';
          if (stageKey !== '' && stageKey === activeStageKey) {
            resetStage();
            button.blur();
          } else {
            setStage(button, true);
          }
        });
      });

      slotButtons.forEach((slot) => {
        slot.addEventListener('click', () => {
          const slotKey = slot.getAttribute('data-slot') || '';
          const target = stageButtons.find((button) => (button.getAttribute('data-stage') || '') === slotKey);
          if (!target) {
            return;
          }
          if (slotKey !== '' && slotKey === activeStageKey) {
            resetStage();
            target.blur();
            return;
          }
          setStage(target, true);
        });
      });

      if (showAllButton instanceof HTMLElement) {
        showAllButton.addEventListener('click', () => {
          resetStage();
          if (itemSelect) {
            itemSelect.focus();
          }
        });
      }

      const initialStage = stageButtons.find((button) => !button.classList.contains('is-complete')) || stageButtons[0] || null;
      if (initialStage) {
        setStage(initialStage);
      } else {
        resetStage();
      }
    }

    document.querySelectorAll('[data-component-form]').forEach((form) => {
      const select = form.querySelector('select[name="item_id"]');
      const notes = form.querySelector('input[name="notes"]');
      if (!(select instanceof HTMLSelectElement) || !(notes instanceof HTMLInputElement)) {
        return;
      }
      const buildContainer = form.closest('[data-build-item]');
      const buildReference = buildContainer ? buildContainer.getAttribute('data-build-reference') || '' : '';
      const assembly = form.closest('[data-assembly]');
      const defaultPlaceholder = notes.getAttribute('placeholder') || '';
      const getStageDetails = () => {
        if (!(assembly instanceof HTMLElement)) {
          return { key: '', label: '', note: '' };
        }
        return {
          key: assembly.getAttribute('data-active-stage') || '',
          label: assembly.getAttribute('data-active-stage-label') || '',
          note: assembly.getAttribute('data-active-stage-note') || '',
        };
      };
      const updateNotes = () => {
        const option = select.selectedOptions[0];
        if (!option) {
          return;
        }
        const itemName = option.getAttribute('data-item-name') || '';
        const suggestionParts = [];
        const stageDetails = getStageDetails();
        if (stageDetails.note !== '') {
          suggestionParts.push(stageDetails.note);
        } else if (stageDetails.label !== '') {
          suggestionParts.push(stageDetails.label);
        }
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

      setupAssembly(assembly, {
        select,
        notes,
        updateNotes,
        defaultPlaceholder,
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