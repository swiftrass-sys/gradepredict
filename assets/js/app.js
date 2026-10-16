/* ---------------- Theme (dark mode) ----------------
   Applied immediately (not waiting for DOMContentLoaded) so the toggle
   feels instant; persisted in localStorage since it's a pure device/browser
   display preference, not app data. */
(function initTheme() {
  // Dark mode belongs to the signed-in app only. Login, register, the landing page and
  // other public pages always stay light, whatever the saved choice is.
  var inApp = !!document.querySelector('.app-shell');
  var saved = null;
  try { saved = localStorage.getItem('gp-theme'); } catch (e) {}
  if (!inApp) { document.documentElement.removeAttribute('data-theme'); return; }
  if (saved === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  }
})();

function setupThemeToggle() {
  var toggle = document.getElementById('darkModeToggle');
  if (!toggle) return;
  toggle.checked = document.documentElement.getAttribute('data-theme') === 'dark';
  toggle.addEventListener('change', function () {
    if (toggle.checked) {
      document.documentElement.setAttribute('data-theme', 'dark');
      localStorage.setItem('gp-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-theme');
      localStorage.setItem('gp-theme', 'light');
    }
  });
}

/* Year-level fields only offer the years the chosen program has (a 6-month course has just Year 1). */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-years-from]').forEach(function (field) {
    var prog = document.getElementById(field.getAttribute('data-years-from'));
    if (!prog) return;
    function apply() {
      var opt = prog.options[prog.selectedIndex];
      var max = opt && opt.dataset.years ? parseInt(opt.dataset.years, 10) : 5;
      if (field.tagName === 'SELECT') {
        Array.prototype.forEach.call(field.options, function (o) {
          var hide = o.value && parseInt(o.value, 10) > max;
          o.hidden = hide; o.disabled = hide;
        });
        if (field.selectedOptions[0] && field.selectedOptions[0].disabled) field.value = String(max);
      } else {
        field.max = max;
        if (parseInt(field.value, 10) > max) field.value = max;
      }
    }
    prog.addEventListener('change', apply); apply();
  });
});

document.addEventListener('DOMContentLoaded', function () {
  // Mobile sidebar toggle
  var toggleBtn = document.getElementById('sidebarToggle');
  var sidebar = document.querySelector('.sidebar');
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  // Password show/hide toggles: any button with [data-toggle-pass="inputId"]
  document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
    });
  });

  // Range sliders: show live value next to them
  document.querySelectorAll('input[type=range][data-live]').forEach(function (range) {
    var out = document.getElementById(range.getAttribute('data-live'));
    var update = function () { if (out) out.textContent = range.value + '%'; };
    range.addEventListener('input', update);
    update();
  });
});
/**
 * components.js — small, dependency-free UI components shared by every page.
 * Each component only activates if its markup/attributes are present, so
 * pages that don't use a given component pay no cost.
 */

/* ---------------- Toast notifications ---------------- */
const Toast = (function () {
  let viewport = null;
  function ensureViewport() {
    if (!viewport) {
      viewport = document.createElement('div');
      viewport.className = 'toast-viewport';
      document.body.appendChild(viewport);
    }
    return viewport;
  }
  function show(message, type = 'info', title = null) {
    const vp = ensureViewport();
    const icons = { success: '✓', error: '!', warning: '!', info: 'i' };
    const titles = { success: 'Success', error: 'Something went wrong', warning: 'Heads up', info: 'Notice' };
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML =
      '<span class="toast-ic">' + (icons[type] || icons.info) + '</span>' +
      '<div class="toast-body"><div class="toast-title">' + (title || titles[type] || titles.info) + '</div><div>' + message + '</div></div>' +
      '<button class="toast-close" aria-label="Dismiss">✕</button>';
    vp.appendChild(el);
    const remove = () => {
      el.classList.add('leaving');
      setTimeout(() => el.remove(), 200);
    };
    el.querySelector('.toast-close').addEventListener('click', remove);
    setTimeout(remove, 5000);
  }
  return { show };
})();

