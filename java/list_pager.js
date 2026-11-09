/* Shared list footer: Showing X–Y of Z, Previous, Next, Go to page. */
(function () {
  const PAGE_SIZE = 10;
  const pageByKey = {};

  function isPlaceholderRow(row) {
    if (!row || row.tagName !== 'TR') return true;
    if (row.classList.contains('loading-row')) return true;
    const cells = row.children;
    if (cells.length === 1 && cells[0].hasAttribute('colspan')) return true;
    return false;
  }

  function cardAlreadyPaged(table) {
    const card = table.closest('.card') || table.parentElement;
    if (!card) return false;
    if (card.querySelector('#inventoryOverviewPager, #customersPager, [data-clar-pager]')) return true;
    const controls = card.querySelectorAll('a, button');
    for (let i = 0; i < controls.length; i++) {
      if ((controls[i].textContent || '').trim() === 'Previous') return true;
    }
    return false;
  }

  function sectionHasSearch(table) {
    const section = table.closest('.content-section') || table.closest('.card');
    if (!section) return false;
    const inputs = section.querySelectorAll('input');
    for (let i = 0; i < inputs.length; i++) {
      const hint = ((inputs[i].getAttribute('placeholder') || '') + ' ' + (inputs[i].id || '') + ' ' + (inputs[i].name || '')).toLowerCase();
      if (hint.indexOf('search') !== -1) return true;
    }
    return false;
  }

  function rowSearchText(row) {
    return String(row.getAttribute('data-search') || row.textContent || '').toLowerCase();
  }

  function matchingRows(tbody, pager) {
    const q = String((pager && pager.dataset.query) || '').trim().toLowerCase();
    return Array.from(tbody.querySelectorAll(':scope > tr')).filter(function (row) {
      if (isPlaceholderRow(row)) return false;
      if (row.getAttribute('data-clar-hide') === '1') return false;
      if (!q) return true;
      return rowSearchText(row).indexOf(q) !== -1;
    });
  }

  function paint(tbody, pager) {
    const key = tbody.id || pager.getAttribute('data-clar-pager') || 'list';
    const all = Array.from(tbody.querySelectorAll(':scope > tr')).filter(function (row) {
      return !isPlaceholderRow(row);
    });
    const data = matchingRows(tbody, pager);
    const total = data.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    let page = pageByKey[key] || 1;
    if (page > pages || page < 1) page = 1;
    pageByKey[key] = page;
    const start = (page - 1) * PAGE_SIZE;
    const visible = {};
    data.forEach(function (row, index) {
      visible[index] = row;
      row.style.display = index >= start && index < start + PAGE_SIZE ? '' : 'none';
    });
    all.forEach(function (row) {
      if (data.indexOf(row) === -1) row.style.display = 'none';
    });
    const from = total === 0 ? 0 : start + 1;
    const to = Math.min(total, start + PAGE_SIZE);
    pager.innerHTML =
      '<span class="text-muted small">Showing ' + from + '–' + to + ' of ' + total + '</span>' +
      '<div class="d-flex gap-2 align-items-center">' +
      '<label class="text-muted small mb-0 d-flex align-items-center gap-1">Go to page: ' +
      '<input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm" style="width:64px" aria-label="Go to page">' +
      '</label>' +
      '<button type="button" class="btn btn-outline-secondary btn-sm"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>' +
      '<button type="button" class="btn btn-outline-secondary btn-sm"' + (page >= pages ? ' disabled' : '') + '>Next</button>' +
      '</div>';
    const buttons = pager.querySelectorAll('button');
    const goInput = pager.querySelector('input');
    buttons[0].addEventListener('click', function () {
      if (pageByKey[key] > 1) {
        pageByKey[key] -= 1;
        paint(tbody, pager);
      }
    });
    buttons[1].addEventListener('click', function () {
      if (pageByKey[key] < pages) {
        pageByKey[key] += 1;
        paint(tbody, pager);
      }
    });
    if (goInput) {
      goInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const raw = String(goInput.value || '').trim();
        if (!/^[1-9]\d*$/.test(raw)) return;
        const n = parseInt(raw, 10);
        const latestPages = Math.max(1, Math.ceil(matchingRows(tbody, pager).length / PAGE_SIZE));
        if (n < 1 || n > latestPages) return;
        pageByKey[key] = n;
        paint(tbody, pager);
      });
    }
  }

  function mount(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('.content-section table').forEach(function (table) {
      if (table.closest('.modal, .modal-overlay, .modal-dialog')) return;
      if (table.id === 'auditLogsTable') return;
      const tbody = table.tBodies && table.tBodies[0];
      if (!tbody || tbody.dataset.clarPagerBound === '1') return;
      if (cardAlreadyPaged(table)) return;
      const wrap = table.closest('.table-container') || table;
      if (!wrap.parentElement) return;
      const pager = document.createElement('div');
      pager.className = 'd-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2';
      pager.style.borderTop = '1px solid var(--border-default,#e2e8f0)';
      pager.setAttribute('data-clar-pager', tbody.id || 'list');
      wrap.insertAdjacentElement('afterend', pager);
      tbody.dataset.clarPagerBound = '1';
      const key = tbody.id || pager.getAttribute('data-clar-pager');
      if (!sectionHasSearch(table)) {
        const search = document.createElement('input');
        search.type = 'text';
        search.placeholder = 'Search...';
        search.setAttribute('aria-label', 'Search...');
        search.autocomplete = 'off';
        search.className = 'form-control form-control-sm';
        search.style.cssText = 'max-width:220px;margin:8px 12px;';
        search.setAttribute('data-clar-table-search', '1');
        wrap.parentElement.insertBefore(search, wrap);
        search.addEventListener('input', function () {
          pager.dataset.query = search.value || '';
          const pages = Math.max(1, Math.ceil(matchingRows(tbody, pager).length / PAGE_SIZE));
          if ((pageByKey[key] || 1) > pages) pageByKey[key] = 1;
          paint(tbody, pager);
        });
      }
      new MutationObserver(function () {
        const pages = Math.max(1, Math.ceil(matchingRows(tbody, pager).length / PAGE_SIZE));
        if ((pageByKey[key] || 1) > pages) pageByKey[key] = 1;
        paint(tbody, pager);
      }).observe(tbody, { childList: true });
      paint(tbody, pager);
    });
  }

  window.clarenzonRefreshListPager = function (tbody, resetPage) {
    if (!tbody || !tbody.closest) return;
    const table = tbody.closest('table');
    if (!table) return;
    const wrap = table.closest('.table-container') || table;
    let pager = wrap.nextElementSibling;
    while (pager && !pager.hasAttribute('data-clar-pager')) pager = pager.nextElementSibling;
    if (!pager) return;
    const key = tbody.id || pager.getAttribute('data-clar-pager') || 'list';
    if (resetPage) pageByKey[key] = 1;
    const pages = Math.max(1, Math.ceil(matchingRows(tbody, pager).length / PAGE_SIZE));
    if ((pageByKey[key] || 1) > pages) pageByKey[key] = 1;
    paint(tbody, pager);
  };

  window.clarenzonRevealTableRow = function (row) {
    if (!row || !row.closest) return;
    const tbody = row.closest('tbody');
    const table = row.closest('table');
    if (!tbody || !table) return;
    const wrap = table.closest('.table-container') || table;
    let pager = wrap.nextElementSibling;
    while (pager && !pager.hasAttribute('data-clar-pager')) pager = pager.nextElementSibling;
    if (!pager) return;
    const key = tbody.id || pager.getAttribute('data-clar-pager') || 'list';
    const data = matchingRows(tbody, pager);
    const idx = data.indexOf(row);
    if (idx < 0) return;
    pageByKey[key] = Math.floor(idx / PAGE_SIZE) + 1;
    paint(tbody, pager);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { mount(document); });
  } else {
    mount(document);
  }
  window.clarenzonMountListPagers = mount;
})();
