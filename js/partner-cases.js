document.addEventListener('DOMContentLoaded', () => {


  const normalize = (value = '') => value.toString().trim().toLowerCase();

  const createTableController = ({
    tableSelector,
    rowSelector,
    searchSelector,
    summarySelector,
    paginationSelector,
    pageLabelSelector,
    prevSelector,
    nextSelector,
    emptySelector,
    pageSize,
    searchableFields,
  }) => {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    const rows = Array.from(table.querySelectorAll(rowSelector));
    if (rows.length === 0) return;

    const searchInput = document.querySelector(searchSelector);
    const summary = document.querySelector(summarySelector);
    const pagination = document.querySelector(paginationSelector);
    const pageLabel = document.querySelector(pageLabelSelector);
    const prevBtn = document.querySelector(prevSelector);
    const nextBtn = document.querySelector(nextSelector);
    const emptyState = document.querySelector(emptySelector);

    let currentPage = 1;
    let filteredRows = [...rows];

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
        : rows.filter((row) => searchableFields.some((field) => normalize(row.dataset[field]).includes(query)));

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
  };

  createTableController({
    tableSelector: '[data-archive-table], .archive-accordion__table',
    rowSelector: '[data-archive-row]',
    searchSelector: '[data-archive-search]',
    summarySelector: '[data-archive-summary]',
    paginationSelector: '[data-archive-pagination]',
    pageLabelSelector: '[data-archive-page]',
    prevSelector: '[data-archive-prev]',
    nextSelector: '[data-archive-next]',
    emptySelector: '[data-archive-empty]',
    pageSize: 10,
    searchableFields: ['reference', 'customer'],
  });

  createTableController({
    tableSelector: '[data-active-table]',
    rowSelector: '[data-active-row]',
    searchSelector: '[data-active-search]',
    summarySelector: '[data-active-summary]',
    paginationSelector: '[data-active-pagination]',
    pageLabelSelector: '[data-active-page]',
    prevSelector: '[data-active-prev]',
    nextSelector: '[data-active-next]',
    emptySelector: '[data-active-empty]',
    pageSize: 5,
    searchableFields: ['reference', 'customer', 'summary'],
  });
});