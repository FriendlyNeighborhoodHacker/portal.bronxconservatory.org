// BCM Portal application JavaScript

// Top bar: avatar dropdown + mobile hamburger panel.
function setupTopNav() {
  var avatarBtn = document.getElementById('avatarToggle');
  var avatarMenu = document.getElementById('avatarMenu');
  var navToggle = document.getElementById('navToggle');
  var mobileNav = document.getElementById('mobileNav');

  function setAvatar(open) {
    if (!avatarMenu) return;
    avatarMenu.classList.toggle('hidden', !open);
    avatarMenu.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (avatarBtn) avatarBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function setMobileNav(open) {
    if (!mobileNav) return;
    mobileNav.classList.toggle('open', open);
    if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  if (avatarBtn && avatarMenu) {
    avatarBtn.addEventListener('click', function (e) {
      e.preventDefault();
      setAvatar(avatarMenu.classList.contains('hidden'));
    });
    document.addEventListener('click', function (e) {
      if (!avatarBtn.contains(e.target) && !avatarMenu.contains(e.target)) {
        setAvatar(false);
      }
    });
  }

  if (navToggle && mobileNav) {
    navToggle.addEventListener('click', function () {
      setMobileNav(!mobileNav.classList.contains('open'));
    });
  }

  // Submenu bars: closed on every page load; the owning top-nav item
  // (e.g. Admin) toggles its bar open/closed instead of navigating.
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest ? e.target.closest('[data-subnav-toggle]') : null;
    if (!toggle) return;
    var subnav = document.getElementById(toggle.getAttribute('data-subnav-toggle'));
    if (!subnav) return; // no bar on this page — follow the link normally
    e.preventDefault();
    var open = subnav.classList.contains('hidden');
    subnav.classList.toggle('hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      setAvatar(false);
      setMobileNav(false);
    }
  });
}

// Generic modal open/close. Openers declare data-modal-open="modalId";
// modals close via their .close button, a backdrop click, or Escape.
// Feature-specific submit logic stays with the feature's own script.
function setupModals() {
  document.addEventListener('click', function (e) {
    var opener = e.target.closest ? e.target.closest('[data-modal-open]') : null;
    if (opener) {
      var modal = document.getElementById(opener.getAttribute('data-modal-open'));
      if (modal) {
        e.preventDefault();
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        var firstInput = modal.querySelector('input:not([type=hidden]), select, textarea');
        if (firstInput) firstInput.focus();
      }
      return;
    }

    var closer = e.target.closest ? e.target.closest('.modal .close, .modal [data-modal-close]') : null;
    if (closer) {
      var closeModal = closer.closest('.modal');
      if (closeModal) {
        e.preventDefault();
        closeModal.classList.add('hidden');
        closeModal.setAttribute('aria-hidden', 'true');
      }
      return;
    }

    // Backdrop click: the .modal element itself is the dimmed backdrop.
    if (e.target.classList && e.target.classList.contains('modal')) {
      e.target.classList.add('hidden');
      e.target.setAttribute('aria-hidden', 'true');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal:not(.hidden)').forEach(function (modal) {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
      });
    }
  });
}

