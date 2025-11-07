

const CACHE_NAME = 'exam-seat-v0.3.0';
const VERSION = '0.3.0';
const urlsToCache = [
  '/',
  '/index.php',
  '/manifest.json',
  '/assets/fonts/vazir/vazir.css',
  '/assets/bootstrap/bootstrap.min.css',
  '/assets/sweetalert2/sweetalert2.min.css',
  '/assets/app/style.css',
  '/assets/bootstrap/bootstrap.bundle.min.js',
  '/assets/sweetalert2/sweetalert2.min.js',
  '/assets/crypto-js.min.js',
  '/assets/app/app.js',
  '/assets/app/version.js',
  '/assets/vendor/chartjs/chart.min.js',
  '/dashboard/dashboard.js',
  '/assets/app/logo.png',
  '/assets/app/Pnulogo.png',
  '/pwa-icons/icon-192.png',
  '/pwa-icons/icon-512.png'
];

self.addEventListener('install', event => {

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
      fetch(request).catch(() => new Response(
        JSON.stringify({ error: 'offline_unavailable', message: 'داده‌ها بدون اتصال به اینترنت در دسترس نیستند.' }),
        {
          status: 503,
          headers: { 'Content-Type': 'application/json; charset=utf-8' }
        }
      ))
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

      self.clients.matchAll().then(clients => {
        clients.forEach(client => {
          client.postMessage({
                type: 'sw-update',
                version: CACHE_NAME,
                tagVersion: `نسخه ${VERSION}`,
                changes: [
                  'افزایش نسخه به 0.3.0 و به‌روزرسانی کش PWA',
                  'نمایش placeholder جمع‌وجور در کارت نمودار زمانی که داده‌ای برای نمایش نیست',
                  'قابلیت بارگذاری مجدد نمودار از داخل کارت (دکمه بارگذاری مجدد)',
                  'بهبود رفتار کارت گزارش‌ها: جایگزینی حذف کارت با placeholder جمع‌وجور',
                  'بارگذاری تنبل (lazy-load) لیست اسامی دانشجویان در گزارش آزمون بعدی',
                  'افزودن مرحله پاکسازی نسخه‌های قدیمی تصویر در GHCR پس از انتشار (در workflow CI)',
                  'اصلاح fallback در workflow برای مدیریت REST packages (رفع TypeError در github-script)'
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
    if (fresh.status === 403) {
      await cache.delete(request);
      return fresh;
    }
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