/* ---------------- Confirmation dialog ---------------- */
const ConfirmDialog = (function () {
  let overlay, titleEl, msgEl, confirmBtn, cancelBtn, pendingAction = null;

  function ensure() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML =
      '<div class="modal-box" role="alertdialog" aria-modal="true">' +
      '  <div class="modal-icon">⚠️</div>' +
      '  <h3 id="cdTitle">Are you sure?</h3>' +
      '  <p id="cdMsg">This action cannot be undone.</p>' +
      '  <div class="modal-actions">' +
      '    <button type="button" class="btn btn-outline" id="cdCancel">Cancel</button>' +
      '    <button type="button" class="btn btn-danger" id="cdConfirm">Confirm</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(overlay);
    titleEl = overlay.querySelector('#cdTitle');
    msgEl = overlay.querySelector('#cdMsg');
    confirmBtn = overlay.querySelector('#cdConfirm');
    cancelBtn = overlay.querySelector('#cdCancel');
    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    confirmBtn.addEventListener('click', () => {
      const action = pendingAction;
      close();
      if (action) action();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  }
  function open(opts) {
    ensure();
    titleEl.textContent = opts.title || 'Are you sure?';
    msgEl.textContent = opts.message || 'This action cannot be undone.';
    confirmBtn.textContent = opts.confirmLabel || 'Confirm';
    confirmBtn.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
    pendingAction = opts.onConfirm;
    overlay.classList.add('open');
  }
  function close() {
    if (overlay) overlay.classList.remove('open');
    pendingAction = null;
  }
  return { open, close };
})();

/* ---------------- Command palette (Ctrl+K / Cmd+K) ---------------- */
const CommandPalette = (function () {
  let overlay, input, results, items = [], activeIndex = 0;

  function ensure() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'cmdk-overlay';
    overlay.innerHTML =
      '<div class="cmdk-box">' +
      '  <div class="cmdk-input-row"><span>🔎</span><input type="text" placeholder="Jump to a page, student, or subject…" id="cmdkInput"><span class="cmdk-hint">Esc</span></div>' +
      '  <div class="cmdk-results" id="cmdkResults"></div>' +
      '</div>';
    document.body.appendChild(overlay);
    input = overlay.querySelector('#cmdkInput');
    results = overlay.querySelector('#cmdkResults');
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    input.addEventListener('input', () => render(input.value));
    input.addEventListener('keydown', onKey);
  }

  function collectNavItems() {
    return Array.from(document.querySelectorAll('.sidebar .nav-item')).map((a) => ({
      type: 'nav', label: a.textContent.trim(), href: a.getAttribute('href'), icon: '📄',
    }));
  }

  async function fetchDataMatches(query) {
    const endpoint = document.body.getAttribute('data-search-endpoint');
    if (!endpoint || query.length < 2) return [];
    try {
      const res = await fetch(endpoint + '?q=' + encodeURIComponent(query));
      if (!res.ok) return [];
      const data = await res.json();
      return (data.results || []).map((r) => ({ type: r.type, label: r.label, href: r.href, icon: r.icon || '👤' }));
    } catch (e) { return []; }
  }

  function renderList(list) {
    if (!list.length) {
      results.innerHTML = '<div class="cmdk-empty">No matches. Try another search.</div>';
      return;
    }
    let byGroup = {};
    list.forEach((it) => { (byGroup[it.type] = byGroup[it.type] || []).push(it); });
    let html = '';
    Object.keys(byGroup).forEach((group) => {
      html += '<div class="cmdk-group-label">' + group + '</div>';
      byGroup[group].forEach((it, idx) => {
        html += '<div class="cmdk-item" data-href="' + it.href + '"><span class="cmdk-ic">' + it.icon + '</span>' + it.label + '</div>';
      });
    });
    results.innerHTML = html;
    results.querySelectorAll('.cmdk-item').forEach((el) => {
      el.addEventListener('click', () => { window.location.href = el.getAttribute('data-href'); });
    });
    items = Array.from(results.querySelectorAll('.cmdk-item'));
    activeIndex = 0;
    highlight();
  }

  async function render(query) {
    const navMatches = collectNavItems().filter((n) => n.label.toLowerCase().includes(query.toLowerCase()));
    const dataMatches = await fetchDataMatches(query);
    renderList(query ? navMatches.concat(dataMatches) : navMatches);
  }

  function highlight() {
    items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
    if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
  }

  function onKey(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); highlight(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (items[activeIndex]) items[activeIndex].click(); }
    else if (e.key === 'Escape') close();
  }

  function open() {
    ensure();
    overlay.classList.add('open');
    input.value = '';
    render('');
    setTimeout(() => input.focus(), 20);
  }
  function close() { if (overlay) overlay.classList.remove('open'); }

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      open();
    }
  });

  return { open, close };
})();

