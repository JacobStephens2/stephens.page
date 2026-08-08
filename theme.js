/**
 * Theme controller for stephens.page
 *
 * Default: follow prefers-color-scheme (no data-theme attribute) - "system".
 * Toggle cycles: system → light → dark → system.
 * Storage key: "theme" → "light" | "dark" | null (system)
 */
(function () {
  var STORAGE_KEY = 'theme';
  var THEME_LIGHT = 'light';
  var THEME_DARK = 'dark';
  var META_LIGHT = '#9b4d24';
  var META_DARK = '#141210';

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  /** Stored preference: "light" | "dark" | null (system). */
  function readStored() {
    try {
      var v = localStorage.getItem(STORAGE_KEY);
      if (v === THEME_LIGHT || v === THEME_DARK) return v;
      // Treat legacy or explicit "system" the same as unset.
      if (v === 'system') {
        localStorage.removeItem(STORAGE_KEY);
      }
    } catch (e) { /* private mode / blocked storage */ }
    return null;
  }

  function writeStored(value) {
    try {
      if (value === null || value === 'system') localStorage.removeItem(STORAGE_KEY);
      else localStorage.setItem(STORAGE_KEY, value);
    } catch (e) { /* ignore */ }
  }

  /** Effective theme the page is showing right now. */
  function effectiveTheme() {
    var stored = readStored();
    if (stored) return stored;
    return systemPrefersDark() ? THEME_DARK : THEME_LIGHT;
  }

  /** Next preference in the cycle: system → light → dark → system. */
  function nextPreference(stored) {
    if (stored === null) return THEME_LIGHT;
    if (stored === THEME_LIGHT) return THEME_DARK;
    return null;
  }

  function applyTheme(preference) {
    var root = document.documentElement;
    if (preference === THEME_LIGHT || preference === THEME_DARK) {
      root.setAttribute('data-theme', preference);
    } else {
      root.removeAttribute('data-theme');
    }
    updateMetaThemeColor();
    updateToggleLabels();
  }

  function updateMetaThemeColor() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    meta.setAttribute('content', effectiveTheme() === THEME_DARK ? META_DARK : META_LIGHT);
  }

  function toggleTheme() {
    var next = nextPreference(readStored());
    writeStored(next);
    applyTheme(next);
  }

  function createToggleButton() {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'theme-toggle';
    btn.setAttribute('aria-label', 'Toggle color theme');
    btn.innerHTML =
      '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<circle cx="12" cy="12" r="4"></circle>' +
        '<path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>' +
      '</svg>' +
      '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5z"></path>' +
      '</svg>' +
      '<svg class="icon-system" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="2" y="3" width="20" height="14" rx="2"></rect>' +
        '<path d="M8 21h8M12 17v4"></path>' +
      '</svg>';
    btn.addEventListener('click', toggleTheme);
    return btn;
  }

  function updateToggleLabels() {
    var stored = readStored();
    var current = stored === null ? 'system' : stored;
    var next = nextPreference(stored);
    var nextLabel = next === null ? 'system' : next;
    var label = 'Theme: ' + current + ' (click for ' + nextLabel + ')';
    document.querySelectorAll('.theme-toggle').forEach(function (btn) {
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.setAttribute('data-theme-pref', current);
    });
  }

  function mountToggle() {
    if (document.querySelector('.theme-toggle')) return;

    var btn = createToggleButton();

    // Portfolio groups contact links in .topbar-right
    var topbarRight = document.querySelector('.topbar-right');
    if (topbarRight) {
      topbarRight.appendChild(btn);
      updateToggleLabels();
      return;
    }

    // Home page header
    var siteHeader = document.querySelector('.site-header');
    if (siteHeader) {
      var contact = siteHeader.querySelector('.site-contact');
      if (contact) {
        var cluster = document.createElement('div');
        cluster.className = 'theme-toggle-cluster';
        contact.replaceWith(cluster);
        cluster.appendChild(contact);
        cluster.appendChild(btn);
      } else {
        siteHeader.appendChild(btn);
      }
      updateToggleLabels();
      return;
    }

    // Most inner pages: .topbar with back + contact
    var topbar = document.querySelector('.topbar');
    if (topbar) {
      var contactLink = topbar.querySelector('.contact-link');
      if (contactLink && contactLink.parentElement === topbar) {
        var wrap = document.createElement('div');
        wrap.className = 'theme-toggle-cluster';
        contactLink.replaceWith(wrap);
        wrap.appendChild(contactLink);
        wrap.appendChild(btn);
      } else {
        topbar.appendChild(btn);
      }
      updateToggleLabels();
      return;
    }

    // Stack page nav
    var topnav = document.querySelector('.topnav');
    if (topnav) {
      topnav.appendChild(btn);
      updateToggleLabels();
      return;
    }

    // Connect / hi / scan and anything else without a header bar
    btn.classList.add('theme-toggle--fixed');
    document.body.appendChild(btn);
    updateToggleLabels();
  }

  // Apply stored preference as early as this script runs.
  applyTheme(readStored());

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    mountToggle();
    // Enable transitions only after first paint so the initial theme doesn't animate in.
    requestAnimationFrame(function () {
      document.documentElement.classList.add('theme-ready');
    });
  });

  // If the user has not pinned a theme, track OS changes live.
  if (window.matchMedia) {
    var mql = window.matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () {
      if (!readStored()) {
        applyTheme(null);
      }
    };
    if (typeof mql.addEventListener === 'function') {
      mql.addEventListener('change', onChange);
    } else if (typeof mql.addListener === 'function') {
      mql.addListener(onChange);
    }
  }

  // Cross-tab sync
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) {
      applyTheme(readStored());
    }
  });
})();
