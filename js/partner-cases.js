document.addEventListener('DOMContentLoaded', () => {
  const archiveTable = document.querySelector('[data-archive-table]') || document.querySelector('.archive-accordion__table');
  if (!archiveTable) return;

  const rows = Array.from(archiveTable.querySelectorAll('[data-archive-row]'));
  if (rows.length === 0) return;

  const searchInput = document.querySelector('[data-archive-search]');
  const summary = document.querySelector('[data-archive-summary]');
  const pagination = document.querySelector('[data-archive-pagination]');
  const pageLabel = document.querySelector('[data-archive-page]');
  const prevBtn = document.querySelector('[data-archive-prev]');
  const nextBtn = document.querySelector('[data-archive-next]');
  const emptyState = document.querySelector('[data-archive-empty]');

  const pageSize = 10;
  let currentPage = 1;
  let filteredRows = [...rows];

  const normalize = (value = '') => value.toString().trim().toLowerCase();

  const updateSummary = (start, end, total) => {
    if (!summary) return;
    const startLabel = total === 0 ? 0 : start + 1;
    summary.textContent = `Wyświetlanie ${startLabel}–${end} z ${total} pozycji`;
  };

  const renderRows = () => {
    rows.forEach((row) => {
      row.style.display = 'none';
    });

    const total = filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (currentPage > totalPages) {
      currentPage = totalPages;
    }

    const start = (currentPage - 1) * pageSize;
    const end = Math.min(start + pageSize, total);
    const visibleRows = filteredRows.slice(start, end);

    visibleRows.forEach((row) => {
      row.style.display = 'table-row';
    });

    if (emptyState) {
      emptyState.hidden = total !== 0;
    }

    if (pagination) {
      pagination.hidden = total <= pageSize;
    }

    if (pageLabel) {
      pageLabel.textContent = total === 0 ? 'Brak wyników' : `Strona ${currentPage} z ${totalPages}`;
    }

    if (prevBtn) {
      prevBtn.disabled = currentPage <= 1;
    }

    if (nextBtn) {
      nextBtn.disabled = currentPage >= totalPages;
    }

    updateSummary(start, end, total);
  };

  const filterRows = () => {
    const query = normalize(searchInput?.value ?? '');
    filteredRows = query === ''
      ? [...rows]
      : rows.filter((row) => {
          const reference = normalize(row.dataset.reference);
          const customer = normalize(row.dataset.customer);
          return reference.includes(query) || customer.includes(query);
        });

    currentPage = 1;
    renderRows();
  };

  searchInput?.addEventListener('input', () => {
    filterRows();
  });

  prevBtn?.addEventListener('click', () => {
    if (currentPage <= 1) return;
    currentPage -= 1;
    renderRows();
  });

  nextBtn?.addEventListener('click', () => {
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    if (currentPage >= totalPages) return;
    currentPage += 1;
    renderRows();
  });

  renderRows();
});