/* ---------------- Autocomplete search bar ---------------- */
function initAutocomplete() {
  document.querySelectorAll('[data-autocomplete]').forEach((input) => {
    const endpoint = input.getAttribute('data-autocomplete');
    const wrap = input.closest('.autocomplete-wrap');
    if (!wrap) return;
    let box = wrap.querySelector('.autocomplete-results');
    if (!box) {
      box = document.createElement('div');
      box.className = 'autocomplete-results';
      wrap.appendChild(box);
    }
    let debounceTimer;
    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const q = input.value.trim();
      if (q.length < 2) { box.classList.remove('open'); return; }
      debounceTimer = setTimeout(async () => {
        try {
          const res = await fetch(endpoint + '?q=' + encodeURIComponent(q));
          const data = await res.json();
          renderResults(box, data.results || []);
        } catch (e) { /* silent */ }
      }, 220);
    });
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) box.classList.remove('open'); });
  });

  function renderResults(box, list) {
    if (!list.length) {
      box.innerHTML = '<div class="ac-empty">No matches found.</div>';
      box.classList.add('open');
      return;
    }
    let byGroup = {};
    list.forEach((r) => { (byGroup[r.type] = byGroup[r.type] || []).push(r); });
    let html = '';
    Object.keys(byGroup).forEach((group) => {
      html += '<div class="ac-group">' + group + '</div>';
      byGroup[group].forEach((r) => {
        html += '<a class="ac-item" href="' + r.href + '"><span>' + (r.icon || '👤') + '</span> ' + r.label + '</a>';
      });
    });
    box.innerHTML = html;
    box.classList.add('open');
  }
}

/* ---------------- Infinite scroll ----------------
   The append target (e.g. a <tbody>) is marked with data-infinite-container.
   The page must also provide its own sentinel/loading elements OUTSIDE of
   any table (class="scroll-sentinel" / class="infinite-loading"), since
   programmatically inserting new elements as siblings of a <tbody> would
   produce invalid, unreliable table markup. */
function initInfiniteScroll() {
  const container = document.querySelector('[data-infinite-container]');
  const sentinel = document.querySelector('.scroll-sentinel');
  const loadingEl = document.querySelector('.infinite-loading');
  if (!container || !sentinel) return;
  const endpoint = container.getAttribute('data-infinite-endpoint');
  let page = 2;
  let loading = false;
  let done = false;

  const observer = new IntersectionObserver(async (entries) => {
    if (!entries[0].isIntersecting || loading || done) return;
    loading = true;
    if (loadingEl) { loadingEl.style.display = 'block'; loadingEl.textContent = 'Loading more…'; }
    try {
      const res = await fetch(endpoint + '?page=' + page);
      const data = await res.json();
      if (data.html && data.html.trim() !== '') {
        container.insertAdjacentHTML('beforeend', data.html);
        page++;
      }
      if (!data.has_more) {
        done = true;
        observer.disconnect();
        if (loadingEl) loadingEl.textContent = 'You’ve reached the end.';
        else sentinel.remove();
      }
    } catch (e) {
      if (loadingEl) loadingEl.textContent = 'Could not load more — check your connection.';
    } finally {
      loading = false;
      if (!done && loadingEl) loadingEl.style.display = 'none';
    }
  }, { rootMargin: '200px' });

  observer.observe(sentinel);
}

/* ---------------- Stepper (multi-step forms) ---------------- */
function initStepper() {
  const stepper = document.querySelector('[data-stepper]');
  if (!stepper) return;
  const dots = Array.from(stepper.querySelectorAll('.step-dot-wrap'));
  const lines = Array.from(stepper.querySelectorAll('.step-line'));
  const panels = Array.from(document.querySelectorAll('.stepper-panel'));
  let current = 0;

  function show(index) {
    panels.forEach((p, i) => p.classList.toggle('active', i === index));
    dots.forEach((d, i) => {
      d.classList.toggle('active', i === index);
      d.classList.toggle('done', i < index);
    });
    lines.forEach((l, i) => l.classList.toggle('done', i < index));
    current = index;
  }

  function validatePanel(index) {
    const panel = panels[index];
    const fields = panel.querySelectorAll('input[required], select[required]');
    for (const f of fields) {
      if (!f.checkValidity()) { f.reportValidity(); return false; }
    }
    return true;
  }

  document.querySelectorAll('[data-step-next]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!validatePanel(current)) return;
      if (current < panels.length - 1) show(current + 1);
    });
  });
  document.querySelectorAll('[data-step-prev]').forEach((btn) => {
    btn.addEventListener('click', () => { if (current > 0) show(current - 1); });
  });

  show(0);
}

