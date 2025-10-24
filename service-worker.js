// Bump cache to force refresh of updated assets (app.js, style.css, index.html)
// Increment this when you want clients to fetch the new assets.
const CACHE_NAME = 'exam-seat-v2.0.1';
const VERSION = '۲.۰.۱';
const urlsToCache = [
  '/',
  '/index.html',
  '/manifest.json',
  '/assets/fonts/vazir/vazir.css',
  '/assets/bootstrap/bootstrap.min.css',
  '/assets/sweetalert2/sweetalert2.min.css',
  '/assets/app/style.css',
  '/assets/bootstrap/bootstrap.bundle.min.js',
  '/assets/sweetalert2/sweetalert2.min.js',
  '/assets/crypto-js.min.js',
  '/assets/app/app.js',
  '/assets/app/logo.png',
  '/assets/app/Pnulogo.png',
  '/pwa-icons/icon-192.png',
  '/pwa-icons/icon-512.png'
];

self.addEventListener('install', event => {
  // Activate updated SW immediately
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      for (const url of urlsToCache) {
        try {
          const request = new Request(url, { cache: 'reload' });
          await cache.add(request);
        } catch (error) {
          console.warn('[SW] Skipping cache for', url, error);
        }
      }
    })
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (url.pathname.startsWith('/API/')) {
    event.respondWith(
      fetch(request).catch(() => caches.match(request))
    );
    return;
  }

  if (request.mode === 'navigate' || shouldUseNetworkFirst(url.pathname)) {
    event.respondWith(networkFirst(request));
    return;
  }

  event.respondWith(cacheFirst(request));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      )
    ).then(() => {
      self.clients.claim();
      // Notify all clients about SW update
      self.clients.matchAll().then(clients => {
        clients.forEach(client => {
          client.postMessage({
            type: 'sw-update',
            version: CACHE_NAME,
            tagVersion: `نسخه ${VERSION}`,
            changes: [
              'فعال‌سازی داشبورد مدیریت',
              'بهبود طراحی با Glassmorphism'
            ]
          });
        });
      });
    })
  );
});

function shouldUseNetworkFirst(pathname) {
  const networkFirstExts = ['.html', '.js', '.mjs', '.css', '.json', '.wasm'];
  return networkFirstExts.some(ext => pathname.endsWith(ext));
}

async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  try {
    const fresh = await fetch(request);
    cache.put(request, fresh.clone());
    return fresh;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    throw error;
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }
  const fresh = await fetch(request);
  cache.put(request, fresh.clone());
  return fresh;
}
