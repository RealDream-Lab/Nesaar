/**
 * Push Notification Client Manager
 * Handles push notification subscription and management
 */

class PushNotificationManager {
  constructor() {
    this.swRegistration = null;
    this.isSubscribed = false;
    this.vapidPublicKey = null;
  }

  /**
   * Initialize push notifications
   * @returns {Promise<boolean>}
   */
  async init() {
    // Check browser support
    if (!("serviceWorker" in navigator)) {
      console.warn("Service Worker not supported");
      return false;
    }

    if (!("PushManager" in window)) {
      console.warn("Push notifications not supported");
      return false;
    }

    if (!("Notification" in window)) {
      console.warn("Notifications not supported");
      return false;
    }

    try {
      // Get service worker registration
      this.swRegistration = await navigator.serviceWorker.ready;

      // Check current subscription status
      const subscription =
        await this.swRegistration.pushManager.getSubscription();
      this.isSubscribed = subscription !== null;

      // Get VAPID public key
      await this.fetchVapidKey();

      console.log("[Push] Initialized, subscribed:", this.isSubscribed);
      return true;
    } catch (error) {
      console.error("[Push] Init failed:", error);
      return false;
    }
  }

  /**
   * Fetch VAPID public key from server
   */
  async fetchVapidKey() {
    try {
      const response = await fetch("/API/push/getVapidKey.php");
      const data = await response.json();

      if (data.success && data.publicKey) {
        this.vapidPublicKey = data.publicKey;
      }
    } catch (error) {
      console.error("[Push] Failed to fetch VAPID key:", error);
    }
  }

  /**
   * Request notification permission
   * @returns {Promise<string>} Permission status: 'granted', 'denied', or 'default'
   */
  async requestPermission() {
    const permission = await Notification.requestPermission();
    console.log("[Push] Permission:", permission);
    return permission;
  }

  /**
   * Subscribe user to push notifications
   * @param {string} userType - 'student', 'proctor', or 'admin'
   * @param {string} userId - User identifier
   * @returns {Promise<boolean>}
   */
  async subscribe(userType, userId) {
    if (!this.swRegistration || !this.vapidPublicKey) {
      console.error("[Push] Not initialized");
      return false;
    }

    // Request permission first
    const permission = await this.requestPermission();
    if (permission !== "granted") {
      console.warn("[Push] Permission denied");
      return false;
    }

    try {
      // Convert VAPID key to Uint8Array
      const applicationServerKey = this.urlBase64ToUint8Array(
        this.vapidPublicKey
      );

      // Subscribe to push
      const subscription = await this.swRegistration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: applicationServerKey,
      });

      // Send subscription to server
      const success = await this.sendSubscriptionToServer(
        subscription,
        userType,
        userId
      );

      if (success) {
        this.isSubscribed = true;
        console.log("[Push] Subscribed successfully");
        return true;
      }
    } catch (error) {
      console.error("[Push] Subscribe failed:", error);
    }

    return false;
  }

  /**
   * Unsubscribe from push notifications
   * @returns {Promise<boolean>}
   */
  async unsubscribe() {
    if (!this.swRegistration) {
      return false;
    }

    try {
      const subscription =
        await this.swRegistration.pushManager.getSubscription();

      if (subscription) {
        // Unsubscribe from browser
        await subscription.unsubscribe();

        // Remove from server
        await this.removeSubscriptionFromServer(subscription);

        this.isSubscribed = false;
        console.log("[Push] Unsubscribed successfully");
        return true;
      }
    } catch (error) {
      console.error("[Push] Unsubscribe failed:", error);
    }

    return false;
  }

  /**
   * Send subscription to server
   * @param {PushSubscription} subscription
   * @param {string} userType
   * @param {string} userId
   * @returns {Promise<boolean>}
   */
  async sendSubscriptionToServer(subscription, userType, userId) {
    try {
      const key = subscription.getKey("p256dh");
      const auth = subscription.getKey("auth");

      const response = await pushCsrfFetch("/API/push/subscribe.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          user_type: userType,
          user_id: userId,
          endpoint: subscription.endpoint,
          keys: {
            p256dh: key
              ? btoa(String.fromCharCode.apply(null, new Uint8Array(key)))
              : "",
            auth: auth
              ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth)))
              : "",
          },
        }),
      });

      const data = await response.json();
      return data.success === true;
    } catch (error) {
      console.error("[Push] Failed to send subscription:", error);
      return false;
    }
  }

  /**
   * Remove subscription from server
   * @param {PushSubscription} subscription
   * @returns {Promise<boolean>}
   */
  async removeSubscriptionFromServer(subscription) {
    try {
      const response = await pushCsrfFetch("/API/push/unsubscribe.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          endpoint: subscription.endpoint,
        }),
      });

      const data = await response.json();
      return data.success === true;
    } catch (error) {
      console.error("[Push] Failed to remove subscription:", error);
      return false;
    }
  }

  /**
   * Check if user is subscribed
   * @returns {Promise<boolean>}
   */
  async checkSubscription() {
    if (!this.swRegistration) {
      return false;
    }

    const subscription =
      await this.swRegistration.pushManager.getSubscription();
    this.isSubscribed = subscription !== null;
    return this.isSubscribed;
  }

  /**
   * Get current permission status
   * @returns {string}
   */
  getPermissionStatus() {
    if (!("Notification" in window)) {
      return "unsupported";
    }
    return Notification.permission;
  }

  /**
   * Convert URL-safe base64 to Uint8Array
   * @param {string} base64String
   * @returns {Uint8Array}
   */
  urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
      .replace(/-/g, "+")
      .replace(/_/g, "/");

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }
}

