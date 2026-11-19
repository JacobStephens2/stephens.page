/**
 * Service worker for stephens.page
 *
 * Pages and shared theme files (/theme.css, /theme.js): network-first, falling back to
 * the scoped cache (and /offline.html for navigations).
 * Other static assets (fonts, images, page scripts): stale-while-revalidate scoped to ASSETS,
 * so edits land on the next visit without cross-bucket cache shadowing.
 * Only same-origin GETs are handled; POSTs (contact form, /card/share) pass through.
 * Bump VERSION to drop old caches after a breaking change.
 */
var VERSION = 'v2';
var PAGES = 'pages-' + VERSION;
var ASSETS = 'assets-' + VERSION;

// The card and its QR page must work with no signal at a conference.
var PRECACHE_PAGES = [
  '/',
  '/offline.html',
  '/card/',
  '/card/qr/'
];

var PRECACHE_ASSETS = [
  '/card/qr/card-qr.png',
  '/card/jacob-stephens-avatar.jpg',
  '/card/jacob-stephens.vcf',
  '/theme.css',
  '/theme.js',
  '/bee-favicon.png',
  '/fonts/source-sans-3-latin.woff2',
  '/fonts/source-serif-4-latin.woff2'
];

function precacheFresh(cacheName, urls) {
  return caches.open(cacheName).then(function (cache) {
    return Promise.all(urls.map(function (url) {
      return fetch(url, { cache: 'no-cache' }).then(function (res) {
        if (!res.ok) throw new Error('Precache failed for ' + url);
        return cache.put(url, res);
      });
    }));
  });
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    Promise.all([
      precacheFresh(PAGES, PRECACHE_PAGES),
      precacheFresh(ASSETS, PRECACHE_ASSETS)
    ]).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) {
        return k !== PAGES && k !== ASSETS;
      }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isAsset(url) {
  return /\.(?:css|js|woff2|png|jpe?g|svg|webp|gif|ico)$/i.test(url.pathname);
}

function isThemeFile(url) {
  return url.pathname === '/theme.css' || url.pathname === '/theme.js';
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Leave PHP endpoints and the private area to the network.
  if (url.pathname.indexOf('/private/') === 0 || /\.php$/.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req, { cache: 'no-cache' }).then(function (res) {
        if (res.ok) {
          var copy = res.clone();
          caches.open(PAGES).then(function (c) { c.put(req, copy); });
        }
        return res;
      }).catch(function () {
        return caches.open(PAGES).then(function (c) {
          return c.match(req, { ignoreSearch: true }).then(function (hit) {
            return hit || c.match('/offline.html');
          });
        });
      })
    );
    return;
  }

  if (isThemeFile(url)) {
    event.respondWith(
      caches.open(ASSETS).then(function (cache) {
        return fetch(req, { cache: 'no-cache' }).then(function (res) {
          if (res.ok) cache.put(req, res.clone());
          return res;
        }).catch(function () {
          return cache.match(req);
        });
      })
    );
    return;
  }

  if (isAsset(url)) {
    event.respondWith(
      caches.open(ASSETS).then(function (cache) {
        return cache.match(req).then(function (hit) {
          var fresh = fetch(req, { cache: 'no-cache' }).then(function (res) {
            if (res.ok) cache.put(req, res.clone());
            return res;
          });
          if (hit) {
            event.waitUntil(fresh.catch(function () {}));
            return hit;
          }
          return fresh;
        });
      })
    );
  }
});
