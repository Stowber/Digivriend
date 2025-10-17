(function () {
  function ready() {
    const buildItems = Array.from(document.querySelectorAll('[data-build-item]'));
    const searchInput = document.querySelector('[data-build-search]');
    const counterElement = document.querySelector('[data-build-count]');
    const statusButtons = Array.from(document.querySelectorAll('[data-filter-status]'));
    const statusCountsElements = Array.from(document.querySelectorAll('[data-status-count]'));
    const activeStatuses = new Set();
    const modalElement = document.querySelector('[data-build-modal]');
    const modalBody = modalElement ? modalElement.querySelector('[data-build-modal-body]') : null;
    const modalTitle = modalElement ? modalElement.querySelector('[data-build-modal-title]') : null;
    const buildDetailsCache = new Map();

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function isModalOpen() {
      return modalElement instanceof HTMLElement && !modalElement.hasAttribute('hidden');
    }

    function openBuildModal() {
      if (!(modalElement instanceof HTMLElement)) {
        return;
      }
      modalElement.removeAttribute('hidden');
      document.body.classList.add('pc-build-modal-open');
      const focusTarget = modalElement.querySelector('[data-build-modal-close]');
      if (focusTarget instanceof HTMLElement) {
        focusTarget.focus({ preventScroll: true });
      }
    }

    function closeBuildModal() {
      if (!(modalElement instanceof HTMLElement)) {
        return;
      }
      modalElement.setAttribute('hidden', 'hidden');
      document.body.classList.remove('pc-build-modal-open');
    }

    function setModalContent(html) {
      if (!(modalBody instanceof HTMLElement)) {
        return;
      }
      modalBody.innerHTML = html;
    }

    function setModalLoading() {
      setModalContent('<p class="pc-build-modal__placeholder">Ładowanie danych buildu...</p>');
    }

    function setModalError(message) {
      setModalContent(`<p class="pc-build-modal__notice">${escapeHtml(message)}</p>`);
    }

    function renderBuildModal(payload) {
      if (!(modalBody instanceof HTMLElement)) {
        return;
      }

      const build = payload && typeof payload === 'object' && payload.build && typeof payload.build === 'object'
        ? payload.build
        : {};
      const components = payload && Array.isArray(payload.components) ? payload.components : [];
      const leftovers = payload && Array.isArray(payload.leftovers) ? payload.leftovers : [];
      const statusKey = escapeHtml(typeof build.status === 'string' ? build.status : '');
      const statusLabelRaw = typeof payload === 'object' && payload !== null && typeof payload.status_label === 'string'
        ? payload.status_label
        : '';
      const statusLabel = escapeHtml(statusLabelRaw);
      const editable = !(payload && typeof payload === 'object' && payload.editable === false);

      const reference = escapeHtml(typeof build.reference_code === 'string' ? build.reference_code : '');
      const fallbackTitle = typeof build.id !== 'undefined' ? `Build #${escapeHtml(String(build.id))}` : 'Podgląd buildu';
      if (modalTitle instanceof HTMLElement) {
        modalTitle.textContent = reference !== '' ? reference : fallbackTitle;
      }

      const customer = escapeHtml(typeof build.customer_name === 'string' ? build.customer_name : '');
      const caseReference = escapeHtml(typeof build.case_reference_code === 'string' ? build.case_reference_code : '');
      const profileManufacturer = escapeHtml(typeof build.profile_manufacturer === 'string' ? build.profile_manufacturer : '');
      const profileModel = escapeHtml(typeof build.profile_model === 'string' ? build.profile_model : '');
      const profileLabel = `${profileManufacturer} ${profileModel}`.trim();
      const summary = escapeHtml(typeof build.summary === 'string' ? build.summary : '');
      const caseSummary = escapeHtml(typeof build.case_summary === 'string' ? build.case_summary : '');
      const updatedAt = escapeHtml(typeof build.updated_at === 'string' ? build.updated_at : '');
      const createdAt = escapeHtml(typeof build.created_at === 'string' ? build.created_at : '');

      const metaParts = [];
      const statusBadgeLabel = statusLabel !== '' ? statusLabel : statusKey !== '' ? statusKey : '—';
      metaParts.push(`<span class="pc-builder__status-badge" data-status="${statusKey}">${statusBadgeLabel}</span>`);
      if (customer !== '') {
        metaParts.push(`<span>Klient: <strong>${customer}</strong></span>`);
      }
      if (caseReference !== '') {
        metaParts.push(`<span>Case: <strong>${caseReference}</strong></span>`);
      }
      if (profileLabel !== '') {
        metaParts.push(`<span>Profil: <strong>${profileLabel}</strong></span>`);
      }
      if (updatedAt !== '') {
        metaParts.push(`<time datetime="${updatedAt}">Aktualizacja: ${updatedAt}</time>`);
      }
      if (createdAt !== '' && createdAt !== updatedAt) {
        metaParts.push(`<time datetime="${createdAt}">Utworzono: ${createdAt}</time>`);
      }

      const summaryBlock = summary !== '' ? `<p>${summary}</p>` : caseSummary !== '' ? `<p>${caseSummary}</p>` : '';
      const noticeBlock = editable ? '' : '<p class="pc-build-modal__notice">Build zatwierdzony — edycja jest zablokowana.</p>';
      const metaHtml = metaParts.length > 0 ? `<div class="pc-build-modal__meta">${metaParts.join('')}</div>` : '';

      const componentItems = components.map((component) => {
        const name = escapeHtml(typeof component.item_name === 'string' ? component.item_name : '');
        const quantityValue = escapeHtml(String(component.quantity !== undefined ? component.quantity : 1));
        const referenceCode = escapeHtml(typeof component.item_reference === 'string' ? component.item_reference : '');
        const barcode = escapeHtml(typeof component.item_barcode === 'string' ? component.item_barcode : '');
        const notes = escapeHtml(typeof component.notes === 'string' ? component.notes : '');
        const meta = [];
        if (referenceCode !== '') {
          meta.push(`Kod: ${referenceCode}`);
        }
        if (barcode !== '') {
          meta.push(`EAN: ${barcode}`);
        }
        if (notes !== '') {
          meta.push(notes);
        }
        const metaHtmlItem = meta.length > 0 ? `<small>${meta.join(' • ')}</small>` : '';
        return `<li>${name !== '' ? name : 'Komponent'} × ${quantityValue}${metaHtmlItem}</li>`;
      }).join('');
      const componentsHtml = componentItems !== ''
        ? `<ul class="pc-build-modal__list">${componentItems}</ul>`
        : '<p class="pc-build-modal__placeholder">Brak zarejestrowanych komponentów.</p>';

      const leftoverItems = leftovers.map((leftover) => {
        const name = escapeHtml(typeof leftover.item_name === 'string' ? leftover.item_name : '');
        const quantityValue = escapeHtml(String(leftover.quantity !== undefined ? leftover.quantity : 0));
        const referenceCode = escapeHtml(typeof leftover.item_reference === 'string' ? leftover.item_reference : '');
        const barcode = escapeHtml(typeof leftover.item_barcode === 'string' ? leftover.item_barcode : '');
        const notes = escapeHtml(typeof leftover.notes === 'string' ? leftover.notes : '');
        const profileManufacturerLeft = escapeHtml(typeof leftover.profile_manufacturer === 'string' ? leftover.profile_manufacturer : '');
        const profileModelLeft = escapeHtml(typeof leftover.profile_model === 'string' ? leftover.profile_model : '');
        const profile = `${profileManufacturerLeft} ${profileModelLeft}`.trim();
        const meta = [];
        if (referenceCode !== '') {
          meta.push(`Kod: ${referenceCode}`);
        }
        if (barcode !== '') {
          meta.push(`EAN: ${barcode}`);
        }
        if (profile !== '') {
          meta.push(`Profil: ${profile}`);
        }
        if (notes !== '') {
          meta.push(notes);
        }
        const metaHtmlItem = meta.length > 0 ? `<small>${meta.join(' • ')}</small>` : '';
        return `<li>${name !== '' ? name : 'Pozostałość'} × ${quantityValue}${metaHtmlItem}</li>`;
      }).join('');
      const leftoversHtml = leftoverItems !== ''
        ? `<ul class="pc-build-modal__list">${leftoverItems}</ul>`
        : '<p class="pc-build-modal__placeholder">Brak zarejestrowanych pozostałości.</p>';

      setModalContent(
        `<div class="pc-build-modal__summary">${metaHtml}${summaryBlock}${noticeBlock}</div>`
        + '<div class="pc-build-modal__grid">'
        + `<section class="pc-build-modal__section"><h3>Komponenty</h3>${componentsHtml}</section>`
        + `<section class="pc-build-modal__section"><h3>Pozostałości</h3>${leftoversHtml}</section>`
        + '</div>'
      );
    }

    function requestBuildDetails(buildId) {
      if (!Number.isInteger(buildId) || buildId <= 0) {
        return;
      }
      if (!(modalElement instanceof HTMLElement)) {
        return;
      }

      openBuildModal();
      setModalLoading();

      if (buildDetailsCache.has(buildId)) {
        renderBuildModal(buildDetailsCache.get(buildId));
        return;
      }

      const url = `pc-builds-data.php?id=${encodeURIComponent(String(buildId))}`;
      fetch(url, { headers: { Accept: 'application/json' } })
        .then((response) => response.json().catch(() => null).then((data) => {
          if (!response.ok) {
            let message = 'Nie udało się pobrać danych buildu.';
            if (data && typeof data === 'object') {
              const errorPayload = data.error;
              if (typeof errorPayload === 'string') {
                message = errorPayload;
              } else if (errorPayload && typeof errorPayload === 'object' && typeof errorPayload.message === 'string') {
                message = errorPayload.message;
              }
            }
            throw new Error(message);
          }
          return data;
        }))
        .then((data) => {
          if (!data || typeof data !== 'object') {
            throw new Error('Nie udało się pobrać danych buildu.');
          }
          buildDetailsCache.set(buildId, data);
          renderBuildModal(data);
        })
        .catch((error) => {
          const message = error && typeof error.message === 'string' && error.message !== ''
            ? error.message
            : 'Nie udało się pobrać danych buildu.';
          setModalError(message);
        });
    }

    function bindBuildModalTriggers(root) {
      if (!(root instanceof Document || root instanceof HTMLElement)) {
        return;
      }
      const triggers = root.querySelectorAll('[data-build-modal-trigger]');
      triggers.forEach((trigger) => {
        if (!(trigger instanceof HTMLElement)) {
          return;
        }
        if (trigger.dataset.modalBound === 'true') {
          return;
        }
        trigger.dataset.modalBound = 'true';
        trigger.addEventListener('click', () => {
          const value = parseInt(trigger.getAttribute('data-build-modal-trigger') || '', 10);
          if (!Number.isNaN(value) && value > 0) {
            requestBuildDetails(value);
          }
        });
      });
    }

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

    bindBuildModalTriggers(document);

    if (modalElement instanceof HTMLElement) {
      modalElement.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLElement && (target.hasAttribute('data-build-modal-close') || target === modalElement)) {
          event.preventDefault();
          closeBuildModal();
        }
      });
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isModalOpen()) {
        closeBuildModal();
      }
    });

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
        const parentBuild = form.closest('[data-build-item]');
      if (parentBuild && parentBuild.getAttribute('data-build-editable') === 'false') {
        return;
      }
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
        const parentBuild = form.closest('[data-build-item]');
      if (parentBuild && parentBuild.getAttribute('data-build-editable') === 'false') {
        return;
      }
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