/* ---------------- Button loading state on submit ---------------- */
function initFormLoadingStates() {
  document.querySelectorAll('form').forEach((form) => {
    if (form.hasAttribute('data-no-loading')) return;
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      const btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.classList.contains('is-loading')) {
        btn.dataset.originalText = btn.innerHTML;
        btn.classList.add('is-loading');
        btn.innerHTML = '<span class="spinner"></span> Please wait…';
      }
    });
  });
}

/* ---------------- Skeleton reveal ---------------- */
function initSkeletons() {
  const skeletons = document.querySelectorAll('.skeleton');
  if (!skeletons.length) return;
  window.addEventListener('load', () => {
    setTimeout(() => skeletons.forEach((s) => s.classList.remove('skeleton')), 350);
  });
}

/* ---------------- Flash message -> toast bridge ----------------
   Pages already render a server-side `.alert` banner for accessibility/
   no-JS fallback. On load we lift that content into a toast and hide the
   static banner, so users get consistent toast-style feedback everywhere
   without every page needing to change how it reports flash messages. */
function initFlashToast() {
  document.querySelectorAll('.alert[data-flash]').forEach((el) => {
    const type = el.getAttribute('data-flash');
    const copy = el.cloneNode(true);
    copy.querySelectorAll('.alert-ic').forEach((ic) => ic.remove());   // the toast draws its own icon
    Toast.show(copy.textContent.trim(), type === 'success' ? 'success' : 'error');
    el.style.display = 'none';
  });
}

/* ---------------- Wire up delete/confirm triggers ----------------
   Works on <a data-confirm="..."> links and on <button data-confirm="...">
   submit buttons inside a form — the dialog intercepts the click, and only
   navigates / submits after the user confirms. */
function initConfirmTriggers() {
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      const message = el.getAttribute('data-confirm');
      const form = el.closest('form');
      ConfirmDialog.open({
        title: el.getAttribute('data-confirm-title') || 'Please confirm',
        message,
        confirmLabel: el.getAttribute('data-confirm-label') || 'Yes, continue',
        onConfirm: () => {
          if (form) form.submit();
          else if (el.tagName === 'A') window.location.href = el.getAttribute('href');
        },
      });
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  initAutocomplete();
  initInfiniteScroll();
  initStepper();
  initFormLoadingStates();
  initSkeletons();
  initFlashToast();
  setupThemeToggle();
  initConfirmTriggers();

  document.querySelectorAll('[data-cmdk-trigger]').forEach((btn) => {
    btn.addEventListener('click', () => CommandPalette.open());
  });
});

/* ---------------- Smart weather card ----------------
   Key info at a glance: icon, temperature, one-line condition. Uses the
   browser's geolocation + Open-Meteo (no API key needed). If location
   isn't available/granted, it fails quietly into a clear empty state
   instead of a broken widget — never overwhelms the card with detail. */
