const CACHE_NAME = 'exam-v1';
const ASSETS = [
  '/',
  '/assets/bootstrap/bootstrap.min.css',
  '/assets/bootstrap/bootstrap.bundle.min.js',
  '/assets/sweetalert2/sweetalert2.min.css',
  '/assets/sweetalert2/sweetalert2.min.js',
  '/assets/fonts/vazir/vazir.css'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
});

self.addEventListener('fetch', e => {
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request)));
});
