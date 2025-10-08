const CACHE_NAME = 'exam-app-v1';
const urlsToCache = [
  '/',
  '/assets/bootstrap/bootstrap.min.css',
  '/assets/bootstrap/bootstrap.bundle.min.js',
  '/assets/material/material.min.css',
  '/assets/material/material.min.js',
  '/assets/sweetalert2/sweetalert2.min.css',
  '/assets/sweetalert2/sweetalert2.min.js',
  '/assets/fonts/vazir/vazir.css',
  '/assets/fonts/vazir/Vazir-Regular.woff2',
  '/assets/fonts/vazir/Vazir-Bold.woff2'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response;
        }
        return fetch(event.request);
      }
    )
  );
});