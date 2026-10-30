/**
 * Self-contained newsletter subscribe widget for stephens.page/blog posts.
 *
 * Include once per post, just before </body>:
 *   <script src="/blog/newsletter-widget.js" defer></script>
 *
 * It injects its own styles, renders the form inside the post's `.container`
 * (just above the footer), loads Cloudflare Turnstile, and POSTs to the Rust
 * newsletter service at https://newsletter.stephens.page/subscribe. Single source
 * of truth so the form can change in one place across the index and every post.
 */
(function () {
  'use strict';
  if (document.getElementById('nl-widget-styles')) return; // idempotent

  var SITE_KEY = '0x4AAAAAADk4Vi9kg773i1pu';
  // Which list this page subscribes to: <script ... data-list="personal">, or
  // window.NEWSLETTER_LIST, defaulting to the professional blog.
  var thisScript = document.currentScript;
  var LIST = (thisScript && thisScript.getAttribute('data-list')) || window.NEWSLETTER_LIST || 'stephens';
  // data-mode="link" renders a CTA to the hosted subscribe page instead of an inline
  // Turnstile form - used on domains the Turnstile key doesn't cover (e.g. jacobstephens.net).
  var MODE = (thisScript && thisScript.getAttribute('data-mode')) || 'form';
  var SUBSCRIBE_URL = 'https://newsletter.stephens.page/?list=' + encodeURIComponent(LIST);

  // 1. Styles (fallbacks so it holds up even if a page lacks the blog vars).
  var style = document.createElement('style');
  style.id = 'nl-widget-styles';
  style.textContent = [
    '.subscribe{margin-top:2.5rem;padding:1.6rem 1.5rem;border:1px solid var(--rule,#cfd8d3);border-radius:10px;background:var(--soft,#eef2ef);}',
    ".subscribe h2{font-family:'Source Serif 4',Georgia,serif;font-size:1.3rem;font-weight:700;letter-spacing:-0.01em;margin:0 0 0.35rem;color:var(--brand,#215239);}",
    '.subscribe .sub-copy{color:var(--muted,#4a5750);font-size:0.95rem;margin:0 0 1rem;}',
    '.subscribe form{display:flex;flex-direction:column;gap:0.8rem;}',
    '.subscribe .sub-row{display:flex;gap:0.6rem;flex-wrap:wrap;}',
    '.subscribe input[type="email"]{flex:1 1 220px;padding:0.6rem 0.75rem;font:inherit;color:var(--ink,#111714);background:var(--surface,#fcfdfc);border:1px solid var(--rule,#cfd8d3);border-radius:6px;}',
    '.subscribe input[type="email"]:focus-visible{outline:2px solid var(--brand,#215239);outline-offset:1px;border-color:var(--brand,#215239);}',
    '.subscribe button{padding:0.6rem 1.2rem;font:inherit;font-weight:700;color:var(--brand-ink,#fff);background:var(--brand,#215239);border:1px solid var(--brand,#215239);border-radius:6px;cursor:pointer;}',
    '.subscribe button:hover:not(:disabled){filter:brightness(0.92);}',
    '.subscribe button:disabled{opacity:0.6;cursor:default;}',
    '.subscribe .sub-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}',
    '.subscribe .sub-status{font-size:0.9rem;min-height:1.2em;margin:0;}',
    '.subscribe .sub-status.ok{color:#2f6b34;}',
    '.subscribe .sub-status.err{color:#a3372a;}',
    '.subscribe .fine{color:var(--muted,#4a5750);font-size:0.8rem;margin:0;}',
    '.subscribe .nl-cta{display:inline-block;padding:0.6rem 1.2rem;font-weight:700;color:var(--brand-ink,#fff)!important;background:var(--brand,#215239);border-radius:6px;text-decoration:none;}',
    '.subscribe .nl-cta:hover{filter:brightness(0.92);}'
  ].join('');
  document.head.appendChild(style);

  // Match Cloudflare Turnstile chrome to the page theme.
  function pageIsDark() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark') return true;
    if (t === 'light') return false;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  var turnstileTheme = pageIsDark() ? 'dark' : 'light';

  // 2. Markup.
  var section = document.createElement('section');
  section.className = 'subscribe';
  section.setAttribute('aria-labelledby', 'nl-sub-heading');
  if (MODE === 'link') {
    section.innerHTML =
      '<h2 id="nl-sub-heading">Get new posts by email</h2>' +
      '<p class="sub-copy">Occasional writeups, sent when I publish. No spam, unsubscribe anytime.</p>' +
      '<p><a class="nl-cta" href="' + SUBSCRIBE_URL + '">Subscribe &rarr;</a></p>';
  } else {
    section.innerHTML =
      '<h2 id="nl-sub-heading">Get new posts by email</h2>' +
      '<p class="sub-copy">Occasional writeups, sent when I publish. No spam, unsubscribe anytime.</p>' +
      '<form id="nl-subscribe-form" novalidate>' +
        '<div class="sub-row">' +
          '<input type="email" name="email" id="nl-sub-email" placeholder="you@example.com" autocomplete="email" required aria-label="Email address">' +
          '<button type="submit" id="nl-sub-btn">Subscribe</button>' +
        '</div>' +
        '<input type="hidden" name="list" value="' + LIST + '">' +
        '<div class="sub-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>' +
        '<div class="cf-turnstile" data-sitekey="' + SITE_KEY + '" data-theme="' + turnstileTheme + '"></div>' +
        '<p class="sub-status" id="nl-sub-status" role="status" aria-live="polite"></p>' +
        '<p class="fine">You\'ll get a confirmation email to opt in, and every email has a one-click unsubscribe.</p>' +
      '</form>';
  }

  var container = document.querySelector('.container');
  var footer = container ? container.querySelector('.footer') : null;
  if (container && footer) {
    container.insertBefore(section, footer);
  } else if (container) {
    container.appendChild(section);
  } else {
    // No .container (e.g. the personal blog): constrain width and center it.
    section.style.maxWidth = '640px';
    section.style.margin = '2.5rem auto';
    document.body.appendChild(section);
  }

  // Link mode: no Turnstile, no submit handler - the CTA links to the hosted page.
  if (MODE === 'link') { return; }

  // 3. Load Turnstile (auto-renders .cf-turnstile on load), or render now if present.
  if (window.turnstile) {
    try { window.turnstile.render(section.querySelector('.cf-turnstile'), { sitekey: SITE_KEY }); } catch (e) {}
  } else if (!document.querySelector('script[src*="turnstile/v0/api.js"]')) {
    var ts = document.createElement('script');
    ts.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    ts.async = true;
    ts.defer = true;
    document.head.appendChild(ts);
  }

  // 4. Submit handler.
  var form = section.querySelector('#nl-subscribe-form');
  var btn = section.querySelector('#nl-sub-btn');
  var status = section.querySelector('#nl-sub-status');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    status.className = 'sub-status';
    status.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Subscribing...';

    fetch('https://newsletter.stephens.page/subscribe', { method: 'POST', body: new URLSearchParams(new FormData(form)) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected response.' }; }); })
      .then(function (j) {
        status.textContent = j.message || (j.ok ? 'Thanks!' : 'Something went wrong.');
        status.className = 'sub-status ' + (j.ok ? 'ok' : 'err');
        if (j.ok) { form.reset(); }
      })
      .catch(function () {
        status.textContent = 'Network error. Please try again.';
        status.className = 'sub-status err';
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = 'Subscribe';
        if (window.turnstile) { try { window.turnstile.reset(); } catch (err) {} }
      });
  });
})();