// Combobox typeahead (ported from the cub_scouts parent modal). Wire up with:
//   bcmTypeahead({ input, hidden, listEl, url, minChars })
// where url returns {ok:true, items:[{id, label}]} for ?q=<term>.
// Selecting an option sets hidden.value = id and input.value = label; typing
// again clears the hidden id so submits always carry a real selection.
function bcmTypeahead(opts) {
  var input = typeof opts.input === 'string' ? document.getElementById(opts.input) : opts.input;
  var hidden = typeof opts.hidden === 'string' ? document.getElementById(opts.hidden) : opts.hidden;
  var listEl = typeof opts.listEl === 'string' ? document.getElementById(opts.listEl) : opts.listEl;
  if (!input || !hidden || !listEl) return;
  var minChars = opts.minChars || 1;
  var items = [];
  var highlight = -1;
  var timer = null;

  function close() {
    listEl.style.display = 'none';
    input.setAttribute('aria-expanded', 'false');
    highlight = -1;
  }

  function render() {
    listEl.innerHTML = '';
    if (!items.length) { close(); return; }
    items.forEach(function (it, idx) {
      var opt = document.createElement('div');
      opt.className = 'typeahead-option' + (idx === highlight ? ' highlight' : '');
      opt.setAttribute('role', 'option');
      opt.id = listEl.id + '_opt_' + idx;
      opt.textContent = it.label;
      opt.addEventListener('mousedown', function (e) { e.preventDefault(); });
      opt.addEventListener('click', function () { select(idx); });
      listEl.appendChild(opt);
    });
    listEl.style.display = 'block';
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-activedescendant', highlight >= 0 ? listEl.id + '_opt_' + highlight : '');
  }

  function select(idx) {
    var it = items[idx];
    if (!it) return;
    hidden.value = it.id;
    input.value = it.label;
    close();
    if (typeof opts.onSelect === 'function') opts.onSelect(it);
  }

  function search(q) {
    fetch(opts.url + (opts.url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.ok) { items = []; render(); return; }
        items = json.items || [];
        highlight = items.length ? 0 : -1;
        render();
      })
      .catch(function () { items = []; render(); });
  }

  input.addEventListener('input', function () {
    hidden.value = '';
    var q = input.value.trim();
    clearTimeout(timer);
    if (q.length < minChars) { items = []; close(); return; }
    timer = setTimeout(function () { search(q); }, 200);
  });

  input.addEventListener('keydown', function (e) {
    if (listEl.style.display !== 'block') return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlight = (highlight + 1) % items.length;
      render();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlight = (highlight - 1 + items.length) % items.length;
      render();
    } else if (e.key === 'Enter') {
      if (highlight >= 0) { e.preventDefault(); select(highlight); }
    } else if (e.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('blur', function () {
    setTimeout(close, 120); // let option clicks land first
  });
}

// Semester Schedule / weekly calendar grid: condense columns as the screen
// narrows, then paginate them when even condensed columns can't fit.
//
//   fit_normal  = floor((width - 90) / 130)   all columns, normal density
//   fit_compact = floor((width - 72) / 92)    all columns, .compact
//   otherwise                                 .compact + paging, where pages
//   pack whole locations greedily; a location splits across pages only when
//   it alone exceeds a page ("Bronx CC (1 of 2)").
//
// On narrow screens (perPage <= 2) the pager's label becomes a column
// <select> so a specific teacher is one tap away. The first visible column
// index is kept in sessionStorage so the modals' post-save reloads return to
// the same page.
function setupScheduleGrid() {
  var widget = document.querySelector('.schedule-widget');
  if (!widget) return;
  var table = widget.querySelector('table[data-columns]');
  var pagerEl = widget.querySelector('.schedule-pager');
  if (!table || !pagerEl) return;

  var columns;
  try {
    columns = JSON.parse(table.getAttribute('data-columns')) || [];
  } catch (e) {
    return;
  }
  if (!columns.length) return;

  var GUTTER_NORMAL = 90, COL_NORMAL = 130;
  var GUTTER_COMPACT = 72, COL_COMPACT = 92;
  var storageKey = 'scheduleGrid:' + window.location.pathname;
  var pages = [];         // [{cols: [indexes], label: 'Location (1 of 2)'}]
  var currentPage = 0;
  var resizeTimer = null;

  // Location groups in column order.
  var groups = [];
  columns.forEach(function (col, i) {
    var lastGroup = groups[groups.length - 1];
    if (!lastGroup || lastGroup.key !== col.loc) {
      groups.push({ key: col.loc, name: col.locName, cols: [] });
    }
    groups[groups.length - 1].cols.push(i);
  });

  function setCompact(on) {
    if (table.classList.contains('compact') === on) return;
    table.classList.toggle('compact', on);
    // Swap teacher headers and student names to their short forms (and back).
    table.querySelectorAll('th.grid-teacher').forEach(function (th) {
      if (!th.dataset.full) th.dataset.full = th.textContent;
      th.textContent = on ? (th.dataset.short || th.dataset.full) : th.dataset.full;
    });
    // Each commitment carries its own short name (a cell can hold more than
    // one when a teacher is double-booked); fall back to the cell's.
    table.querySelectorAll('td.grid-cell .cell-student').forEach(function (el) {
      var owner = el.closest('.cell-item') || el.closest('td');
      if (!el.dataset.full) el.dataset.full = el.textContent;
      var shortName = owner && (owner.dataset.short || owner.dataset.studentShort);
      el.textContent = on && shortName ? shortName : el.dataset.full;
    });
  }

  function buildPages(perPage) {
    var built = [];
    groups.forEach(function (group) {
      if (group.cols.length > perPage) {
        // A single location wider than a page: split it.
        var parts = Math.ceil(group.cols.length / perPage);
        for (var p = 0; p < parts; p++) {
          built.push({
            cols: group.cols.slice(p * perPage, (p + 1) * perPage),
            label: group.name + ' (' + (p + 1) + ' of ' + parts + ')',
            splitPart: p + 1,
            splitOf: parts,
            names: [group.name]
          });
        }
        return;
      }
      var open = built[built.length - 1];
      if (!open || open.splitOf || open.cols.length + group.cols.length > perPage) {
        built.push({ cols: [], label: '', names: [] });
        open = built[built.length - 1];
      }
      open.cols = open.cols.concat(group.cols);
      open.names.push(group.name);
      open.label = open.names.join(' · ');
    });
    return built;
  }

  function applyVisibility(visibleCols) {
    var visible = {};
    visibleCols.forEach(function (i) { visible[i] = true; });
    table.querySelectorAll('[data-col]').forEach(function (el) {
      el.classList.toggle('col-hidden', !visible[el.getAttribute('data-col')]);
    });
    // Location header row: recompute colspans for this page.
    var page = pages[currentPage];
    table.querySelectorAll('th.grid-loc').forEach(function (th) {
      var key = th.getAttribute('data-loc-key');
      var count = 0;
      visibleCols.forEach(function (i) { if (columns[i].loc === key) count++; });
      th.classList.toggle('col-hidden', count === 0);
      if (count > 0) {
        th.colSpan = count;
        var label = th.getAttribute('data-label');
        if (page && page.splitOf && columns[visibleCols[0]].loc === key) {
          label += ' (' + page.splitPart + ' of ' + page.splitOf + ')';
        }
        th.textContent = label;
      }
    });
  }

  function renderPager() {
    pagerEl.innerHTML = '';
    pagerEl.classList.remove('hidden');

    var prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'button';
    prev.textContent = '◀';
    prev.setAttribute('aria-label', 'Previous page');
    prev.disabled = currentPage === 0;
    prev.addEventListener('click', function () { goToPage(currentPage - 1); });

    var next = document.createElement('button');
    next.type = 'button';
    next.className = 'button';
    next.textContent = '▶';
    next.setAttribute('aria-label', 'Next page');
    next.disabled = currentPage >= pages.length - 1;
    next.addEventListener('click', function () { goToPage(currentPage + 1); });

    pagerEl.appendChild(prev);

    if (useSelectPager) {
      // Phone-sized: jump straight to a teacher.
      var select = document.createElement('select');
      select.setAttribute('aria-label', 'Jump to teacher');
      columns.forEach(function (col, i) {
        var option = document.createElement('option');
        option.value = i;
        option.textContent = col.teacher + ' — ' + col.locName;
        select.appendChild(option);
      });
      select.value = String(pages[currentPage].cols[0]);
      select.addEventListener('change', function () {
        var target = parseInt(select.value, 10);
        for (var p = 0; p < pages.length; p++) {
          if (pages[p].cols.indexOf(target) >= 0) { goToPage(p); return; }
        }
      });
      pagerEl.appendChild(select);
    } else {
      var label = document.createElement('span');
      label.className = 'schedule-pager-label';
      label.textContent = 'Page ' + (currentPage + 1) + ' of ' + pages.length
        + ' — ' + pages[currentPage].label;
      pagerEl.appendChild(label);
    }

    pagerEl.appendChild(next);
  }

  var useSelectPager = false; // phones: jump-to-teacher dropdown instead of a label

  function goToPage(p) {
    currentPage = Math.max(0, Math.min(pages.length - 1, p));
    try {
      sessionStorage.setItem(storageKey, String(pages[currentPage].cols[0]));
    } catch (e) { /* private browsing */ }
    applyVisibility(pages[currentPage].cols);
    renderPager();
  }

  function layout() {
    var width = widget.clientWidth || document.documentElement.clientWidth;
    var fitNormal = Math.floor((width - GUTTER_NORMAL) / COL_NORMAL);
    var fitCompact = Math.floor((width - GUTTER_COMPACT) / COL_COMPACT);

    if (columns.length <= fitNormal) {
      setCompact(false);
      pages = [{ cols: columns.map(function (c, i) { return i; }), label: '' }];
      currentPage = 0;
      applyVisibility(pages[0].cols);
      pagerEl.classList.add('hidden');
      return;
    }
    if (columns.length <= fitCompact) {
      setCompact(true);
      pages = [{ cols: columns.map(function (c, i) { return i; }), label: '' }];
      currentPage = 0;
      applyVisibility(pages[0].cols);
      pagerEl.classList.add('hidden');
      return;
    }

    // Condensed AND paged.
    setCompact(true);
    var perPage = Math.max(1, fitCompact);
    useSelectPager = width <= 768; // phones jump by teacher, not by page
    pages = buildPages(perPage);

    // Stay on (or restore to) the page holding the anchor column.
    var anchor = null;
    try {
      var stored = sessionStorage.getItem(storageKey);
      if (stored !== null) anchor = parseInt(stored, 10);
    } catch (e) { /* ignore */ }
    if (anchor === null && pages[currentPage]) anchor = pages[currentPage].cols[0];
    currentPage = 0;
    if (anchor !== null) {
      for (var p = 0; p < pages.length; p++) {
        if (pages[p].cols.indexOf(anchor) >= 0) { currentPage = p; break; }
      }
    }
    applyVisibility(pages[currentPage].cols);
    renderPager();
  }

  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(layout, 150);
  });
  layout();
}

