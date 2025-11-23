document.addEventListener("DOMContentLoaded", () => {
  // Global wrapper: convert simple informational Swal modals into 5s toasts
  (function wrapSwalInfoToasts() {
    try {
      function patch() {
        try {
          if (typeof Swal === "undefined" || Swal._ns_info_toast_patched)
            return;
          const _orig = Swal.fire.bind(Swal);
          Swal.fire = function (opts) {
            try {
              // Only intercept plain object-style calls (common usage across project)
              if (typeof opts === "object" && opts !== null) {
                const isSimpleInfo =
                  (opts.icon === "info" || opts.icon === "success") &&
                  !opts.input &&
                  !opts.html &&
                  !opts.showCancelButton &&
                  // if explicitly asking user to confirm (showConfirmButton=true) we still convert
                  // because toasts are intended for non-blocking notifications
                  true;
                if (isSimpleInfo) {
                  const toastOpts = Object.assign({}, opts, {
                    toast: true,
                    position: opts.position || "top-end",
                    timer: typeof opts.timer === "number" ? opts.timer : 3000,
                    showConfirmButton: false,
                    allowOutsideClick: true,
                  });
                  // ensure RTL toast class is preserved
                  if (toastOpts.customClass) {
                    toastOpts.customClass = Object.assign(
                      {},
                      toastOpts.customClass
                    );
                    toastOpts.customClass.popup =
                      toastOpts.customClass.popup || "swal2-rtl swal2-toast";
                  } else {
                    toastOpts.customClass = { popup: "swal2-rtl swal2-toast" };
                  }
                  _orig(toastOpts);
                  // return resolved promise to preserve callsites expecting a Promise
                  return Promise.resolve({ isConfirmed: true });
                }
              }
            } catch (e) {
              // fallthrough to original
            }
            return _orig.apply(Swal, arguments);
          };
          Swal._ns_info_toast_patched = true;
        } catch (e) {}
      }
      if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", patch);
      else patch();
    } catch (e) {}
  })();

  const VERSION = window.APP_VERSION;

  function getCsrfToken() {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    return metaTag ? metaTag.getAttribute("content") : null;
  }

  async function secureFetch(url, options = {}) {
    const csrfToken = getCsrfToken();

    if (
      options.method &&
      ["POST", "PUT", "DELETE", "PATCH"].includes(options.method.toUpperCase())
    ) {
      options.headers = options.headers || {};
      if (csrfToken) {
        options.headers["X-CSRF-Token"] = csrfToken;
      }
    }

    return fetch(url, options);
  }

  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener("message", (event) => {
      if (event.data?.type === "sw-update") {
        const { changes } = event.data || {};
        const items = (changes || []).slice(0, 5);

        Swal.fire({
          icon: "info",
          title: "نسخه جدید منتشر شد",
          html: `<div style="text-align:justify;">نسخهٔ جدیدی منتشر شده است. برای اعمال تغییرات صفحه را بازنشانی کنید:<br><br>${items
            .map((c) => `• ${c}`)
            .join("<br>")}</div>`,
          showConfirmButton: true,
          confirmButtonText: "بارگذاری مجدد",
          showCancelButton: false,
          allowOutsideClick: true,
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        }).then((result) => {
          if (result.isConfirmed) {
            try {
              window.location.reload(true);
            } catch (e) {
              window.location.reload();
            }
          }
        });
      }
    });
  }
  const form = document.getElementById("examForm");
  const searchBtn = document.getElementById("searchBtn");
  const examCards = document.getElementById("examCards");
  const studentTypeRadio = document.getElementById("studentType");
  const staffTypeRadio = document.getElementById("staffType");
  const coworkerTypeRadio = document.getElementById("coworkerType");
  const firstFieldLabel = document.getElementById("firstFieldLabel");
  const secondFieldLabel = document.getElementById("secondFieldLabel");
  const studentIdInput = document.getElementById("studentId");
  const nationalIdInput = document.getElementById("nationalId");
  const loginRow = document.getElementById("loginRow");
  const loginSection = document.getElementById("loginSection");
  const staffRadioWrapper = document.querySelector('[data-role="staff-radio"]');
  const captchaContainer = document.getElementById("captchaContainer");
  const captchaQuestion = document.getElementById("captchaQuestion");
  const captchaAnswerInput = document.getElementById("captchaAnswer");
  const captchaTokenInput = document.getElementById("captchaToken");
  const footerClock = document.getElementById("footerClock");
  const footerSpacer = document.querySelector(".footer-spacer");
  const REFRESH_INTERVAL_MS = 60000;
  const CLOCK_REFRESH_MS = REFRESH_INTERVAL_MS;

  let refreshTimer = null;
  let clockTimer = null;

  let baseServerMs = Date.now();
  let basePerf =
    typeof performance !== "undefined" && performance.now
      ? performance.now()
      : Date.now();
  let secondTimer = null;
  let currentCredentials = null;
  let lastSnapshot = "";
  let lastPayload = [];
  let lastFullName = "";
  let lastStudentId = "";
  let licenseAlertShown = false;
  let lastLicenseAlertMessage = "";
  const COWORKER_SESSION_KEY = "coworkerSession";
  let coworkerSessions = [];
  let coworkerStats = null;
  let coworkerProfile = null;
  let coworkerCredentials = null;
  let staffRadioHidden = false;
  let suppressUserTypePersistence = false;

  async function handleLicenseGuardResponse(response) {
    if (response.status !== 403) return;
    let message = "دسترسی شما به علت مشکل در لایسنس محدود شده است.";
    try {
      const payload = await response.clone().json();
      if (payload && payload.message) {
        message = payload.message;
      }
    } catch (error) {
      // ignored: fallback message is already defined
    }
    showLicenseExpiredAlert(message);
    const err = new Error("license_forbidden");
    err.isLicenseError = true;
    throw err;
  }

  async function guardedFetch(resource, options) {
    const response = await secureFetch(resource, options);
    await handleLicenseGuardResponse(response);
    return response;
  }

  function getPersianMonthName(dateStr) {
    const parts = dateStr.split("/");
    const month = parseInt(parts[1], 10);
    const months = [
      "",
      "فروردین",
      "اردیبهشت",
      "خرداد",
      "تیر",
      "مرداد",
      "شهریور",
      "مهر",
      "آبان",
      "آذر",
      "دی",
      "بهمن",
      "اسفند",
    ];
    return months[month] || "";
  }

  // Fetch and cache config
  async function loadConfig() {
    try {
      const response = await guardedFetch("API/getConfig.php");
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const config = await response.json();
      localStorage.setItem("appConfig", JSON.stringify(config));
      return config;
    } catch (error) {
      console.warn("Failed to load config:", error);

      const cached = localStorage.getItem("appConfig");

      return cached
        ? JSON.parse(cached)
        : { University: "", SaadCode: "", IsInit: "NO" };
    }
  }

  let appConfig = null;
  loadConfig().then((config) => {
    appConfig = config;

    const footerText = document.getElementById("footerText");
    if (footerText) {
      footerText.textContent = config.University
        ? `نسار - ${config.University}`
        : "نسار";
    }

    if (config.IsInit !== "YES") {
      showInitModal(config);
    }
  });

  async function showInitModal(currentConfig) {
    const defaultSaad =
      currentConfig && currentConfig.SaadCode
        ? String(currentConfig.SaadCode).trim()
        : "";
    const defaultUniversity =
      currentConfig && currentConfig.University
        ? String(currentConfig.University).trim()
        : "";
    const { value: formValues } = await Swal.fire({
      title: "تنظیمات اولیه",
      html: `
                <div style="text-align:right; direction:rtl; line-height:2; max-width: 400px; margin: 0 auto;">
                    <label for="swal-saadcode">کد ساد مرکز:</label><br>
                    <input
                        id="swal-saadcode"
                        class="swal2-input"
                        value="${escapeHtml(defaultSaad)}"
                        style="width:100%; max-width: 380px; margin-bottom:10px;"
                    ><br>
                    <label for="swal-university">دانشگاه:</label><br>
                    <input
                        id="swal-university"
                        class="swal2-input"
                        value="${escapeHtml(defaultUniversity)}"
                        style="width:100%; max-width: 380px;"
                    >
                </div>
            `,
      focusConfirm: false,
      showCancelButton: false,
      confirmButtonText: "ذخیره",
      width: 600,
      buttonsStyling: false,
      allowOutsideClick: false,
      allowEscapeKey: false,
      preConfirm: () => {
        const saadRaw = document.getElementById("swal-saadcode").value.trim();
        const university = document
          .getElementById("swal-university")
          .value.trim();
        if (!saadRaw || !university) {
          Swal.showValidationMessage("هر دو فیلد باید پر شوند");
          return false;
        }

        const digitMap = {
          "۰": "0",
          "۱": "1",
          "۲": "2",
          "۳": "3",
          "۴": "4",
          "۵": "5",
          "۶": "6",
          "۷": "7",
          "۸": "8",
          "۹": "9",
          "٠": "0",
          "١": "1",
          "٢": "2",
          "٣": "3",
          "٤": "4",
          "٥": "5",
          "٦": "6",
          "٧": "7",
          "٨": "8",
          "٩": "9",
        };
        let normalized = "";
        for (let ch of saadRaw) {
          normalized += digitMap[ch] !== undefined ? digitMap[ch] : ch;
        }
        normalized = normalized.replace(/\s+/g, "");
        if (!/^\d{4}$/.test(normalized)) {
          Swal.showValidationMessage("کد ساد باید دقیقاً ۴ رقم (۰-۹) باشد");
          return false;
        }
        return { SaadCode: normalized, University: university };
      },
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary mx-2",
      },
    });

    if (formValues) {
      Swal.fire({
        title: "در حال ارسال...",
        text: "لطفاً صبر کنید",
        allowOutsideClick: false,
        showConfirmButton: false,
        willOpen: () => {
          Swal.showLoading();
        },
        customClass: {
          popup: "swal2-rtl swal2-glass",
        },
      });

      try {
        const response = await secureFetch("API/updateConfig.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(formValues),
        });
        const result = await response.json();

        Swal.close();

        if (result.success) {
          Swal.fire({
            icon: "success",
            title: "ذخیره شد",
            html: `<div style="text-align:right;">${escapeHtml(
              result.message || "تنظیمات آپدیت شد."
            )}</div>`,
            confirmButtonText: "باشه",
            customClass: {
              popup: "swal2-rtl swal2-glass",
            },
          });

          const newConfig = await loadConfig();
          appConfig = newConfig;
          const footerText = document.getElementById("footerText");
          if (footerText) {
            footerText.textContent = newConfig.University
              ? `نسار - ${newConfig.University}`
              : "نسار";
          }
        } else {
          if (result.alreadyRegistered) {
            Swal.fire({
              icon: "error",
              title: "کد ساد تکراری",
              html: `<div style="text-align:right;line-height:2">${escapeHtml(
                result.error || "این کد ساد قبلاً ثبت شده است."
              )}</div>`,
              confirmButtonText: "تلاش مجدد",
              customClass: {
                popup: "swal2-rtl swal2-glass",
              },
            }).then(() => {
              loadConfig()
                .then((config) => showInitModal(config))
                .catch(() => showInitModal(currentConfig || {}));
            });
          } else {
            throw new Error(result.error || "خطا در آپدیت");
          }
        }
      } catch (error) {
        Swal.close();
        Swal.fire({
          icon: "error",
          title: "خطا",
          text: " ارتباط با سرور لایسنس برقرار نشد. چند دقیقه دیگر دوباره تلاش کنید.",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
          },
        }).then(() => {
          loadConfig()
            .then((config) => showInitModal(config))
            .catch(() => showInitModal(currentConfig || {}));
        });
      }
    }
  }

  function handleUserTypeChange() {
    studentIdInput.value = "";
    nationalIdInput.value = "";
    const persistChanges = !suppressUserTypePersistence;

    if (studentTypeRadio.checked) {
      firstFieldLabel.textContent = "شماره دانشجویی";
      secondFieldLabel.textContent = "کد ملی / شماره شناسنامه";
      studentIdInput.placeholder = "مثال: 403254321";
      nationalIdInput.placeholder = "مثال: 3781985569";
      studentIdInput.type = "tel";
      studentIdInput.inputMode = "numeric";
      studentIdInput.autocomplete = "off";
      nationalIdInput.type = "tel";
      nationalIdInput.inputMode = "numeric";
      nationalIdInput.autocomplete = "off";
      if (persistChanges) {
        localStorage.setItem("userType", "student");
      }
    } else if (staffTypeRadio?.checked) {
      firstFieldLabel.textContent = "نام کاربری";
      secondFieldLabel.textContent = "رمز عبور";
      studentIdInput.placeholder = "";
      nationalIdInput.placeholder = "";
      studentIdInput.type = "text";
      studentIdInput.inputMode = "text";
      studentIdInput.autocomplete = "username";
      nationalIdInput.type = "password";
      nationalIdInput.inputMode = "text";
      nationalIdInput.autocomplete = "current-password";
      if (persistChanges) {
        localStorage.setItem("userType", "staff");
      }
    } else if (coworkerTypeRadio?.checked) {
      firstFieldLabel.textContent = "نام کاربری";
      secondFieldLabel.textContent = "رمز عبور";
      studentIdInput.placeholder = "";
      nationalIdInput.placeholder = "";
      studentIdInput.type = "text";
      studentIdInput.inputMode = "text";
      studentIdInput.autocomplete = "username";
      nationalIdInput.type = "password";
      nationalIdInput.inputMode = "text";
      nationalIdInput.autocomplete = "current-password";
      if (persistChanges) {
        localStorage.setItem("userType", "coworker");
      }
    }

    if (!staffTypeRadio?.checked) {
      hideCaptchaChallenge();
    }

    suppressUserTypePersistence = false;
  }

  function shouldHideStaffRadio() {
    const ua = (navigator.userAgent || "").toLowerCase();
    const mobileUA = /android|iphone|ipad|ipod|mobile/.test(ua);
    const coarsePointer = window.matchMedia("(pointer: coarse)").matches;
    const narrowViewport = window.matchMedia("(max-width: 991px)").matches;
    const desktopViewport = window.matchMedia("(min-width: 992px)").matches;
    return !desktopViewport && (mobileUA || coarsePointer || narrowViewport);
  }

  function enforceStaffOptionVisibility() {
    const previousState = staffRadioHidden;
    const hide = shouldHideStaffRadio();
    staffRadioHidden = hide;

    if (hide) {
      if (staffRadioWrapper) {
        staffRadioWrapper.classList.add("d-none");
      }
      if (staffTypeRadio) {
        staffTypeRadio.checked = false;
        staffTypeRadio.disabled = true;
      }
    } else {
      if (staffRadioWrapper) {
        staffRadioWrapper.classList.remove("d-none");
      }
      if (staffTypeRadio) {
        staffTypeRadio.disabled = false;
      }
    }

    return previousState !== hide;
  }

  studentTypeRadio.addEventListener("change", handleUserTypeChange);
  if (staffTypeRadio) {
    staffTypeRadio.addEventListener("change", handleUserTypeChange);
  }
  if (coworkerTypeRadio) {
    coworkerTypeRadio.addEventListener("change", handleUserTypeChange);
  }

  enforceStaffOptionVisibility();

  const savedUserType = localStorage.getItem("userType");
  if (savedUserType === "staff") {
    if (staffTypeRadio && !staffRadioHidden) {
      staffTypeRadio.checked = true;
    } else {
      suppressUserTypePersistence = true;
      studentTypeRadio.checked = true;
    }
  } else if (savedUserType === "coworker" && coworkerTypeRadio) {
    coworkerTypeRadio.checked = true;
  } else {
    studentTypeRadio.checked = true;
  }

  handleUserTypeChange();

  window.addEventListener("resize", () => {
    if (enforceStaffOptionVisibility()) {
      if (staffRadioHidden) {
        suppressUserTypePersistence = true;
        studentTypeRadio.checked = true;
      } else {
        const activePreference = localStorage.getItem("userType");
        if (activePreference === "staff" && staffTypeRadio) {
          staffTypeRadio.checked = true;
        } else if (activePreference === "coworker" && coworkerTypeRadio) {
          coworkerTypeRadio.checked = true;
        } else {
          studentTypeRadio.checked = true;
        }
      }
      handleUserTypeChange();
    }
  });

  updateServerClock().then(() => {
    try {
      startSecondTicker();
    } catch (e) {}
  });
  if (footerClock && footerSpacer)
    footerSpacer.textContent = footerClock.textContent;
  startClockRefresh();

  const copyrightFooter = document.getElementById("copyrightFooter");
  if (copyrightFooter) {
    copyrightFooter.addEventListener("click", async () => {
      let countdownInterval;

      if (!appConfig) {
        appConfig = await loadConfig();
      }
      const university = appConfig.University || "دانشگاه پیام نور مرکز بیجار";
      Swal.fire({
        title: "درباره نِسار",
        html: `
                    <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
                        نِسار (نسخه ${VERSION}) یک وب‌اپلیکیشن پیشرفته و مدرن است که با بهره‌گیری از طراحی مبتنی بر تجربه کاربری نوین و سبک گلس‌مورفیسم، به دانشجویان دانشگاه پیام نور این امکان را می‌دهد تا برنامه امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را به‌صورت یکپارچه و متمرکز مشاهده کنند.
                        <br>
                        این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(
                          university
                        )}</span> و توسط <a href="https://t.me/RealDream" target="_blank" style="color: gold; font-weight: bold; text-decoration: none; border: none; outline: none;">مهدی حسنی</a> توسعه یافته است
                    </div>
                    <div class="swal2-countdown">
                        <span class="swal2-countdown-value">${toPersianDigits(
                          30
                        )}</span>
                    </div>
                `,
        timer: 30000,
        showConfirmButton: false,
        allowOutsideClick: true,
        allowEscapeKey: true,
        customClass: {
          popup: "swal2-rtl swal2-glass",
        },
        didOpen: () => {
          const valueEl = Swal.getHtmlContainer()?.querySelector(
            ".swal2-countdown-value"
          );
          if (!valueEl) return;
          const updateCountdown = () => {
            const remaining = Swal.getTimerLeft();
            if (typeof remaining !== "number") return;
            const seconds = Math.max(0, Math.ceil(remaining / 1000));
            valueEl.textContent = toPersianDigits(seconds);
          };
          updateCountdown();
          countdownInterval = window.setInterval(updateCountdown, 250);
        },
        willClose: () => {
          if (countdownInterval) {
            window.clearInterval(countdownInterval);
          }
        },
      });
    });
  }

  let deferredPrompt = null;
  let shownInstallHint = false;

  const isStandalone =
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (!isStandalone) {
      showInstallToast();
    }
  });

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;

    Swal.fire({
      icon: "success",
      title: "نصب شد",
      text: "اپلیکیشن «نسار» با موفقیت به صفحه اصلی شما اضافه شد.",
      confirmButtonText: "باشه",
      buttonsStyling: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary btn-lg px-4",
      },
    });
  });

  function showInstallToast() {
    if (shownInstallHint) return;
    shownInstallHint = true;

    const isIOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    const isAndroid = /android/i.test(window.navigator.userAgent);

    if (isAndroid && deferredPrompt) {
      Swal.fire({
        title: "نصب به‌عنوان اپلیکیشن",
        html: "می‌توانید «نسار» را به صفحه اصلی اضافه کنید تا مثل یک اپ اجرا شود.",
        icon: "info",
        showCancelButton: true,
        confirmButtonText: "نصب کن",
        cancelButtonText: "بعداً",
        buttonsStyling: false,
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary mx-2",
          cancelButton: "btn btn-cancel mx-2",
        },
      }).then(async (result) => {
        if (result.isConfirmed && deferredPrompt) {
          deferredPrompt.prompt();
          const choice = await deferredPrompt.userChoice;
          deferredPrompt = null;
          if (choice.outcome === "accepted") {
          }
        }
      });
    } else if (isIOS && !isStandalone) {
      Swal.fire({
        title: "افزودن به صفحه اصلی (iOS)",
        html: `
                    <div style="text-align:right;line-height:1.9">
                      1) دکمه Share در Safari را بزنید.<br>
                      2) گزینه <b>Add to Home Screen</b> را انتخاب کنید.<br>
                      3) روی <b>Add</b> بزنید تا «نسار» به صفحه اصلی اضافه شود.
                    </div>
                `,
        icon: "info",
        confirmButtonText: "متوجه شدم",
        buttonsStyling: false,
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary btn-lg px-4",
        },
      });
    }
  }

  setTimeout(() => {
    if (!isStandalone) showInstallToast();
  }, 1500);

  const ENCRYPTION_KEY = "PNU_EXAM_SEAT_2025_SECRET_KEY";

  function encryptData(data) {
    try {
      const jsonString = JSON.stringify(data);
      const encoded = btoa(unescape(encodeURIComponent(jsonString)));
      return encoded;
    } catch (e) {
      console.error("Encryption failed:", e);
      return null;
    }
  }

  function decryptData(encryptedData) {
    try {
      const decoded = decodeURIComponent(escape(atob(encryptedData)));
      return JSON.parse(decoded);
    } catch (e) {
      console.error("Decryption failed:", e);
      return null;
    }
  }

  async function fetchExamPayload(studentId, nationalId) {
    const licenseCheck = await checkLicense();
    if (!licenseCheck.valid) {
      showLicenseExpiredAlert(licenseCheck.message);
      const licenseError = new Error("license_expired");
      licenseError.isLicenseError = true;
      throw licenseError;
    }

    const credentials = { student_id: studentId, national_id: nationalId };
    const encryptedData = encryptData(credentials);

    if (!encryptedData) {
      throw new Error("Failed to encrypt data");
    }

    const body = new FormData();
    body.append("encrypted_data", encryptedData);

    const response = await guardedFetch("API/getStudentExams.php", {
      method: "POST",
      body,
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    let payload;
    try {
      payload = await response.json();
    } catch (e) {
      throw new Error("Invalid JSON response");
    }
    if (payload && typeof payload === "object" && "error" in payload) {
      const userError = new Error(payload.error || "درخواست نامعتبر");
      userError.isUserError = true;
      throw userError;
    }
    if (!Array.isArray(payload)) throw new Error("Invalid response format");
    return payload;
  }

  async function checkLicense() {
    const GRACE_PERIOD_MS = 24 * 60 * 60 * 1000;
    const TRIAL_CACHE_MS = 15 * 60 * 1000;
    const PERMANENT_CACHE_MS = 24 * 60 * 60 * 1000;

    let licenseCache = {};
    try {
      const cacheResponse = await guardedFetch("API/getLicenseCache.php", {
        cache: "no-store",
      });
      if (cacheResponse.ok) {
        const cacheData = await cacheResponse.json();
        licenseCache = cacheData.cache || {};
      }
    } catch (cacheError) {
      console.warn("[License] ⚠ Unable to read license cache:", cacheError);
    }

    try {
      const nowMs = Date.now();
      const lastCheckedRaw = licenseCache?.lastChecked || "";
      const lastSuccessCheck = licenseCache?.lastSuccessCheck || "";
      const lastStatus = licenseCache?.lastStatus || "";
      const lastType = licenseCache?.currentType || "";

      if (lastStatus === "valid" && lastCheckedRaw) {
        const lastCheckedDate = new Date(lastCheckedRaw);
        if (!Number.isNaN(lastCheckedDate.getTime())) {
          const cacheWindow =
            lastType === "permanent" ? PERMANENT_CACHE_MS : TRIAL_CACHE_MS;
          if (nowMs - lastCheckedDate.getTime() < cacheWindow) {
            console.log("[License] ✓ Using cached status");
            return {
              valid: true,
              message: "لایسنس اخیراً بررسی شده است",
              skipCheck: true,
              licenseType: lastType || "unknown",
            };
          }
        }
      }

      const tokenResponse = await guardedFetch("API/getLicenseToken.php", {
        cache: "no-store",
      });
      if (!tokenResponse.ok) {
        console.warn("[License] ⚠ Could not fetch license token");
        return await handleLicenseServerError(
          lastSuccessCheck,
          GRACE_PERIOD_MS,
          "عدم دسترسی به توکن لایسنس"
        );
      }

      const tokenData = await tokenResponse.json();
      if (tokenData.error || !tokenData.LicenseToken) {
        console.warn("[License] ⚠ License token not found");
        return await handleLicenseServerError(
          lastSuccessCheck,
          GRACE_PERIOD_MS,
          "توکن لایسنس یافت نشد"
        );
      }

      const licenseToken = tokenData.LicenseToken;
      const webhookUrl = "https://wfa.pnubijar.ac.ir/webhook/LC";
      let webhookResponse;

      try {
        webhookResponse = await fetch(
          `${webhookUrl}?LicenseToken=${encodeURIComponent(licenseToken)}`,
          {
            method: "GET",
            headers: { Accept: "application/json" },
            cache: "no-store",
            signal: AbortSignal.timeout(5000),
          }
        );
      } catch (fetchError) {
        console.warn("[License] ⚠ Webhook request failed:", fetchError.message);
        return await handleLicenseServerError(
          lastSuccessCheck,
          GRACE_PERIOD_MS,
          "عدم دسترسی به سرور لایسنس"
        );
      }

      if (!webhookResponse.ok) {
        console.warn(
          "[License] ⚠ Webhook returned error:",
          webhookResponse.status
        );
        return await handleLicenseServerError(
          lastSuccessCheck,
          GRACE_PERIOD_MS,
          "سرور لایسنس پاسخ نداد"
        );
      }

      const licenseData = await webhookResponse.json();
      if (!licenseData || typeof licenseData !== "object") {
        console.warn("[License] ⚠ Invalid license response");
        return await handleLicenseServerError(
          lastSuccessCheck,
          GRACE_PERIOD_MS,
          "پاسخ نامعتبر از سرور لایسنس"
        );
      }

      const licenceType = licenseData.LicenceType || "";
      const exp = licenseData.Exp || "";

      if (licenceType === "Licenced") {
        await updateLicenseLastChecked();
        await updateLicenseStatus("valid");
        console.log("[License] ✓ Permanent license active");
        return {
          valid: true,
          message: "لایسنس فعال",
          licenseType: "permanent",
        };
      }

      if (licenceType === "FullLicenced") {
        if (!exp) {
          await updateLicenseStatus("invalid");
          console.error("[License] ✗ Expiry date missing");
          return { valid: false, message: "تاریخ انقضا یافت نشد" };
        }

        const expTimestamp = new Date(exp).getTime();
        const currentTimestamp = Date.now();
        if (!Number.isFinite(expTimestamp)) {
          await updateLicenseStatus("invalid");
          console.error("[License] ✗ Invalid expiry format");
          return { valid: false, message: "تاریخ انقضا نامعتبر است" };
        }

        if (expTimestamp > currentTimestamp) {
          await updateLicenseLastChecked();
          await updateLicenseStatus("valid");

          const hoursRemaining = Math.floor(
            (expTimestamp - currentTimestamp) / (1000 * 60 * 60)
          );
          console.log(
            `[License] ✓ Trial license active (${hoursRemaining}h remaining)`
          );

          return {
            valid: true,
            message: "دوره آزمایشی فعال",
            licenseType: "trial",
            expiry: exp,
          };
        }

        await updateLicenseStatus("invalid");
        console.error("[License] ✗ License expired");
        return {
          valid: false,
          message:
            "دوره آزمایشی پایان یافته، در صورتی که کاربر این سامانه هستید لطفاً به ادمین اطلاع دهید تا نسبت به فعال‌سازی لایسنس اقدام نماید.",
        };
      }

      await updateLicenseStatus("invalid");
      console.error("[License] ✗ Invalid license type:", licenceType);
      return { valid: false, message: "نوع لایسنس نامعتبر است" };
    } catch (error) {
      console.error("[License] ⚠ Exception:", error);
      const lastSuccessCheck = licenseCache?.lastSuccessCheck || "";
      return await handleLicenseServerError(
        lastSuccessCheck,
        GRACE_PERIOD_MS,
        "خطا در بررسی لایسنس"
      );
    }
  }

  // مدیریت خطای سرور لایسنس با Grace Period
  async function handleLicenseServerError(
    lastSuccessCheck,
    graceWindowMs,
    errorMessage
  ) {
    const now = Date.now();
    await updateLicenseStatus("error");

    if (lastSuccessCheck) {
      const lastSuccessDate = new Date(lastSuccessCheck);
      if (!Number.isNaN(lastSuccessDate.getTime())) {
        const timeSinceSuccess = now - lastSuccessDate.getTime();
        if (timeSinceSuccess < graceWindowMs) {
          const hoursRemaining = Math.floor(
            (graceWindowMs - timeSinceSuccess) / (1000 * 60 * 60)
          );
          console.log(
            `[License] ⚡ Grace Period active (${hoursRemaining}h remaining)`
          );
          return {
            valid: true,
            message: `${errorMessage} (دسترسی موقت: ${hoursRemaining} ساعت)`,
            gracePeriod: true,
          };
        }
      }
    }

    console.warn("[License] ⚡ Fallback: Allowing temporary access");
    return {
      valid: true,
      message: `${errorMessage} (دسترسی موقت)`,
      fallback: true,
    };
  }

  async function updateLicenseStatus(status) {
    try {
      await guardedFetch("API/updateLicenseStatus.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          status: status,
        }),
      });
    } catch (error) {
      console.warn("[License] Could not update status:", error);
    }
  }

  async function updateLicenseLastChecked() {
    try {
      const response = await guardedFetch("API/updateLicenseLastChecked.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          update: true,
        }),
      });

      if (response.ok) {
        const result = await response.json();
        console.log("License last checked date updated", result);
      }
    } catch (error) {
      console.warn("Failed to update license last checked:", error);
    }
  }

  function showLicenseExpiredAlert(message) {
    const trimmedMessage = (message || "").trim();
    if (licenseAlertShown) {
      if (trimmedMessage && trimmedMessage !== lastLicenseAlertMessage) {
        console.warn(
          "[License] Duplicate alert suppressed with new message:",
          trimmedMessage
        );
      }
      return;
    }
    licenseAlertShown = true;
    lastLicenseAlertMessage = trimmedMessage;
    const fallback =
      "دوره آزمایشی پایان یافته، در صورتی که کاربر این سامانه هستید لطفاً به ادمین اطلاع دهید تا نسبت به فعال‌سازی لایسنس اقدام نماید.";
    const combined = trimmedMessage || fallback;
    const splitter =
      combined.indexOf("،") > -1
        ? "،"
        : combined.indexOf(".") > -1
        ? "."
        : null;
    let titleText = combined;
    let bodyText = "";

    if (splitter) {
      const index = combined.indexOf(splitter);
      titleText = combined.slice(0, index).trim();
      bodyText = combined.slice(index + 1).trim();
    }

    Swal.fire({
      icon: "error",
      title: escapeHtml(titleText || "دسترسی محدود شد"),
      html: bodyText
        ? `<div style="text-align:justify;line-height:1.9;direction:rtl">${escapeHtml(
            bodyText
          )}</div>`
        : "",
      allowOutsideClick: false,
      allowEscapeKey: false,
      allowEnterKey: false,
      showConfirmButton: false,
      showCloseButton: false,
      buttonsStyling: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
      },
    });
  }

  async function updateServerClock() {
    if (!footerClock) return;
    try {
      const response = await guardedFetch("API/serverTime.php", {
        cache: "no-store",
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (!payload || !payload.date || !payload.time) {
        throw new Error("Invalid payload structure");
      }

      const formattedDate = toPersianDigits(payload.date);
      const formattedTime = toPersianDigits(payload.time);
      const formattedStamp = `${formattedDate} | ${formattedTime}`;
      footerClock.textContent = formattedStamp;
      if (footerSpacer) footerSpacer.textContent = formattedStamp;

      try {
        const timeParts = String(payload.time)
          .split(":")
          .map((s) => parseInt(toEnglishDigits(s), 10));
        const parts = String(payload.date)
          .split("/")
          .map((p) => parseInt(p, 10));
        const h = timeParts[0] || 0;
        const m = timeParts[1] || 0;
        const s = timeParts[2] || 0;
        if (parts.length === 3 && !Number.isNaN(h) && !Number.isNaN(m)) {
          const [y, mm, d] = parts;
          const serverDate = new Date(y, mm - 1, d, h, m, s, 0);
          baseServerMs = serverDate.getTime();
          basePerf =
            typeof performance !== "undefined" && performance.now
              ? performance.now()
              : Date.now();
        }
      } catch (e) {}
    } catch (error) {
      console.warn("Clock update failed:", error);
    }
  }

  function formatWithSeconds(ms) {
    const d = new Date(ms);
    const datePart = `${toPersianDigits(d.getFullYear())}/${toPersianDigits(
      String(d.getMonth() + 1).padStart(2, "0")
    )}/${toPersianDigits(String(d.getDate()).padStart(2, "0"))}`;
    const timePart = `${toPersianDigits(
      String(d.getHours()).padStart(2, "0")
    )}:${toPersianDigits(
      String(d.getMinutes()).padStart(2, "0")
    )}:${toPersianDigits(String(d.getSeconds()).padStart(2, "0"))}`;
    return `${datePart} | ${timePart}`;
  }

  function startSecondTicker() {
    if (secondTimer) return;
    const tick = () => {
      const perfNow =
        typeof performance !== "undefined" && performance.now
          ? performance.now()
          : Date.now();
      const elapsed = perfNow - basePerf;
      const nowMs = baseServerMs + Math.round(elapsed);
      if (footerClock) footerClock.textContent = formatWithSeconds(nowMs);
      if (footerSpacer) footerSpacer.textContent = footerClock.textContent;
    };

    tick();
    secondTimer = setInterval(() => {
      if (document.hidden) return;
      tick();
    }, 1000);
  }

  function stopSecondTicker() {
    if (secondTimer) {
      clearInterval(secondTimer);
      secondTimer = null;
    }
  }

  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      stopSecondTicker();
    } else {
      updateServerClock().then(() => startSecondTicker());
    }
  });

  function startClockRefresh() {
    if (clockTimer) clearTimeout(clockTimer);

    const scheduleNextTick = () => {
      const now = new Date();
      const elapsed = now.getSeconds() * 1000 + now.getMilliseconds();
      const remainder = elapsed % CLOCK_REFRESH_MS;
      const delay =
        remainder === 0 ? CLOCK_REFRESH_MS : CLOCK_REFRESH_MS - remainder;

      clockTimer = setTimeout(async () => {
        stopSecondTicker();
        await updateServerClock();
        startSecondTicker();
        scheduleNextTick();
      }, delay);
    };

    scheduleNextTick();
  }

  function stopAutoRefresh() {
    if (refreshTimer) {
      clearTimeout(refreshTimer);
      refreshTimer = null;
    }
  }

  function startAutoRefresh(studentId, nationalId) {
    currentCredentials = { studentId, nationalId };
    stopAutoRefresh();
    const scheduleNextRefresh = () => {
      const now = new Date();
      const elapsed = now.getSeconds() * 1000 + now.getMilliseconds();
      const remainder = elapsed % REFRESH_INTERVAL_MS;
      const delay =
        remainder === 0 ? REFRESH_INTERVAL_MS : REFRESH_INTERVAL_MS - remainder;

      refreshTimer = setTimeout(async () => {
        updateServerClock();
        try {
          const payload = await fetchExamPayload(
            currentCredentials.studentId,
            currentCredentials.nationalId
          );
          const snapshot = JSON.stringify(payload || []);
          if (snapshot === lastSnapshot) {
            const needsReorder = updateCountdowns();
            if (needsReorder) {
              const firstExam = payload[0] || {};
              const refreshedName = `${firstExam.first_name || ""} ${
                firstExam.last_name || ""
              }`.trim();
              lastFullName = refreshedName || lastFullName;
              renderResults(
                payload,
                lastFullName,
                currentCredentials.studentId
              );
            }
            return;
          }
          lastSnapshot = snapshot;
          const first = payload[0] || {};
          const fullName = `${first.first_name || ""} ${
            first.last_name || ""
          }`.trim();
          lastFullName = fullName;
          renderResults(payload, fullName, currentCredentials.studentId);
        } catch (error) {
          console.warn("Auto-refresh failed:", error);
          if (error?.isLicenseError) {
            // توقف refresh در صورت انقضای لایسنس
            stopAutoRefresh();
            return;
          }
        } finally {
          if (currentCredentials) {
            scheduleNextRefresh();
          }
        }
      }, delay);
    };

    scheduleNextRefresh();
  }

  function hideLogin() {
    const target = document.getElementById("loginRow") || loginSection;
    if (target) target.classList.add("d-none");
    document.body.classList.remove("login-active");
  }

  function showLogin() {
    const target = document.getElementById("loginRow") || loginSection;
    if (target) target.classList.remove("d-none");
    document.body.classList.add("login-active");
  }

  function showCaptchaChallenge(challenge) {
    if (!captchaContainer || !challenge) {
      return;
    }
    captchaContainer.classList.remove("d-none");
    if (captchaQuestion) {
      captchaQuestion.textContent = challenge.question || "؟";
    }
    if (captchaTokenInput) {
      captchaTokenInput.value = challenge.token || "";
    }
    if (captchaAnswerInput) {
      captchaAnswerInput.value = "";
      captchaAnswerInput.focus({ preventScroll: true });
    }
  }

  function hideCaptchaChallenge() {
    if (captchaContainer) {
      captchaContainer.classList.add("d-none");
    }
    if (captchaQuestion) {
      captchaQuestion.textContent = "";
    }
    if (captchaTokenInput) {
      captchaTokenInput.value = "";
    }
    if (captchaAnswerInput) {
      captchaAnswerInput.value = "";
    }
  }

  function handleCaptchaFromResponse(result) {
    if (result?.captchaRequired && result?.captcha) {
      showCaptchaChallenge(result.captcha);
    } else if (!result?.captchaRequired) {
      hideCaptchaChallenge();
    }
  }

  function clearResults() {
    if (examCards) examCards.textContent = "";
    lastPayload = [];
    lastFullName = "";
    lastStudentId = "";
    // کلاس‌های session رو دست نزن - فقط محتوا رو پاک کن
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    let mode = "student";
    if (staffTypeRadio?.checked) {
      mode = "staff";
    }
    if (coworkerTypeRadio?.checked) {
      mode = "coworker";
    }

    const firstValue = toEnglishDigits(studentIdInput.value).trim();
    const secondValue = toEnglishDigits(nationalIdInput.value).trim();

    if (mode === "staff") {
      const username = firstValue;
      const password = secondValue;

      if (!username || !password) {
        showAlert(
          "warning",
          "خطا!",
          "وارد کردن نام کاربری و رمز عبور الزامی است."
        );
        return;
      }

      const captchaPayload = {
        captchaToken: captchaTokenInput?.value || "",
        captchaAnswer: toEnglishDigits(captchaAnswerInput?.value || "").trim(),
      };

      try {
        const response = await secureFetch("API/adminLogin.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            username,
            password,
            captchaToken: captchaPayload.captchaToken,
            captchaAnswer: captchaPayload.captchaAnswer,
          }),
        });

        const result = await response.json();
        handleCaptchaFromResponse(result);
        if (!response.ok || !result.success) {
          const message = result?.error || "نام کاربری یا رمز عبور اشتباه است.";
          showAlert("error", "ورود ناموفق", message);
          return;
        }

        hideCaptchaChallenge();
        const actor = String(result.actor || "admin").toLowerCase();

        if (actor === "recipient") {
          window.location.href = "Recipient/";
          return;
        }

        try {
          appConfig = await loadConfig();
        } catch (configError) {
          console.warn("Failed to refresh config after login:", configError);
        }

        const missingFields = Array.isArray(result.missingFields)
          ? result.missingFields
          : [];
        if (missingFields.length) {
          const config = appConfig || {};
          const { value: formValues } = await Swal.fire({
            title: "اطلاعات امضاءکنندگان",
            html: `
                                <div style="text-align:right;direction:rtl;">
                                    <label style="font-weight:600;">نام نمایشی شما (نمایش در داشبورد)</label>
                                    <input id="swal-adminnick" class="swal2-input" value="${escapeHtml(
                                      config.AdminNickName || ""
                                    )}" placeholder="مثال: آرتین حسنی">

                                    <label style="font-weight:600;">نام و نام خانوادگی رئیس مرکز</label>
                                    <input id="swal-boss" class="swal2-input" value="${escapeHtml(
                                      config.BossNickName || ""
                                    )}" placeholder="مثال: دكتر الهام قاسمي فر">

                                    <label style="font-weight:600;">نام و نام خانوادگی رئیس اداره آموزش</label>
                                    <input id="swal-headofedu" class="swal2-input" value="${escapeHtml(
                                      config.HeadOfEDU || ""
                                    )}" placeholder="مثال: مهدی حسنی">

                                    <label style="font-weight:600;">نام و نام خانوادگی مسئول جلسه</label>
                                    <input id="swal-chairman" class="swal2-input" value="${escapeHtml(
                                      config.Chairman || ""
                                    )}" placeholder="مثال: سید احمد موسوی">
                                </div>
                            `,
            focusConfirm: false,
            showCancelButton: false,
            confirmButtonText: "ذخیره",
            preConfirm: () => {
              const admin =
                document.getElementById("swal-adminnick")?.value.trim() || "";
              const boss =
                document.getElementById("swal-boss")?.value.trim() || "";
              const headofedu =
                document.getElementById("swal-headofedu")?.value.trim() || "";
              const chairman =
                document.getElementById("swal-chairman")?.value.trim() || "";
              if (!admin || !boss || !headofedu || !chairman) {
                Swal.showValidationMessage("لطفاً همهٔ فیلدها را پر کنید");
                return false;
              }
              return {
                AdminNickName: admin,
                BossNickName: boss,
                HeadOfEDU: headofedu,
                Chairman: chairman,
              };
            },
            customClass: { popup: "swal2-rtl swal2-glass" },
          });

          if (formValues) {
            try {
              const saveResponse = await guardedFetch("API/saveConfig.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(formValues),
              });
              const saveResult = await saveResponse.json();
              if (!saveResult.success) {
                throw new Error(
                  saveResult.error || "Failed to save configuration"
                );
              }

              appConfig = await loadConfig();
            } catch (error) {
              console.error("Error saving admin/signature info:", error);
              showAlert("error", "خطا", "خطا در ذخیره اطلاعات مدیریت");
              return;
            }
          }
        }

        window.location.href = "dashboard/";
        return;
      } catch (error) {
        console.error("Admin login error:", error);
        showAlert("error", "خطا", "مشکلی در احراز هویت رخ داده است.");
        return;
      }
    }

    if (mode === "coworker") {
      if (!firstValue || !secondValue) {
        showAlert(
          "warning",
          "خطا!",
          "وارد کردن نام کاربری و رمز عبور الزامی است."
        );
        return;
      }

      const coworkerNationalId = firstValue.replace(/[^0-9]/g, "").trim();
      const coworkerPhone = secondValue.replace(/[^0-9]/g, "").trim();

      if (
        coworkerNationalId.length !== 10 ||
        (coworkerPhone.length !== 10 && coworkerPhone.length !== 11)
      ) {
        showAlert("warning", "خطا!", "نام کاربری یا رمز عبور صحیح نیست.");
        return;
      }

      stopAutoRefresh();
      currentCredentials = null;
      lastSnapshot = "";
      lastPayload = [];
      lastFullName = "";

      toggleLoading(true);
      clearResults();
      try {
        const payload = await fetchCoworkerSessionsPayload(
          coworkerNationalId,
          coworkerPhone
        );
        persistCoworkerSession({
          national_id: coworkerNationalId,
          phone: coworkerPhone,
        });
        renderCoworkerSessions(payload, {
          nationalId: coworkerNationalId,
          phone: coworkerPhone,
        });
      } catch (error) {
        console.error("Coworker login error:", error);
        const message =
          error && error.isUserError
            ? error.message
            : "امکان ورود عوامل اجرائی در حال حاضر وجود ندارد.";
        showAlert("error", "ورود ناموفق", message);
      } finally {
        toggleLoading(false);
      }
      return;
    }

    const studentId = firstValue.replace(/[^0-9]/g, "").trim();
    const nationalId = secondValue.replace(/[^0-9]/g, "").trim();

    if (!studentId || !nationalId) {
      showAlert(
        "warning",
        "خطا!",
        "وارد کردن نام کاربری و رمز عبور الزامی است."
      );
      return;
    }

    stopAutoRefresh();
    currentCredentials = null;
    lastSnapshot = "";
    lastPayload = [];
    lastFullName = "";

    toggleLoading(true);
    clearResults();
    updateServerClock();
    try {
      const payload = await fetchExamPayload(studentId, nationalId);

      if (payload.length === 0) {
        showAlert(
          "info",
          "توجه",
          "هیچ امتحانی برای اطلاعات وارد شده یافت نشد."
        );
        return;
      }

      const first = payload[0] || null;
      const fullName = first
        ? `${first.first_name || ""} ${first.last_name || ""}`.trim()
        : "";
      lastFullName = fullName;
      try {
        const sessionInfo = {
          student_id: studentId,
          national_id: nationalId,
          first_name: first?.first_name || "",
          last_name: first?.last_name || "",
          stored_at: Date.now(),
        };
        localStorage.setItem("userSession", JSON.stringify(sessionInfo));
      } catch (storageError) {
        console.warn("Failed to persist user session", storageError);
      }

      renderResults(payload, fullName, studentId);
      lastSnapshot = JSON.stringify(payload || []);
      startAutoRefresh(studentId, nationalId);
    } catch (error) {
      console.error("Fetch error:", error);
      if (error && error.isLicenseError) {
      } else if (error && error.isUserError) {
        showAlert(
          "warning",
          "ورود ناموفق",
          "رمز عبور و شماره دانشجویی صحیح نیست یا اطلاعاتی برای این شماره وجود ندارد."
        );
      } else {
        showAlert(
          "error",
          "خطا در اتصال!",
          "مشکلی در ارتباط با سرور رخ داده است. لطفاً بعداً تلاش کنید."
        );
      }
    } finally {
      toggleLoading(false);
    }
  });

  (async function autoLoginFromStorage() {
    if (savedUserType === "coworker") {
      await autoLoginCoworkerFromStorage();
      return;
    }

    if (savedUserType === "staff") {
      showLogin();
      return;
    }

    let stored = null;
    try {
      stored = JSON.parse(localStorage.getItem("userSession") || "null");
    } catch (parseError) {
      console.warn("Failed to parse stored session", parseError);
      localStorage.removeItem("userSession");
    }

    if (!stored || !stored.student_id || !stored.national_id) {
      stopAutoRefresh();
      currentCredentials = null;
      lastSnapshot = "";
      lastPayload = [];
      showLogin();
      return;
    }

    const sid = toEnglishDigits(String(stored.student_id)).trim();
    const nid = toEnglishDigits(String(stored.national_id)).trim();
    if (!sid || !nid) {
      localStorage.removeItem("userSession");
      showLogin();
      return;
    }

    try {
      hideLogin();
      toggleLoading(true);
      clearResults();
      updateServerClock();

      const payload = await fetchExamPayload(sid, nid);
      if (!Array.isArray(payload) || payload.length === 0) {
        throw new Error("هیچ امتحانی یافت نشد");
      }

      const first = payload[0];
      const fullName = `${first?.first_name || ""} ${
        first?.last_name || ""
      }`.trim();

      try {
        const refreshedSession = {
          student_id: sid,
          national_id: nid,
          first_name: first?.first_name || "",
          last_name: first?.last_name || "",
          stored_at: Date.now(),
        };
        localStorage.setItem("userSession", JSON.stringify(refreshedSession));
      } catch (storageError) {
        console.warn("Failed to refresh stored session", storageError);
      }

      lastFullName = fullName;
      renderResults(payload, fullName, sid);
      lastSnapshot = JSON.stringify(payload || []);
      startAutoRefresh(sid, nid);
    } catch (e) {
      console.warn("Auto-login failed:", e);
      stopAutoRefresh();
      currentCredentials = null;
      lastSnapshot = "";
      lastPayload = [];
      lastFullName = "";
      if (!e?.isLicenseError) {
        localStorage.removeItem("userSession");
        try {
          await secureFetch("API/userLogout.php", { method: "POST" });
        } catch (logoutErr) {
          console.debug("userLogout failed", logoutErr);
        }
      }
      if (e?.isLicenseError) {
      } else if (!e?.isUserError) {
        showAlert(
          "error",
          "خطا در اتصال!",
          "مشکلی در ارتباط با سرور رخ داده است. لطفاً بعداً تلاش کنید."
        );
      }
      if (!e?.isLicenseError) {
        showLogin();
      }
    } finally {
      toggleLoading(false);
    }
  })();

  function toggleLoading(isLoading) {
    const spinner = searchBtn.querySelector(".spinner-border");
    const text = searchBtn.querySelector(".text");

    if (isLoading) {
      spinner.classList.remove("d-none");
      text.textContent = "در حال جستجو...";
      searchBtn.disabled = true;
    } else {
      spinner.classList.add("d-none");
      text.textContent = "جستجو";
      searchBtn.disabled = false;
    }
  }

  function renderResults(exams, fullName, studentId) {
    const previousScroll = window.scrollY || 0;
    const now = Date.now();
    const normalizedFullName =
      typeof fullName === "string" ? fullName.trim() : "";
    if (normalizedFullName) {
      lastFullName = normalizedFullName;
    }
    const resolvedName = (lastFullName || "").trim();

    const providedStudentId =
      typeof studentId === "number" || typeof studentId === "string"
        ? String(studentId).trim()
        : "";
    if (providedStudentId) {
      lastStudentId = providedStudentId;
    }
    const resolvedStudentId = (lastStudentId || "").trim();

    const safeExams = Array.isArray(exams) ? exams : [];

    const decorated = safeExams.map((exam, idx) => {
      const target = createExamDateTime(exam.exam_date, exam.exam_time);
      const timeValue = target ? target.getTime() : 0;
      const isUpcoming = target ? timeValue > now : false;
      return { exam, idx, target, timeValue, isUpcoming };
    });

    const upcoming = decorated
      .filter((item) => item.isUpcoming)
      .sort((a, b) => (a.timeValue || Infinity) - (b.timeValue || Infinity));

    const past = decorated
      .filter((item) => !item.isUpcoming)
      .sort((a, b) => (b.timeValue || -Infinity) - (a.timeValue || -Infinity));

    const htmlParts = [];
    const hasIdentity = Boolean(resolvedName) || Boolean(resolvedStudentId);

    if (hasIdentity) {
      htmlParts.push(`
                <div class="session-card" data-role="session-card">
                    <div class="session-info">
                        <div class="session-name">${escapeHtml(
                          resolvedName || "کاربر نسار"
                        )}</div>
                        ${
                          resolvedStudentId
                            ? `<div class="session-id">${toPersianDigits(
                                resolvedStudentId
                              )}</div>`
                            : ""
                        }
                    </div>
                    <button type="button" class="session-logout-btn">
                        <span class="session-logout-text">خروج</span>
                    </button>
                </div>
            `);
    }

    if (upcoming.length) {
      const upcomingMarkup = upcoming
        .map(({ exam, idx }) => {
          const seatNum = exam.seat_number || "";
          const isNumericSeat = /^\d+$/.test(seatNum.toString().trim());
          const seatClass = isNumericSeat ? "seat-available" : "seat-hidden";
          const countdownText = getCountdownText(
            exam.exam_date,
            exam.exam_time
          );
          const countdownMarkup = countdownText
            ? `<div class="exam-countdown">${countdownText}</div>`
            : "";
          return `
                <div class="exam-card ${seatClass} upcoming" tabindex="0" data-exam-origin="${idx}" data-exam-status="upcoming">
                    <div class="exam-title">
                        <span>${escapeHtml(exam.course_name)}</span>
                    </div>
                    <div class="exam-meta">${toPersianDigits(
                      exam.exam_date
                    )} | ${toPersianDigits(exam.exam_time)}</div>
                    ${countdownMarkup}
                </div>
            `;
        })
        .join("");
      htmlParts.push(upcomingMarkup);
    }

    if (upcoming.length && past.length) {
      htmlParts.push('<div class="exam-divider" role="presentation"></div>');
    }

    if (past.length) {
      const pastMarkup = past
        .map(({ exam, idx }) => {
          const seatNum = exam.seat_number || "";
          const isNumericSeat = /^\d+$/.test(seatNum.toString().trim());
          const seatClass = isNumericSeat ? "seat-available" : "seat-hidden";
          return `
                <div class="exam-card ${seatClass} past" tabindex="0" data-exam-origin="${idx}" data-exam-status="past">
                    <div class="exam-title">
                        <span>${escapeHtml(exam.course_name)}</span>
                    </div>
                </div>
            `;
        })
        .join("");
      htmlParts.push(pastMarkup);
    }

    examCards.innerHTML = htmlParts.join("");
    lastPayload = safeExams.slice();

    const logoutBtn = examCards.querySelector(".session-logout-btn");
    if (logoutBtn) {
      logoutBtn.addEventListener("click", async (event) => {
        event.preventDefault();
        event.stopPropagation();

        const confirmation = await Swal.fire({
          icon: "question",
          title: "خروج از حساب؟",
          html: '<div style="text-align:right;">با خروج، دسترسی شما به اطلاعات آزمون قطع می‌شود. آیا اطمینان دارید؟</div>',
          showCancelButton: true,
          confirmButtonText: "بله، خروج",
          cancelButtonText: "انصراف",
          reverseButtons: true,
          focusCancel: true,
          buttonsStyling: false,
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-danger mx-2",
            cancelButton: "btn btn-cancel mx-2",
          },
        });

        if (!confirmation.isConfirmed) {
          return;
        }

        stopAutoRefresh();
        currentCredentials = null;
        lastSnapshot = "";
        lastPayload = [];
        lastFullName = "";
        lastStudentId = "";
        try {
          await secureFetch("API/userLogout.php", { method: "POST" });
        } catch (logoutErr) {
          console.debug("userLogout failed", logoutErr);
        }
        localStorage.removeItem("userSession");
        clearResults();
        showLogin();
      });
    }

    const maxScroll = Math.max(
      0,
      document.body.scrollHeight - window.innerHeight
    );
    const targetScroll = Math.min(previousScroll, maxScroll);
    if (Math.abs(window.scrollY - targetScroll) > 1) {
      window.scrollTo(0, targetScroll);
    }

    const attachModal = (exam, status) => {
      return () => {
        if (status === "past") {
          const seatValue = (exam.seat_number ?? "").toString().trim();
          const typeParts = [];
          if (exam.exam_type) typeParts.push(escapeHtml(exam.exam_type));
          if (exam.course_type) typeParts.push(escapeHtml(exam.course_type));
          const typeSentence = typeParts.length
            ? ` به صورت ${typeParts.join(" و ")}`
            : "";
          let message = `آزمون درس ${escapeHtml(
            exam.course_name
          )} در تاریخ ${toPersianDigits(exam.exam_date)} ساعت ${toPersianDigits(
            exam.exam_time
          )}${typeSentence} برگزار گردیده`;
          if (seatValue) {
            message += ` و شماره صندلی شما در این آزمون ${toPersianDigits(
              seatValue
            )} بوده است.`;
          } else {
            message += " است.";
          }
          let countdownInterval;
          Swal.fire({
            title: toPersianDigits(exam.course_code),
            html: `
                                <div style="text-align:justify;direction:rtl;line-height:1.9;font-size:1.05em;">
                                    ${message}
                                </div>
                                <div class="swal2-countdown">
                                    <span class="swal2-countdown-value">${toPersianDigits(
                                      15
                                    )}</span>
                                </div>
                            `,
            timer: 15000,
            showConfirmButton: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            allowEnterKey: false,
            buttonsStyling: false,
            customClass: {
              popup: "swal2-rtl swal2-glass",
            },
            didOpen: () => {
              const valueEl = Swal.getHtmlContainer()?.querySelector(
                ".swal2-countdown-value"
              );
              if (!valueEl) return;
              const updateCountdown = () => {
                const remaining = Swal.getTimerLeft();
                if (typeof remaining !== "number") return;
                const seconds = Math.max(0, Math.ceil(remaining / 1000));
                valueEl.textContent = toPersianDigits(seconds);
              };
              updateCountdown();
              countdownInterval = window.setInterval(updateCountdown, 250);
            },
            willClose: () => {
              if (countdownInterval) {
                window.clearInterval(countdownInterval);
              }
            },
          });
          return;
        }

        const parts = exam.exam_date.split("/");
        const formattedDate = `${toPersianDigits(
          parts[2]
        )} ${getPersianMonthName(exam.exam_date)} ${toPersianDigits(parts[0])}`;
        Swal.fire({
          title: `${toPersianDigits(exam.course_code)}`,
          html: `
                        <div style="text-align:justify;direction:rtl;color:#d63333;font-weight:600;margin-bottom:0.6rem;font-size:0.95rem;">
                            ⚠️ همواره معیار شما برای حضور در جلسات آزمون باید گزارش ۴۲۸ سامانه گلستان باشد.
                        </div>
                        <div lang="fa" style="text-align:justify;direction:rtl;line-height:1.9;font-size:1.05em; hyphens: auto; -webkit-hyphens: auto; -moz-hyphens: auto;">
                            آزمون ${escapeHtml(exam.course_type)} ${escapeHtml(
            exam.course_name
          )} راس ساعت ${toPersianDigits(exam.exam_time)} روز ${
            exam.exam_day
          } ${formattedDate} به شیوه ${escapeHtml(
            exam.exam_type
          )} برگزار خواهد شد. ${
            /^\d+$/.test(exam.seat_number)
              ? `شماره صندلی شما ${toPersianDigits(exam.seat_number)} می‌باشد.`
              : exam.seat_number
          }${
            /^\d+$/.test(exam.seat_number)
              ? `<br><br>ساختمان: <span style="color: #007bff;">${
                  escapeHtml(exam.building) || "-"
                }</span><br>کلاس: <span style="color: #007bff;">${
                  escapeHtml(exam.class_name) || "-"
                }</span><br>ردیف: <span style="color: #007bff;">${
                  toPersianDigits(exam.seat_row) || "-"
                }</span>`
              : ""
          }
                        </div>
                    `,
          confirmButtonText: "بستن",
          buttonsStyling: false,
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary btn-lg px-4",
          },
        });
      };
    };

    const cards = examCards.querySelectorAll(".exam-card");
    cards.forEach((card) => {
      const origin = parseInt(card.getAttribute("data-exam-origin"), 10);
      if (Number.isNaN(origin) || !lastPayload[origin]) return;
      const status = card.getAttribute("data-exam-status");
      card.addEventListener("click", attachModal(lastPayload[origin], status));
    });

    hideLogin();
  }

  function buildCoworkerBadgeLine(session) {
    const parts = [];
    if (session.required_proctors) {
      parts.push(`${toPersianDigits(session.required_proctors)} مراقب`);
    }
    if (session.students_count) {
      parts.push(`${toPersianDigits(session.students_count)} دانشجو`);
    }
    if (session.course_count) {
      parts.push(`${toPersianDigits(session.course_count)} عنوان درسی`);
    }
    return parts.join(" · ");
  }

  function formatCoworkerMetaLine(session) {
    const datePart = session.exam_date
      ? toPersianDigits(session.exam_date)
      : "--/--/--";
    const timePart = session.exam_time
      ? `ساعت ${toPersianDigits(session.exam_time)}`
      : "ساعت --:--";
    return `${timePart} | ${datePart}`;
  }

  function renderCoworkerSessions(payload, credentials) {
    if (!examCards) return;
    coworkerSessions = Array.isArray(payload.sessions) ? payload.sessions : [];
    coworkerStats = payload.stats || null;
    coworkerProfile = payload.coworker || null;
    coworkerCredentials = credentials || null;

    const now = Date.now();
    const decorated = coworkerSessions.map((session, idx) => {
      const target = createExamDateTime(session.exam_date, session.exam_time);
      const timestamp = target ? target.getTime() : 0;
      return {
        session,
        idx,
        timestamp,
        isUpcoming: Boolean(target && timestamp > now),
      };
    });

    const upcoming = decorated
      .filter((item) => item.isUpcoming)
      .sort((a, b) => (a.timestamp || Infinity) - (b.timestamp || Infinity));

    const past = decorated
      .filter((item) => !item.isUpcoming)
      .sort((a, b) => (b.timestamp || -Infinity) - (a.timestamp || -Infinity));

    const htmlParts = [];
    const displayName =
      `${coworkerProfile?.first_name || ""} ${
        coworkerProfile?.last_name || ""
      }`.trim() || "همکار نسار";
    const nationalIdValue =
      coworkerProfile?.national_id || coworkerCredentials?.nationalId || "";
    const statsLine = coworkerStats
      ? `${toPersianDigits(
          coworkerStats.total_sessions || 0
        )} جلسه · ${toPersianDigits(
          coworkerStats.upcoming_sessions || 0
        )} باقی مانده`
      : "";

    htmlParts.push(`
            <div class="session-card" data-role="coworker-card">
                <div class="session-info">
                    <div class="session-name">${escapeHtml(displayName)}</div>
                    ${
                      nationalIdValue
                        ? `<div class="session-id">کد ملی: ${toPersianDigits(
                            nationalIdValue
                          )}</div>`
                        : ""
                    }
                    ${
                      statsLine
                        ? `<div class="session-meta">${statsLine}</div>`
                        : ""
                    }
                </div>
                <div class="session-actions">
                    <button type="button" class="session-logout-btn session-stats-btn" data-role="coworker-stats">
                        <span class="session-logout-text">آمار جلسات</span>
                    </button>
                    <button type="button" class="session-logout-btn" data-role="coworker-logout">
                        <span class="session-logout-text">خروج</span>
                    </button>
                </div>
            </div>
        `);

    const buildCardMarkup = (entry) => {
      const { session, idx } = entry;
      const badge = buildCoworkerBadgeLine(session);
      const countdownText =
        session.status !== "past"
          ? getCountdownText(session.exam_date, session.exam_time)
          : "";
      const seatClass =
        session.status === "past" ? "seat-hidden" : "seat-available";
      return `
                <div class="exam-card coworker-session-card ${seatClass} ${
        session.status
      }" tabindex="0" data-session-origin="${idx}">
                    <div class="exam-title text-center">${toPersianDigits(
                      session.exam_time
                    )} | ${toPersianDigits(session.exam_date)}</div>
                    ${
                      badge
                        ? `<div class="exam-detail">${escapeHtml(badge)}</div>`
                        : ""
                    }
                    ${
                      countdownText
                        ? `<div class="exam-countdown">${countdownText}</div>`
                        : ""
                    }
                </div>
            `;
    };

    if (upcoming.length) {
      htmlParts.push(upcoming.map((entry) => buildCardMarkup(entry)).join(""));
    }

    if (upcoming.length && past.length) {
      htmlParts.push('<div class="exam-divider" role="presentation"></div>');
    }

    if (past.length) {
      htmlParts.push(past.map((entry) => buildCardMarkup(entry)).join(""));
    }

    if (!upcoming.length && !past.length) {
      htmlParts.push(`
                <div class="exam-card seat-hidden past" data-role="coworker-empty">
                    <div class="exam-title">
                        <span>جلسه‌ای برای شما ثبت نشده است.</span>
                    </div>
                </div>
            `);
    }

    examCards.innerHTML = htmlParts.join("");
    hideLogin();

    const logoutBtn = examCards.querySelector('[data-role="coworker-logout"]');
    if (logoutBtn) {
      logoutBtn.addEventListener("click", (event) => {
        event.preventDefault();
        handleCoworkerLogout();
      });
    }

    const statsButton = examCards.querySelector('[data-role="coworker-stats"]');
    if (statsButton) {
      statsButton.addEventListener("click", (event) => {
        event.preventDefault();
        showCoworkerStatsModal();
      });
    }

    const cards = examCards.querySelectorAll(".exam-card[data-session-origin]");
    cards.forEach((card) => {
      const origin = parseInt(card.getAttribute("data-session-origin"), 10);
      if (Number.isNaN(origin) || !coworkerSessions[origin]) return;
      // Removed click handler for SweetAlert modal
    });
  }

  function handleCoworkerLogout() {
    stopAutoRefresh();
    currentCredentials = null;
    lastSnapshot = "";
    lastPayload = [];
    lastFullName = "";
    clearCoworkerSessionStorage();
    coworkerSessions = [];
    coworkerStats = null;
    coworkerProfile = null;
    coworkerCredentials = null;
    clearResults();
    showLogin();
  }

  function showCoworkerStatsModal() {
    if (!coworkerStats) {
      showAlert(
        "info",
        "آماری در دسترس نیست",
        "داده‌ای برای نمایش وجود ندارد."
      );
      return;
    }

    const rows = [
      { label: "مجموع جلسات", value: coworkerStats.total_sessions },
      { label: "روزهای حضور", value: coworkerStats.unique_days },
      { label: "جلسات باقی‌مانده", value: coworkerStats.upcoming_sessions },
      { label: "جلسات برگزارشده", value: coworkerStats.completed_sessions },
    ];

    const list = rows
      .map(
        (row) => `
                <div style="display:flex;justify-content:space-between;padding:0.35rem 0;border-bottom:1px solid rgba(148,163,184,0.25);">
                    <span>${row.label}</span>
                    <strong>${toPersianDigits(row.value ?? 0)}</strong>
                </div>
            `
      )
      .join("");

    let nextSessionHtml = "";
    if (coworkerStats.next_session) {
      const next = coworkerStats.next_session;
      const weekday = escapeHtml(next.weekday || "جلسه آزمون");
      const dateText = toPersianDigits(next.exam_date || "--/--/--");
      const timeText = toPersianDigits(next.exam_time || "--:--");
      nextSessionHtml = `
                <div style="margin-top:1rem;padding:0.8rem;border-radius:0.75rem;background:rgba(15,23,42,0.08);">
                    <div style="font-weight:600;margin-bottom:0.35rem;">جلسه بعدی</div>
                    <div>${weekday}&nbsp;&nbsp;${dateText} ساعت ${timeText}</div>
                </div>
            `;
    }

    Swal.fire({
      title: "تصویر لحظه‌ای حضور",
      html: `<div style="text-align:right;line-height:1.9;">${list}${nextSessionHtml}</div>`,
      confirmButtonText: "بستن",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }

  function showCoworkerSessionModal(session) {
    if (!session) return;
    const badge =
      buildCoworkerBadgeLine(session) ||
      "اطلاعات تکمیلی برای این جلسه ثبت نشده است.";
    const countdown =
      session.status !== "past" && typeof session.minutes_until === "number"
        ? `<div style="margin-top:0.8rem;color:#16a34a;">حدود ${toPersianDigits(
            Math.max(session.minutes_until, 0)
          )} دقیقه تا شروع جلسه</div>`
        : "";
    Swal.fire({
      title: escapeHtml(session.weekday || "جلسه آزمون"),
      html: `
                <div style="text-align:right;line-height:1.9;">
                    <div><strong>تاریخ:</strong> ${toPersianDigits(
                      session.exam_date || "-"
                    )}</div>
                    <div><strong>ساعت:</strong> ${toPersianDigits(
                      session.exam_time || "-"
                    )}</div>
                    <div style="margin-top:0.8rem;">${escapeHtml(badge)}</div>
                    ${countdown}
                </div>
            `,
      confirmButtonText: "بستن",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }

  function encodeCoworkerPayload(data) {
    try {
      const json = JSON.stringify(data);
      return btoa(unescape(encodeURIComponent(json)));
    } catch (error) {
      console.error("encodeCoworkerPayload failed", error);
      return null;
    }
  }

  async function fetchCoworkerSessionsPayload(nationalId, phone) {
    const encoded = encodeCoworkerPayload({
      national_id: nationalId,
      phone,
    });
    if (!encoded) {
      const err = new Error("خطا در آماده‌سازی اطلاعات");
      err.isUserError = true;
      throw err;
    }
    const formData = new FormData();
    formData.append("encrypted_data", encoded);
    const response = await guardedFetch("API/getCoworkerSessions.php", {
      method: "POST",
      body: formData,
    });
    const payload = await response.json();
    if (!response.ok || payload.success !== true) {
      const code = (payload?.error || "").toString();
      let message;
      if (code === "not_found" || code === "phone_mismatch") {
        message = "نام کاربری یا رمز عبور صحیح نیست.";
      } else {
        message =
          payload?.message ||
          payload?.error ||
          "امکان ورود عوامل اجرائی در حال حاضر وجود ندارد.";
      }
      const error = new Error(message);
      error.isUserError = true;
      throw error;
    }
    return payload;
  }

  function persistCoworkerSession(credentials) {
    try {
      const snapshot = {
        national_id: credentials.national_id,
        phone: credentials.phone,
        stored_at: Date.now(),
      };
      localStorage.setItem(COWORKER_SESSION_KEY, JSON.stringify(snapshot));
    } catch (error) {
      console.warn("Failed to persist coworker session", error);
    }
  }

  function clearCoworkerSessionStorage() {
    try {
      localStorage.removeItem(COWORKER_SESSION_KEY);
    } catch (error) {
      console.warn("Failed to clear coworker session", error);
    }
  }

  async function autoLoginCoworkerFromStorage() {
    if (!coworkerTypeRadio) {
      showLogin();
      return;
    }
    let stored = null;
    try {
      stored = JSON.parse(localStorage.getItem(COWORKER_SESSION_KEY) || "null");
    } catch (error) {
      console.warn("Failed to parse coworker session", error);
      clearCoworkerSessionStorage();
    }
    if (!stored?.national_id || !stored?.phone) {
      showLogin();
      return;
    }

    if (coworkerTypeRadio && !coworkerTypeRadio.checked) {
      coworkerTypeRadio.checked = true;
      handleUserTypeChange();
    }

    stopAutoRefresh();
    toggleLoading(true);
    clearResults();
    try {
      const payload = await fetchCoworkerSessionsPayload(
        stored.national_id,
        stored.phone
      );
      renderCoworkerSessions(payload, {
        nationalId: stored.national_id,
        phone: stored.phone,
      });
    } catch (error) {
      console.warn("Coworker auto-login failed", error);
      clearCoworkerSessionStorage();
      if (!error?.isLicenseError) {
        showLogin();
      }
    } finally {
      toggleLoading(false);
    }
  }

  function updateCountdowns(payload = lastPayload) {
    if (!Array.isArray(payload) || payload.length === 0) return false;
    let needsReorder = false;
    const now = Date.now();
    payload.forEach((exam, idx) => {
      const card = examCards?.querySelector(
        `.exam-card[data-exam-origin='${idx}']`
      );
      if (!card) return;
      const status = card.getAttribute("data-exam-status");
      const target = createExamDateTime(exam.exam_date, exam.exam_time);
      if (!target || target.getTime() <= now) {
        if (status === "upcoming") {
          needsReorder = true;
        }
        const countdownExisting = card.querySelector(".exam-countdown");
        if (countdownExisting) countdownExisting.remove();
        return;
      }

      const text = getCountdownText(exam.exam_date, exam.exam_time);
      let countdown = card.querySelector(".exam-countdown");
      if (!text) {
        if (countdown) countdown.remove();
        return;
      }
      if (countdown) {
        countdown.textContent = text;
        return;
      }
      const meta = card.querySelector(".exam-meta");
      countdown = document.createElement("div");
      countdown.className = "exam-countdown";
      countdown.textContent = text;
      if (meta && meta.parentNode) {
        meta.parentNode.insertBefore(countdown, meta.nextSibling);
      } else {
        card.appendChild(countdown);
      }
    });
    return needsReorder;
  }

  function showAlert(icon, title, text) {
    Swal.fire({
      icon,
      title,
      text,
      confirmButtonText: "باشه",
      buttonsStyling: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary btn-lg px-4",
      },
    });
  }

  function toEnglishDigits(value) {
    if (!value) return "";
    const persianMap = {
      "۰": "0",
      "۱": "1",
      "۲": "2",
      "۳": "3",
      "۴": "4",
      "۵": "5",
      "۶": "6",
      "۷": "7",
      "۸": "8",
      "۹": "9",
    };
    const arabicMap = {
      "٠": "0",
      "١": "1",
      "٢": "2",
      "٣": "3",
      "٤": "4",
      "٥": "5",
      "٦": "6",
      "٧": "7",
      "٨": "8",
      "٩": "9",
    };
    return value
      .split("")
      .map((ch) => persianMap[ch] ?? arabicMap[ch] ?? ch)
      .join("");
  }

  function toPersianDigits(value) {
    if (value === null || value === undefined) return "";
    const digits = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
    return value.toString().replace(/\d/g, (d) => digits[Number(d)]);
  }

  function getCountdownText(examDate, examTime) {
    const target = createExamDateTime(examDate, examTime);
    if (!target) return "";
    const diff = target.getTime() - Date.now();
    if (diff <= 0) return "";

    const totalMinutes = Math.floor(diff / 60000);
    const days = Math.floor(totalMinutes / (60 * 24));
    let remainingMinutes = totalMinutes - days * 60 * 24;
    const hours = Math.floor(remainingMinutes / 60);
    remainingMinutes -= hours * 60;
    const minutes = remainingMinutes;

    const parts = [];
    if (days > 0) parts.push(`${toPersianDigits(days)} روز`);
    if (hours > 0) parts.push(`${toPersianDigits(hours)} ساعت`);
    if (minutes > 0 && days < 3)
      parts.push(`${toPersianDigits(minutes)} دقیقه`);
    if (!parts.length) parts.push("کمتر از یک دقیقه");

    return `${parts.join(" و ")} مانده تا زمان آزمون`;
  }

  function createExamDateTime(examDateStr, examTimeStr) {
    if (!examDateStr) return null;
    const normalizedDate = toEnglishDigits(String(examDateStr).trim()).replace(
      /-/g,
      "/"
    );
    const segments = normalizedDate
      .split("/")
      .map((part) => parseInt(part, 10));
    if (segments.length !== 3 || segments.some(Number.isNaN)) return null;
    let [year, month, day] = segments;

    if (year < 1700) {
      const gregorian = jalaliToGregorian(year, month, day);
      if (!gregorian) return null;
      [year, month, day] = gregorian;
    }

    const timeString = examTimeStr
      ? toEnglishDigits(String(examTimeStr).trim())
      : "00:00";
    const timeParts = timeString.split(":").map((part) => parseInt(part, 10));
    const hour = timeParts[0] ?? 0;
    const minute = timeParts[1] ?? 0;
    if (Number.isNaN(hour) || Number.isNaN(minute)) return null;

    return new Date(year, month - 1, day, hour, minute, 0);
  }

  function jalaliToGregorian(jy, jm, jd) {
    jy = parseInt(jy, 10);
    jm = parseInt(jm, 10);
    jd = parseInt(jd, 10);
    if ([jy, jm, jd].some(Number.isNaN)) return null;

    jy += 1595;
    let days =
      -355668 +
      365 * jy +
      Math.floor(jy / 33) * 8 +
      Math.floor(((jy % 33) + 3) / 4) +
      jd +
      (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);

    let gy = 400 * Math.floor(days / 146097);
    days %= 146097;

    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }

    gy += 4 * Math.floor(days / 1461);
    days %= 1461;

    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }

    const gd = days + 1;
    const monthDays = [
      0,
      31,
      (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28,
      31,
      30,
      31,
      30,
      31,
      31,
      30,
      31,
      30,
      31,
    ];

    let gm = 1;
    let remaining = gd;
    while (gm <= 12 && remaining > monthDays[gm]) {
      remaining -= monthDays[gm];
      gm += 1;
    }

    return [gy, gm, remaining];
  }

  function escapeHtml(value) {
    if (!value) return "";
    return value
      .toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }
});
