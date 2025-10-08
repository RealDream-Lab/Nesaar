const CACHE_NAME = 'exam-seat-v1';
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
  '/assets/app/app.js',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(response => response || fetch(event.request))
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      )
    )
  );
});