// Global instance - use unique name to avoid conflicts with browser's PushManager
window.nesaarPushManager = new PushNotificationManager();

// Helper function to get CSRF token
function getPushCsrfToken() {
  const metaTag = document.querySelector('meta[name="csrf-token"]');
  return metaTag ? metaTag.getAttribute("content") : null;
}

// Helper function for CSRF-protected fetch
async function pushCsrfFetch(url, options = {}) {
  const csrfToken = getPushCsrfToken();
  options.headers = options.headers || {};
  if (csrfToken) {
    options.headers["X-CSRF-Token"] = csrfToken;
  }
  return fetch(url, options);
}

// Listen for subscription change messages from service worker
navigator.serviceWorker?.addEventListener("message", (event) => {
  if (event.data?.type === "push-subscription-changed") {
    console.log("[Push] Subscription changed, updating...");
    // Re-subscribe with stored user info if available
    const userType = localStorage.getItem("push_user_type");
    const userId = localStorage.getItem("push_user_id");
    if (userType && userId) {
      window.nesaarPushManager.subscribe(userType, userId);
    }
  }
});

/**
 * Helper function to show push notification toggle UI
 * @param {string} containerId - ID of container element
 * @param {string} userType - 'student', 'proctor', or 'admin'
 * @param {string} userId - User identifier
 */
async function initPushNotificationUI(containerId, userType, userId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const pushMgr = window.nesaarPushManager;
  const initialized = await pushMgr.init();

  if (!initialized) {
    container.innerHTML = `
      <div class="alert alert-warning">
        مرورگر شما از اعلان‌های Push پشتیبانی نمی‌کند
      </div>
    `;
    return;
  }

  const permission = pushMgr.getPermissionStatus();
  const isSubscribed = await pushMgr.checkSubscription();

  // Store user info for re-subscription
  localStorage.setItem("push_user_type", userType);
  localStorage.setItem("push_user_id", userId);

  const renderUI = () => {
    const currentPermission = pushMgr.getPermissionStatus();

    if (currentPermission === "denied") {
      container.innerHTML = `
        <div class="alert alert-danger">
          <i class="bi bi-bell-slash"></i>
          اعلان‌ها توسط مرورگر مسدود شده‌اند. لطفاً از تنظیمات مرورگر اجازه دهید.
        </div>
      `;
      return;
    }

    // Get reminder minutes from config (default 30)
    let reminderMinutes = 30;
    try {
      const cachedConfig = localStorage.getItem("appConfig");
      if (cachedConfig) {
        const cfg = JSON.parse(cachedConfig);
        const cfgMinutes = parseInt(cfg.PushReminderMinutes, 10);
        if (!isNaN(cfgMinutes) && cfgMinutes >= 30 && cfgMinutes <= 180) {
          reminderMinutes = cfgMinutes;
        }
      }
    } catch (e) {
      console.warn("[Push] Failed to get reminder minutes from config", e);
    }

    // Convert to Persian digits
    const persianDigits = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
    const reminderPersian = String(reminderMinutes).replace(
      /\d/g,
      (d) => persianDigits[d]
    );

    container.innerHTML = `
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="pushToggle" ${
          pushMgr.isSubscribed ? "checked" : ""
        }>
        <label class="form-check-label" for="pushToggle">
          <i class="bi bi-bell${pushMgr.isSubscribed ? "-fill" : ""}"></i>
          دریافت اعلان یادآوری آزمون (${reminderPersian} دقیقه قبل)
        </label>
      </div>
    `;

    const toggle = document.getElementById("pushToggle");
    toggle?.addEventListener("change", async (e) => {
      toggle.disabled = true;

      if (e.target.checked) {
        const success = await pushMgr.subscribe(userType, userId);
        if (!success) {
          e.target.checked = false;
        }
      } else {
        await pushMgr.unsubscribe();
      }

      toggle.disabled = false;
      renderUI();
    });
  };

  renderUI();
}
