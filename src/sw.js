// Service Worker for PWA functionality (caching and offline support)

const CACHE_NAME = 'beerfest-cache-v2'; // Increment cache version to force update
// These are the core files that make up the app's "shell".
const APP_SHELL_URLS = [
  '/',
  'index.php',
  'config/theme.css',
  'manifest.php',
  'my_stats.php'
];
// These are the data files we want to have available offline.
const DATA_URLS = [
    '/data/beers.json',
    '/data/flags.json'
];

// Install event: triggered when the service worker is first installed.
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache and caching app shell and initial data');
        // Cache the app shell and the initial data together.
        return cache.addAll([...APP_SHELL_URLS, ...DATA_URLS]);
      })
  );
});

// Activate event: triggered when the service worker is activated.
// This is a good place to clean up old caches.
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      ).then(() => {
        // Take control of all open clients once the old cache is cleared.
        return self.clients.claim();
      });
    })
  );
});

// Listen for a message from the client to activate the new service worker.
self.addEventListener('message', event => {
  if (event.data && event.data.action === 'skipWaiting') {
    self.skipWaiting();
  }
});


// Fetch event: triggered for every network request made by the page.
self.addEventListener('fetch', event => {
  const requestUrl = new URL(event.request.url);

  // Let the browser handle stats pages directly (basic auth requires native handling)
  if (requestUrl.pathname === '/stats.php' || requestUrl.pathname === '/stats') {
    return;
  }

  // Strategy: Network First, falling back to Cache for Data files
  // This ensures users get fresh data if online, but the app still works offline
  // because the data was pre-cached during the install event.
  if (requestUrl.pathname.includes('/data/')) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        return fetch(event.request)
          .then(networkResponse => {
            // If we get a fresh response from the network, update the cache
            cache.put(event.request, networkResponse.clone());
            return networkResponse;
          })
          .catch(() => {
            // If the network fails, return the cached version.
            return cache.match(event.request);
          });
      })
    );
    return;
  }

  // Strategy: Cache First for all other requests (app shell, assets)
  // This makes the app load instantly.
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        // Return from cache, or fetch from network if not in cache.
        return response || fetch(event.request);
      })
  );
});