var WMO_ICONS = {
  0: 'Clear sky', 1: 'Mostly clear', 2: 'Partly cloudy', 3: 'Overcast',
  45: 'Foggy', 48: 'Foggy',
  51: 'Light drizzle', 53: 'Drizzle', 55: 'Heavy drizzle',
  61: 'Light rain', 63: 'Rain', 65: 'Heavy rain',
  71: 'Light snow', 73: 'Snow', 75: 'Heavy snow',
  80: 'Rain showers', 81: 'Rain showers', 82: 'Violent showers',
  95: 'Thunderstorm', 96: 'Thunderstorm', 99: 'Thunderstorm',
};
function initWeatherCard() {
  var el = document.getElementById('smartWeatherCard');
  if (!el) return;
  var unit = el.getAttribute('data-unit') === 'f' ? 'fahrenheit' : 'celsius';

  function render(temp, code, place) {
    var info = WMO_ICONS[code] || 'Weather';
    el.classList.remove('loading', 'empty-state-card');
    el.innerHTML =
      '<div><div class="wc-temp">' + Math.round(temp) + '°' + (unit === 'fahrenheit' ? 'F' : 'C') + '</div>' +
      '<div class="wc-meta">' + info + '</div></div>' +
      (place ? '<div class="wc-place">' + place + '</div>' : '');
  }
  function showEmpty() {
    el.classList.remove('loading');
    el.classList.add('empty-state-card');
    el.innerHTML = '<div class="wc-meta">Weather unavailable. Allow location access to see it here.</div>';
  }

  // Timeout + retry with exponential backoff: a flaky mobile connection
  // shouldn't leave the card stuck on "Loading weather…" forever, and a
  // transient failure shouldn't give up after a single try.
  function fetchWithTimeout(url, ms) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, ms);
    return fetch(url, { signal: controller.signal }).finally(function () { clearTimeout(timer); });
  }
  function fetchWithRetry(url, attempt) {
    attempt = attempt || 0;
    return fetchWithTimeout(url, 5000)
      .then(function (r) { if (!r.ok) throw new Error('bad status ' + r.status); return r.json(); })
      .catch(function (err) {
        if (attempt >= 2) throw err; // 3 tries total (attempt 0, 1, 2)
        var delay = Math.pow(2, attempt) * 500; // 500ms, 1000ms
        return new Promise(function (resolve) { setTimeout(resolve, delay); })
          .then(function () { return fetchWithRetry(url, attempt + 1); });
      });
  }

  if (!navigator.geolocation) { showEmpty(); return; }
  navigator.geolocation.getCurrentPosition(
    function (pos) {
      var lat = pos.coords.latitude, lon = pos.coords.longitude;
      var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + lat + '&longitude=' + lon +
        '&current=temperature_2m,weather_code&temperature_unit=' + unit;
      fetchWithRetry(url).then(function (data) {
        if (!data || !data.current) { showEmpty(); return; }
        render(data.current.temperature_2m, data.current.weather_code, el.getAttribute('data-place') || '');
      }).catch(showEmpty);
    },
    function () { showEmpty(); },
    { timeout: 6000 }
  );
}

document.addEventListener('DOMContentLoaded', function () {
  initWeatherCard();
});

window.Toast = Toast;
window.ConfirmDialog = ConfirmDialog;
window.CommandPalette = CommandPalette;


/* Charts: Chart.js defaults to dark-grey labels, which disappear on the dark theme. */
(function themeCharts() {
  if (!window.Chart) return;
  var dark = document.documentElement.getAttribute('data-theme') === 'dark';
  Chart.defaults.color = dark ? '#94a3b8' : '#64748b';
  Chart.defaults.borderColor = dark ? 'rgba(148,163,184,.16)' : 'rgba(100,116,139,.18)';
})();


/* Account generator: copy buttons, copy-as-table and CSV download. Only runs where those buttons exist. */
(function () {
  function copyText(text, btn) {
    function done() {
      if (!btn) return;
      var old = btn.textContent; btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = old; }, 1400);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { if (window.Toast) Toast.show('Could not copy. Select the text and copy it by hand.', 'error'); }
      document.body.removeChild(ta);
    }
  }
  function batch() {
    var el = document.getElementById('batchData');
    try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; }
  }
  function csvCell(v) {
    v = v == null ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(v)) v = "'" + v;   // stop spreadsheets treating a cell as a formula
    return '"' + v.replace(/"/g, '""') + '"';
  }
  document.addEventListener('click', function (e) {
    var one = e.target.closest('[data-copy]');
    if (one) { copyText(one.getAttribute('data-copy'), one); return; }
    var tbl = e.target.closest('[data-copy-table]');
    if (tbl) {
      var d = batch(); if (!d) return;
      var lines = ['Name\tEmail\tTemporary password'].concat(d.rows.map(function (r) { return [r.name, r.email, r.password].join('\t'); }));
      copyText(lines.join('\n'), tbl); return;
    }
    var dl = e.target.closest('[data-download-csv]');
    if (dl) {
      var b = batch(); if (!b) return;
      var rows = [['Name', 'Role', 'Email', 'Temporary password', 'Student no.', 'Sign-in page']].concat(
        b.rows.map(function (r) { return [r.name, r.role, r.email, r.password, r.student_no || '', b.signIn]; }));
      var csv = '\ufeff' + rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      a.download = 'new-accounts-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    }
  });
})();