// Utility functions
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Form validation helpers
function validateEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

function validatePassword(password) {
  return password.length >= 8;
}

// Auto-submit forms with debouncing
function setupAutoSubmit() {
  const forms = document.querySelectorAll('form[data-auto-submit]');
  
  forms.forEach(form => {
    const inputs = form.querySelectorAll('input, select');
    let timeout;
    
    inputs.forEach(input => {
      input.addEventListener('input', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }, 600);
      });
    });
  });
}

// Warn before navigating away from a form with unsaved changes.
// Opt in by adding data-warn-unsaved to a form. Submitting that form does not
// warn; other forms on the page (e.g. a photo upload that reloads the page)
// still warn since they would discard the edits. Forms whose submission makes
// the edits irrelevant (e.g. "Delete") can opt out with data-skip-unsaved-warning.
function setupUnsavedChangesWarning() {
  var forms = Array.prototype.slice.call(document.querySelectorAll('form[data-warn-unsaved]'));
  if (!forms.length) return;

  function snapshot(form) {
    var parts = [];
    form.querySelectorAll('input, select, textarea').forEach(function(el) {
      if (el.type === 'checkbox' || el.type === 'radio') {
        parts.push(el.name + '=' + (el.checked ? '1' : '0'));
      } else if (el.type !== 'file') {
        parts.push(el.name + '=' + el.value);
      }
    });
    return parts.join(' ');
  }

  var initial = forms.map(snapshot);
  var suppress = false;

  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (form.hasAttribute('data-warn-unsaved') || form.hasAttribute('data-skip-unsaved-warning')) {
      suppress = true;
    }
  });

  window.addEventListener('beforeunload', function(e) {
    if (suppress) return;
    var dirty = forms.some(function(form, i) { return snapshot(form) !== initial[i]; });
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  setupTopNav();
  setupModals();
  setupScheduleGrid();
  setupAutoSubmit();
  setupUnsavedChangesWarning();
  
  // Focus first input on auth pages
  if (document.body.classList.contains('auth')) {
    const firstInput = document.querySelector('input[type="email"], input[type="text"]');
    if (firstInput) {
      firstInput.focus();
    }
  }
  
  // Confirm delete actions
  const deleteButtons = document.querySelectorAll('[data-confirm]');
  deleteButtons.forEach(button => {
    button.addEventListener('click', function(e) {
      const message = this.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });
});
