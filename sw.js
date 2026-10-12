/**
 * Service worker for stephens.page
 *
 * Pages: network-first, falling back to the cached copy, then to /offline.html.
 * Static assets (fonts, CSS, JS, images): stale-while-revalidate, so edits land on the next visit.
 * Only same-origin GETs are handled; POSTs (contact form, /card/share) pass through.
 * Bump VERSION to drop old caches after a breaking change.
 */
var VERSION = 'v1';
var PAGES = 'pages-' + VERSION;
var ASSETS = 'assets-' + VERSION;

// The card and its QR page must work with no signal at a conference.
var PRECACHE = [
  '/',
  '/offline.html',
  '/card/',
  '/card/qr/',
  '/card/qr/card-qr.png',
  '/card/jacob-stephens-avatar.jpg',
  '/card/jacob-stephens.vcf',
  '/theme.css',
  '/theme.js',
  '/bee-favicon.png',
  '/fonts/source-sans-3-latin.woff2',
  '/fonts/source-serif-4-latin.woff2'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(PAGES).then(function (cache) { return cache.addAll(PRECACHE); })
      .then(function () { return self.skipWaiting(); })
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

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Leave PHP endpoints and the private area to the network.
  if (url.pathname.indexOf('/private/') === 0 || /\.php$/.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then(function (res) {
        if (res.ok) {
          var copy = res.clone();
          caches.open(PAGES).then(function (c) { c.put(req, copy); });
        }
        return res;
      }).catch(function () {
        return caches.match(req, { ignoreSearch: true }).then(function (hit) {
          return hit || caches.match('/offline.html');
        });
      })
    );
    return;
  }

  if (isAsset(url)) {
    event.respondWith(
      caches.match(req).then(function (hit) {
        var fresh = fetch(req).then(function (res) {
          if (res.ok) {
            var copy = res.clone();
            caches.open(ASSETS).then(function (c) { c.put(req, copy); });
          }
          return res;
        });
        if (hit) {
          event.waitUntil(fresh.catch(function () {}));
          return hit;
        }
        return fresh;
      })
    );
  }
});
