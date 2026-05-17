// Service Worker for PWA functionality (caching and offline support)

const CACHE_NAME = 'beerfest-cache-v8';

// Files pre-cached on install so the app shell loads offline on first launch.
// "/" is the canonical app entry — index.php is served at "/" by nginx, so
// caching both would just duplicate the same response under two keys.
const APP_SHELL_URLS = [
  '/',
  '/config/theme.css',
  '/dist/style.css',
];

// Data files pre-cached so beer listings are available offline immediately.
const DATA_URLS = [
  '/data/beers.json',
  '/data/flags.json',
];

// Auth-protected pages — let the browser handle these natively so basic-auth
// prompts work and we never cache credentials-bearing responses.
const AUTH_PATHS = new Set([
  '/stats.php', '/stats',
  '/admin.php', '/admin',
  '/admin_api.php', '/admin_api',
]);

// Decide whether a response is safe to cache.
//   - opaque responses (cross-origin no-cors, e.g. gstatic fonts) are kept
//   - non-2xx is never cached so 404s and 5xx aren't pinned
//   - no-store/private is never cached (handles the NOT_PUBLIC gate page)
function shouldCache(response) {
  if (!response) return false;
  if (response.type === 'opaque') return true;
  if (!response.ok) return false;
  const cc = response.headers.get('Cache-Control') || '';
  return !/no-store|private/i.test(cc);
}

// Install: pre-cache the app shell with per-URL fetches + shouldCache filter.
// Using Promise.allSettled (instead of cache.addAll) means a single 404 won't
// fail the whole install, and a no-store gate-mode response won't be pinned.
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      Promise.allSettled(
        [...APP_SHELL_URLS, ...DATA_URLS].map(url =>
          fetch(url, { cache: 'reload' }).then(response => {
            if (shouldCache(response)) {
              return cache.put(url, response);
            }
          })
        )
      )
    )
  );
});

// Activate: drop old caches, enable Navigation Preload, claim open tabs.
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    if (self.registration.navigationPreload) {
      await self.registration.navigationPreload.enable();
    }
    const names = await caches.keys();
    await Promise.all(
      names.filter(n => n !== CACHE_NAME).map(n => caches.delete(n))
    );
    await self.clients.claim();
  })());
});

// Stale-while-revalidate: serve cache instantly, refresh in background.
// Evicts the cached entry when the network response is no-store/private so
// a NOT_PUBLIC toggle self-heals on the next request.
function staleWhileRevalidate(request, cache) {
  return cache.match(request).then(cachedResponse => {
    const networkFetch = fetch(request).then(networkResponse => {
      if (shouldCache(networkResponse)) {
        cache.put(request, networkResponse.clone());
      } else {
        cache.delete(request);
      }
      return networkResponse;
    });
    if (cachedResponse) {
      networkFetch.catch(() => {});
      return cachedResponse;
    }
    return networkFetch;
  });
}

self.addEventListener('fetch', event => {
  // Only intercept GETs over http(s). Skip POST/PUT/DELETE and exotic schemes
  // like chrome-extension:// (cache.put rejects for non-http(s)).
  if (event.request.method !== 'GET') return;
  const requestUrl = new URL(event.request.url);
  if (!requestUrl.protocol.startsWith('http')) return;

  if (AUTH_PATHS.has(requestUrl.pathname)) return;

  const isSameOrigin = requestUrl.origin === self.location.origin;
  const isFonts = requestUrl.origin === 'https://fonts.googleapis.com'
               || requestUrl.origin === 'https://fonts.gstatic.com';

  // Google Fonts: stale-while-revalidate so fonts work offline.
  if (isFonts) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => staleWhileRevalidate(event.request, cache))
    );
    return;
  }

  // Don't intercept other cross-origin requests — keeps our cache lean and
  // avoids accidentally caching third-party scripts/pixels.
  if (!isSameOrigin) return;

  // Data files: network-first, cache fallback for offline.
  if (requestUrl.pathname.startsWith('/data/')) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache =>
        fetch(event.request)
          .then(networkResponse => {
            if (shouldCache(networkResponse)) {
              cache.put(event.request, networkResponse.clone());
            } else {
              cache.delete(event.request);
            }
            return networkResponse;
          })
          .catch(() => cache.match(event.request))
      )
    );
    return;
  }

  // Document navigations: cache-first with preload-backed revalidation, and
  // an app-shell fallback when both cache and network are unavailable.
  if (event.request.mode === 'navigate') {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_NAME);
      const cachedResponse = await cache.match(event.request);

      const networkFetch = (async () => {
        const preload = event.preloadResponse ? await event.preloadResponse : null;
        const networkResponse = preload || await fetch(event.request);
        if (shouldCache(networkResponse)) {
          cache.put(event.request, networkResponse.clone());
        } else {
          cache.delete(event.request);
        }
        return networkResponse;
      })();

      if (cachedResponse) {
        networkFetch.catch(() => {});
        return cachedResponse;
      }
      try {
        return await networkFetch;
      } catch {
        const shell = await cache.match('/');
        if (shell) return shell;
        return new Response('Offline', {
          status: 503,
          headers: { 'Content-Type': 'text/plain' },
        });
      }
    })());
    return;
  }

  // Everything else (same-origin assets): stale-while-revalidate.
  event.respondWith(
    caches.open(CACHE_NAME).then(cache => staleWhileRevalidate(event.request, cache))
  );
});
