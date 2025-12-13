const CACHE_NAME = "exam-seat-v0.9.92";
const VERSION = "0.9.92";
const urlsToCache = [
  "/",
  "/index.php",
  "/manifest.json",
  "/assets/fonts/vazir/vazir.css",
  "/assets/fonts/vazir/Farsi-Digits/Vazir-Regular-FD.woff2",
  "/assets/fonts/vazir/Farsi-Digits/Vazir-Medium-FD.woff2",
  "/assets/fonts/vazir/Farsi-Digits/Vazir-Bold-FD.woff2",
  "/assets/bootstrap/bootstrap.min.css",
  "/assets/sweetalert2/sweetalert2.min.css",
  "/assets/app/style.css",
  "/assets/bootstrap/bootstrap.bundle.min.js",
  "/assets/sweetalert2/sweetalert2.min.js",
  "/assets/crypto-js.min.js",
  "/assets/app/app.js",
  "/assets/app/version.js",
  "/assets/app/push-notifications.js",
  "/assets/vendor/chartjs/chart.min.js",
  "/dashboard/dashboard.js",
  "/assets/app/logo.png",
  "/assets/app/Pnulogo.png",
  "/pwa-icons/icon-192.png",
  "/pwa-icons/icon-512.png",
];

self.addEventListener("install", (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      for (const url of urlsToCache) {
        try {
          const request = new Request(url, { cache: "reload" });
          await cache.add(request);
        } catch (error) {
          console.warn("[SW] Skipping cache for", url, error);
        }
      }
    })
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (request.method !== "GET") {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  if (url.pathname.startsWith("/API/")) {
    event.respondWith(handleApiRequest(request));
    return;
  }

  if (request.mode === "navigate" || shouldUseNetworkFirst(url.pathname)) {
    event.respondWith(networkFirst(request));
    return;
  }

  event.respondWith(cacheFirst(request));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key !== CACHE_NAME)
            .map((key) => caches.delete(key))
        )
      )
      .then(() => {
        self.clients.claim();

        self.clients.matchAll().then((clients) => {
          clients.forEach((client) => {
            client.postMessage({
              type: "sw-update",
              version: CACHE_NAME,
              tagVersion: `نسخه ${VERSION}`,
              changes: ["فعال‌سازی پوش نوتیفیکیشن"],
            });
          });
        });
      })
  );
});

function shouldUseNetworkFirst(pathname) {
  const networkFirstExts = [".html", ".js", ".mjs", ".css", ".json", ".wasm"];
  return networkFirstExts.some((ext) => pathname.endsWith(ext));
}

async function networkFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  try {
    const fresh = await fetch(request);
    if (fresh.status === 403) {
      await handleLicenseForbidden();
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
  if (fresh.status === 403) {
    await handleLicenseForbidden();
    return fresh;
  }
  cache.put(request, fresh.clone());
  return fresh;
}

async function handleApiRequest(request) {
  try {
    const response = await fetch(request);
    if (response.status === 403) {
      await handleLicenseForbidden();
    }
    return response;
  } catch (error) {
    return new Response(
      JSON.stringify({
        error: "offline_unavailable",
        message: "داده‌ها بدون اتصال به اینترنت در دسترس نیستند.",
      }),
      {
        status: 503,
        headers: { "Content-Type": "application/json; charset=utf-8" },
      }
    );
  }
}

let licenseForbiddenNotified = false;

async function handleLicenseForbidden() {
  await purgeAllCaches();
  if (licenseForbiddenNotified) {
    return;
  }
  licenseForbiddenNotified = true;
  const clients = await self.clients.matchAll();
  for (const client of clients) {
    client.postMessage({
      type: "license-forbidden",
      message: "مجوز سامانه معتبر نیست، لطفاً دوباره وارد شوید.",
    });
  }
}

async function purgeAllCaches() {
  const keys = await caches.keys();
  await Promise.all(keys.map((key) => caches.delete(key)));
}

// =====================================================
// Push Notification Handlers
// =====================================================

self.addEventListener("push", (event) => {
  console.log("[SW] Push received:", event);

  let data = {
    title: "اطلاع‌رسانی",
    body: "شما یک پیام جدید دارید",
    icon: "/pwa-icons/icon-192.png",
    badge: "/pwa-icons/icon-192.png",
    data: {},
  };

  if (event.data) {
    try {
      const payload = event.data.json();
      data = { ...data, ...payload };
    } catch (e) {
      console.warn("[SW] Failed to parse push data:", e);
      data.body = event.data.text();
    }
  }

  // Store notification data for showing SweetAlert in clients
  const alertData = {
    type: "show-notification-alert",
    title: data.title,
    body: data.body,
    data: data.data,
  };

  const options = {
    body: data.body,
    icon: data.icon,
    badge: data.badge,
    vibrate: [200, 100, 200],
    data: { ...data.data, alertData: alertData },
    tag: data.tag || "default",
    requireInteraction: data.requireInteraction || false,
    actions: data.actions || [],
  };

  event.waitUntil(
    (async () => {
      // Show notification
      await self.registration.showNotification(data.title, options);

      // Send message to all open clients to show SweetAlert immediately
      const clientList = await self.clients.matchAll({
        type: "window",
        includeUncontrolled: true,
      });

      console.log("[SW] Found clients:", clientList.length);

      for (const client of clientList) {
        console.log("[SW] Posting to client:", client.url);
        client.postMessage(alertData);
      }
    })()
  );
});

self.addEventListener("notificationclick", (event) => {
  console.log("[SW] Notification clicked:", event);

  event.notification.close();

  const notificationData = event.notification.data || {};
  const notificationTitle = event.notification.title || "نسار - اطلاع‌رسانی";
  const notificationBody = event.notification.body || "";

  event.waitUntil(
    clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((clientList) => {
        // Prepare message for SweetAlert display
        const alertMessage = {
          type: "show-notification-alert",
          title: notificationTitle,
          body: notificationBody,
          data: notificationData,
        };

        // Try to focus existing window and send message
        for (const client of clientList) {
          if (client.url.includes(self.location.origin) && "focus" in client) {
            client.focus();
            client.postMessage(alertMessage);
            return;
          }
        }

        // Open new window if none exists
        if (clients.openWindow) {
          // Store the message to show after window opens
          return clients.openWindow("/").then((newClient) => {
            // Wait a bit for the page to load, then send message
            setTimeout(() => {
              clients.matchAll({ type: "window" }).then((cls) => {
                cls.forEach((c) => c.postMessage(alertMessage));
              });
            }, 2000);
          });
        }
      })
  );
});

self.addEventListener("notificationclose", (event) => {
  console.log("[SW] Notification closed:", event);
});

// Handle push subscription change
self.addEventListener("pushsubscriptionchange", (event) => {
  console.log("[SW] Push subscription changed:", event);

  event.waitUntil(
    self.registration.pushManager
      .subscribe({
        userVisibleOnly: true,
        applicationServerKey:
          event.oldSubscription?.options?.applicationServerKey,
      })
      .then((subscription) => {
        // Notify clients to update subscription on server
        self.clients.matchAll().then((clients) => {
          clients.forEach((client) => {
            client.postMessage({
              type: "push-subscription-changed",
              subscription: subscription.toJSON(),
            });
          });
        });
      })
      .catch((err) => {
        console.error("[SW] Failed to resubscribe:", err);
      })
  );
});
