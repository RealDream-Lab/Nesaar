function isDesktopDevice() {
  const isTouch = "ontouchstart" in window || navigator.maxTouchPoints > 1;
  const width = window.innerWidth || document.documentElement.clientWidth;
  const userAgent = navigator.userAgent.toLowerCase();
  const isMobileUA =
    /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/.test(
      userAgent
    );
  return !isTouch && width > 900 && !isMobileUA;
}

function escapeHtml(value) {
  if (value === null || value === undefined) {
    return "";
  }
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

// Configure SweetAlert2 globally to prevent layout shifts
// Note: scrollbarPadding and heightAuto are NOT set in mixin because they're incompatible with toasts
// Instead, they should be set individually on non-toast modals if needed
try {
  if (typeof Swal !== "undefined") {
    // Keep original Swal reference without problematic global defaults
    window.Swal = Swal;
  }
} catch (e) {}

// Ensure simple informational Swal modals are shown as toasts (5s) to avoid blocking clicks
(function wrapSwalInfoToastsDashboard() {
  try {
    function patch() {
      try {
        if (
          typeof Swal === "undefined" ||
          Swal._ns_info_toast_patched_dashboard
        )
          return;

        const _orig = Swal.fire.bind(Swal);
        Swal.fire = function (opts) {
          try {
            if (typeof opts === "object" && opts !== null) {
              // Check if it's a toast
              const isToast = opts.toast === true;

              const isSimpleInfo =
                (opts.icon === "info" || opts.icon === "success") &&
                !opts.input &&
                !opts.html &&
                !opts.showCancelButton;
              if (isSimpleInfo && !isToast) {
                const toastOpts = Object.assign({}, opts, {
                  toast: true,
                  position: opts.position || "top-end",
                  timer: typeof opts.timer === "number" ? opts.timer : 3000,
                  showConfirmButton: false,
                });
                // Remove incompatible toast parameters
                delete toastOpts.scrollbarPadding;
                delete toastOpts.heightAuto;
                delete toastOpts.allowOutsideClick;
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
                return _orig(toastOpts);
              }

              // For non-toast modals, add heightAuto: false to prevent scroll jumping
              if (!isToast && opts.heightAuto === undefined) {
                opts = Object.assign({}, opts, { heightAuto: false });
              }

              // For toasts, remove heightAuto if present
              if (isToast && opts.heightAuto !== undefined) {
                opts = Object.assign({}, opts);
                delete opts.heightAuto;
              }
            }
          } catch (e) {}
          return _orig.apply(Swal, arguments);
        };
        Swal._ns_info_toast_patched_dashboard = true;
      } catch (e) {}
    }
    if (document.readyState === "loading")
      document.addEventListener("DOMContentLoaded", patch);
    else patch();
  } catch (e) {}
})();

async function copyToClipboard(text) {
  if (typeof text !== "string" || !text) {
    return false;
  }

  try {
    if (
      navigator.clipboard &&
      typeof navigator.clipboard.writeText === "function"
    ) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (err) {
    console.warn("Clipboard API copy failed", err);
  }

  try {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "readonly");
    textarea.style.position = "fixed";
    textarea.style.top = "-200px";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    const success = document.execCommand("copy");
    document.body.removeChild(textarea);
    return success;
  } catch (fallbackError) {
    console.warn("Fallback clipboard copy failed", fallbackError);
    return false;
  }
}

const DASHBOARD_CONTEXT =
  typeof window !== "undefined" && window.DASHBOARD_CONTEXT
    ? window.DASHBOARD_CONTEXT
    : {};
const DASHBOARD_ROLE =
  typeof DASHBOARD_CONTEXT.role === "string"
    ? DASHBOARD_CONTEXT.role.toLowerCase()
    : "admin";
const isRecipientView = DASHBOARD_ROLE === "recipient";
const SESSION_ENDPOINT = isRecipientView
  ? "../API/recipientSession.php"
  : "../API/adminSession.php";
const LOGOUT_ENDPOINT = isRecipientView
  ? "../API/recipientLogout.php"
  : "../API/adminLogout.php";
const DEFAULT_DASHBOARD_NAME = isRecipientView
  ? "کاربر گزارش‌گیری"
  : "مدیر سیستم";

try {
  window.addEventListener(
    "afterprint",
    () => {
      try {
        closeSwalLoadingHard();
      } catch (e) {}
      try {
        reopenEssentialsMenuIfRequested();
      } catch (e) {}
    },
    false
  );
} catch (e) {}

function closeSwalLoadingHard() {
  try {
    if (window.Swal && typeof Swal.close === "function") {
      try {
        Swal.close();
      } catch (e) {}
    }
  } catch (e) {}
  try {
    const nodes = document.querySelectorAll(
      ".swal2-container, .swal2-popup, .swal2-backdrop-show, .swal2-loading, .swal2-actions.swal2-loading"
    );
    nodes.forEach((el) => {
      try {
        const isLoading =
          el.getAttribute && el.getAttribute("data-loading") === "true";
        if (isLoading || el.classList.contains("swal2-loading")) {
          el.remove();
        }
      } catch (e) {}
    });
  } catch (e) {}
}

function safePrintIframe(iframe, cw) {
  try {
    const idoc = iframe.contentDocument || iframe.contentWindow.document;
    (async () => {
      try {
        await waitForVazirFonts(idoc);
      } catch (e) {
        /* ignore */
      }
      try {
        cw.focus();
        cw.print();
      } catch (err) {
        console.error("Print error:", err);
      }
    })();
  } catch (e) {
    try {
      cw.focus();
      cw.print();
    } catch (err) {
      console.error("Print error:", err);
    }
  }
}

function safePrintWindow_OLD(win) {
  try {
    const idoc = win && win.document ? win.document : null;
    (async () => {
      try {
        await waitForVazirFonts(idoc);
      } catch (e) {
        /* ignore */
      }
      try {
        win.focus();
        win.print();
      } catch (err) {
        console.error("Print error (window):", err);
      }
    })();
  } catch (e) {
    try {
      win.focus();
      win.print();
    } catch (err) {
      console.error("Print error (window):", err);
    }
  }
}

function showReportModal(url, title) {
  const rptDownload =
    window.appConfig && window.appConfig.rptDownload === "YES";

  // Helper function to create spinner HTML with counter - counter is OUTSIDE spinning element
  const createSpinnerHtml = () => `
    <div class="swal2-spinner-wrapper">
      <div class="swal2-loading-spinner"></div>
      <span id="loadingCounter">000</span>
    </div>
    <p style="margin-top: 15px;">لطفاً صبر کنید</p>`;

  // Helper function to start counter
  const startCounter = () => {
    let seconds = 0;
    return setInterval(() => {
      seconds++;
      const counterEl = document.getElementById("loadingCounter");
      if (counterEl) {
        counterEl.textContent = String(seconds).padStart(3, "0");
      }
    }, 1000);
  };

  if (rptDownload) {
    // Show loading for download mode
    let counterInterval;
    Swal.fire({
      title: "در حال آماده‌سازی گزارش...",
      html: createSpinnerHtml(),
      allowOutsideClick: false,
      showConfirmButton: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
      },
      didOpen: () => {
        counterInterval = startCounter();
        // Open download after a brief delay
        setTimeout(() => {
          clearInterval(counterInterval);
          window.open(url, "_blank");
          Swal.close();
          reopenEssentialsMenuIfRequested();
        }, 500);
      },
      willClose: () => {
        if (counterInterval) clearInterval(counterInterval);
      },
    });
  } else {
    // Show loading spinner first, then load iframe
    let counterInterval;
    Swal.fire({
      title: "در حال آماده‌سازی گزارش...",
      html: createSpinnerHtml(),
      allowOutsideClick: false,
      showConfirmButton: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
      },
      didOpen: () => {
        counterInterval = startCounter();
        // Create iframe and wait for it to load
        const iframe = document.createElement("iframe");
        iframe.src = url;
        iframe.style.cssText =
          "width:100%; height:85vh; border:none; display:none;";
        iframe.onload = () => {
          clearInterval(counterInterval);
          // Show the actual report modal
          Swal.fire({
            title: title || "پیش‌نمایش گزارش",
            html: `<iframe src="${url}" style="width:100%; height:85vh; border:none;"></iframe>`,
            width: "95%",
            padding: "0",
            showCloseButton: true,
            showConfirmButton: false,
            customClass: {
              popup: "swal2-rtl swal2-glass",
            },
            didClose: () => {
              reopenEssentialsMenuIfRequested();
            },
          });
        };
        iframe.onerror = () => {
          clearInterval(counterInterval);
          Swal.fire({
            icon: "error",
            title: "خطا",
            text: "خطا در بارگذاری گزارش",
            customClass: { popup: "swal2-rtl" },
          });
        };
        document.body.appendChild(iframe);
        // Cleanup hidden iframe after use
        setTimeout(() => {
          if (iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
          }
        }, 60000);
      },
      willClose: () => {
        if (counterInterval) clearInterval(counterInterval);
      },
    });
  }
}

function reopenEssentialsMenuIfRequested() {
  try {
    if (window._reopenEssentialsMenu) {
      window._reopenEssentialsMenu = false;
      setTimeout(() => {
        try {
          examEssentialsHandler();
        } catch (e) {
          console.error("Reopen menu failed:", e);
        }
      }, 200);
    }
  } catch (e) {}
}

let cachedVazirFontMeta = null;
function getVazirFontMeta() {
  if (cachedVazirFontMeta) return cachedVazirFontMeta;
  let href = "";
  try {
    const existing = document.querySelector('link[href*="vazir.css"]');
    if (existing && existing.href) {
      href = existing.href;
    }
  } catch (e) {
    /* ignore */
  }
  if (!href) {
    const origin =
      window.location && window.location.origin ? window.location.origin : "";
    href = `${origin}/assets/fonts/vazir/vazir.css`;
  }
  const base = href.replace(/\/vazir\.css(\?.*)?$/, "");
  const preloadFiles = [
    "Vazir-Regular-FD.woff2",
    "Vazir-Medium-FD.woff2",
    "Vazir-Bold-FD.woff2",
  ];
  const preloadTags = preloadFiles
    .map(
      (file) =>
        `<link rel="preload" href="${base}/Farsi-Digits/${file}" as="font" type="font/woff2" crossorigin="anonymous">`
    )
    .join("");
  cachedVazirFontMeta = {
    href,
    base,
    headMarkup: `${preloadTags}<link rel="stylesheet" href="${href}">`,
  };
  return cachedVazirFontMeta;
}

function preloadVazirFonts() {
  try {
    if (!document.fonts || typeof document.fonts.load !== "function") return;
    [
      'normal 400 16px "Vazir"',
      'normal 500 16px "Vazir"',
      'normal 700 16px "Vazir"',
    ].forEach((desc) => {
      try {
        document.fonts.load(desc);
      } catch (e) {
        /* ignore */
      }
    });
  } catch (e) {
    /* ignore */
  }
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForVazirFonts(doc) {
  try {
    if (!doc || !doc.fonts || typeof doc.fonts.load !== "function") {
      await delay(350);
      return;
    }
    const descriptors = [
      'normal 400 16px "Vazir"',
      'normal 500 16px "Vazir"',
      'normal 700 16px "Vazir"',
    ];
    const tasks = [];
    descriptors.forEach((desc) => {
      try {
        tasks.push(doc.fonts.load(desc));
      } catch (e) {
        /* ignore */
      }
    });
    if (doc.fonts.ready) {
      tasks.push(doc.fonts.ready);
    }
    if (!tasks.length) {
      await delay(350);
      return;
    }
    await Promise.race([Promise.all(tasks), delay(4000)]);
  } catch (e) {
    await delay(250);
  }
}

try {
  preloadVazirFonts();
} catch (e) {
  /* ignore */
}

async function printSessionReport() {
  try {
    let context =
      window._overrideExamContext && window._overrideExamContext.active
        ? window._overrideExamContext
        : window._lastExamContext;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (!examDate || !examTime) {
      const nextExamDateTimeText =
        document.getElementById("nextExamDateTime")?.textContent || "";
      if (
        !nextExamDateTimeText ||
        nextExamDateTimeText === "بارگذاری..." ||
        nextExamDateTimeText === "آزمونی یافت نشد"
      ) {
        return Swal.fire({
          icon: "info",
          title: "اطلاعات",
          text: "آزمون بعدی یافت نشد",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
      const parts = nextExamDateTimeText.split("|").map((s) => s.trim());
      if (parts.length !== 2) {
        return Swal.fire({
          icon: "error",
          title: "خطا",
          text: "فرمت تاریخ و ساعت نامعتبر است",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
      examTime = toEnglishDigits(parts[0]);
      examDate = toEnglishDigits(parts[1]).replace(/-/g, "/");
      setLastExamContext(examDate, examTime);
    } else {
      examTime = toEnglishDigits(examTime);
      examDate = toEnglishDigits(examDate).replace(/-/g, "/");
      setLastExamContext(examDate, examTime);
    }
    const url = `../API/generatePDF.php?report_type=session&exam_date=${encodeURIComponent(
      examDate
    )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
    showReportModal(url, "صورتجلسه آزمون");
  } catch (e) {
    console.error(e);
  }
}

function toPersianDigits(num) {
  return String(num).replace(/[0-9]/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]);
}

function toEnglishDigits(value) {
  const persian = "۰۱۲۳۴۵۶۷۸۹";
  const arabic = "٠١٢٣٤٥٦٧٨٩";
  return String(value).replace(/[۰-۹٠-٩]/g, (d) => {
    let idx = persian.indexOf(d);
    if (idx >= 0) return String(idx);
    idx = arabic.indexOf(d);
    return idx >= 0 ? String(idx) : d;
  });
}

function setLastExamContext(examDate, examTime) {
  if (!examDate || !examTime) return;
  const normalizedDate = toEnglishDigits(String(examDate))
    .replace(/-/g, "/")
    .trim();
  const normalizedTime = toEnglishDigits(String(examTime)).trim();
  window._lastExamContext = {
    exam_date: normalizedDate,
    exam_time: normalizedTime,
  };
}

// Return Bootstrap badge class for exam type labels across the dashboard
function getExamBadgeClass(type) {
  if (!type) return "bg-secondary";
  const t = String(type).trim();
  if (t === "الکترونیکی") return "bg-warning";
  if (t === "کتبی") return "bg-dark";
  return "bg-info";
}

document.addEventListener("DOMContentLoaded", function () {
  if (!isDesktopDevice()) {
    let countdownInterval;
    Swal.fire({
      icon: "warning",
      title: "دسترسی فقط از دسکتاپ",
      html:
        '<div style="text-align:justify;line-height:2">برای استفاده کامل از امکانات داشبورد مدیریتی نسار، لطفاً از کامپیوتر یا لپ‌تاپ استفاده کنید.<br>نمایش و مدیریت دقیق اطلاعات فقط در نسخه دسکتاپ پشتیبانی می‌شود.</div>' +
        '<div class="swal2-countdown" style="margin-top:1.2em;text-align:center;font-size:1.2em;font-weight:bold;"><span class="swal2-countdown-value">' +
        toPersianDigits(15) +
        "</span></div>",
      timer: 15000,
      timerProgressBar: true,
      showConfirmButton: false,
      allowOutsideClick: true,
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
  }
});

function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") : null;
}

function showLicenseForbidden(message) {
  Swal.fire({
    icon: "error",
    title: "خطای لایسنس",
    html: `<div style="text-align:right;line-height:1.9">${message}</div>`,
    confirmButtonText: "باشه",
    allowOutsideClick: false,
    customClass: {
      popup: "swal2-rtl swal2-glass",
      confirmButton: "btn btn-primary",
    },
  });
}

async function handleLicenseGuardResponse(response) {
  if (response.status !== 403) return;
  let message = "دسترسی به داشبورد به علت مشکل لایسنس امکان‌پذیر نیست.";
  try {
    const payload = await response.clone().json();
    if (payload && payload.message) {
      message = payload.message;
    }
  } catch (error) {
    // Ignore JSON parsing errors and use fallback text
  }
  showLicenseForbidden(message);
  const err = new Error("license_forbidden");
  err.isLicenseError = true;
  throw err;
}

async function guardedFetch(resource, options = {}) {
  const opts = { ...options };
  const method = (opts.method || "GET").toUpperCase();
  if (["POST", "PUT", "DELETE", "PATCH"].includes(method)) {
    const csrfToken = getCsrfToken();
    if (csrfToken) {
      if (opts.headers instanceof Headers) {
        opts.headers.set("X-CSRF-Token", csrfToken);
      } else {
        opts.headers = { ...(opts.headers || {}), "X-CSRF-Token": csrfToken };
      }
    }
  }

  const response = await fetch(resource, opts);
  await handleLicenseGuardResponse(response);
  return response;
}

async function fetchSmsCreditValue() {
  try {
    const response = await guardedFetch("../API/getSmsCredit.php", {
      cache: "no-store",
    });
    if (!response.ok) {
      return null;
    }
    const payload = await response.json();
    if (payload && payload.success) {
      if (payload.credit !== undefined && payload.credit !== null) {
        return payload.credit;
      }
      if (payload.raw && payload.raw.Data !== undefined) {
        return payload.raw.Data;
      }
    }
  } catch (err) {
    console.warn("fetchSmsCreditValue failed", err);
  }
  return null;
}

function formatSmsCreditDisplay(value) {
  if (value === null || value === undefined || value === "") {
    return "نامشخص";
  }
  const num = Number(value);
  if (Number.isFinite(num)) {
    return toPersianDigits(num.toLocaleString("en-US"));
  }
  return toPersianDigits(String(value));
}

async function checkAuth() {
  try {
    const response = await guardedFetch(SESSION_ENDPOINT, {
      cache: "no-store",
    });
    if (!response.ok) {
      throw new Error("unauthorized");
    }
    const session = await response.json();
    const username =
      session.displayName || session.username || DEFAULT_DASHBOARD_NAME;
    const usernameTarget = document.getElementById("adminUsername");
    if (usernameTarget) {
      usernameTarget.textContent = username;
    }
    return true;
  } catch (error) {
    window.location.href = "../";
    return false;
  }
}

// Logout
document.getElementById("logoutBtn").addEventListener("click", async () => {
  const result = await Swal.fire({
    title: "تأیید خروج",
    text: "آیا مطمئن هستید که می‌خواهید از داشبورد خارج شوید؟",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "بله",
    cancelButtonText: "لغو",
    reverseButtons: true,
    customClass: {
      popup: "swal2-rtl swal2-glass",
      confirmButton: "btn btn-danger",
      cancelButton: "btn btn-cancel",
    },
  });

  if (result.isConfirmed) {
    try {
      await guardedFetch(LOGOUT_ENDPOINT, { method: "POST" });
    } catch (logoutError) {
      console.warn("Admin logout request failed:", logoutError);
    }
    window.location.href = "../";
  }
});

// Edit Roles (placeholder) — opens a modal to manage roles (implementation can be added later)
// Edit Roles — edit four config names: AdminNickName, BossNickName, HeadOfEDU, Chairman
// Flow: 1) confirm warning -> 2) show turquoise-styled form prefilled from getConfig.php -> 3) on Save, show confirmation -> 4) POST to API/saveConfig.php
try {
  const editBtn = document.getElementById("editRolesBtn");
  if (editBtn) {
    editBtn.addEventListener("click", async () => {
      // Load current config
      let cfg = {};
      try {
        const cfgResp = await guardedFetch("../API/getConfig.php", {
          cache: "no-store",
        });
        if (cfgResp && cfgResp.ok) cfg = await cfgResp.json();
      } catch (e) {
        console.warn("Could not load config for edit roles modal", e);
      }

      const adminVal = cfg.AdminNickName || "";
      const bossVal = cfg.BossNickName || "";
      const headVal = cfg.HeadOfEDU || "";
      const chairVal = cfg.Chairman || "";
      const groupByCourseChecked =
        String(cfg.GroupByCourse || "").toUpperCase() === "YES";
      const paperSavingChecked =
        String(cfg.PaperSaving || "").toUpperCase() === "YES";
      const sendSmsChecked = String(cfg.SendSMS || "").toUpperCase() === "YES";
      const rptDownloadVal = String(cfg.rptDownload || "").toUpperCase();
      const wavesAnimationChecked =
        String(cfg.WavesAnimation || "YES").toUpperCase() !== "NO";
      const reproductionReportModeVal = String(
        cfg.ReproductionReportMode || "course"
      ).toLowerCase();

      let smsCreditValue = null;
      try {
        smsCreditValue = await fetchSmsCreditValue();
      } catch (e) {
        /* already logged */
      }
      const smsCreditDisplay = formatSmsCreditDisplay(smsCreditValue);
      const smsCreditParenthetical =
        smsCreditDisplay === "نامشخص"
          ? "اعتبار: نامشخص"
          : `اعتبار: ${smsCreditDisplay} پیامک`;

      // Form HTML: two-column rows for text inputs, toggles under a divider
      const sharedInputStyle =
        "margin-bottom:6px;border:1px solid rgba(255,255,255,0.12);background:transparent;color:inherit;box-shadow:none;";
      const fieldWrapperStyle =
        "flex:1;min-width:220px;display:flex;flex-direction:column;gap:4px;";
      const formHtml = `
                <div style="text-align: right; direction: rtl;">
                    <div style="padding:8px; border-radius:6px;">
                        <div style="margin-bottom:10px; font-weight:700; color:inherit;">ویرایش نقش‌ها و نام‌های امضا‌کننده</div>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <div style="${fieldWrapperStyle}">
                                <label style="font-size:0.92rem;color:inherit;">نام نمایشی کاربر (نمایش در هدر)</label>
                                <input id="er_admin" class="swal2-input" placeholder="نام نمایشی کاربر" style="${sharedInputStyle}" value="${escapeHtml(
        adminVal
      )}">
                            </div>
                            <div style="${fieldWrapperStyle}">
                                <label style="font-size:0.92rem;color:inherit;">نام و نام خانوادگی رئیس مرکز</label>
                                <input id="er_boss" class="swal2-input" placeholder="رئیس مرکز" style="${sharedInputStyle}" value="${escapeHtml(
        bossVal
      )}">
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
                            <div style="${fieldWrapperStyle}">
                                <label style="font-size:0.92rem;color:inherit;">نام و نام خانوادگی رئیس اداره آموزش</label>
                                <input id="er_head" class="swal2-input" placeholder="رئیس اداره آموزش" style="${sharedInputStyle}" value="${escapeHtml(
        headVal
      )}">
                            </div>
                            <div style="${fieldWrapperStyle}">
                                <label style="font-size:0.92rem;color:inherit;">نام و نام خانوادگی مسئول جلسه</label>
                                <input id="er_chair" class="swal2-input" placeholder="مسئول جلسه" style="${sharedInputStyle}" value="${escapeHtml(
        chairVal
      )}">
                            </div>
                        </div>
                        <hr style="border:0;border-top:1px solid rgba(255,255,255,0.15);margin:14px 0;">
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input id="er_groupByCourse" type="checkbox" ${
                                  groupByCourseChecked ? "checked" : ""
                                } style="width:1.15rem;height:1.15rem;">
                                <label for="er_groupByCourse" style="margin:0;cursor:pointer;">مرتب‌سازی صندلی‌ها براساس درس</label>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input id="er_paperSaving" type="checkbox" ${
                                  paperSavingChecked ? "checked" : ""
                                } style="width:1.15rem;height:1.15rem;">
                                <label for="er_paperSaving" style="margin:0;cursor:pointer;">صرفه‌جویی در مصرف کاغذ</label>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input id="er_sendSms" type="checkbox" ${
                                  sendSmsChecked ? "checked" : ""
                                } style="width:1.15rem;height:1.15rem;">
                                <label for="er_sendSms" style="margin:0;cursor:pointer;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                    <span>فعال‌سازی ارسال پیامک برای عوامل اجرائی</span>
                                    <span style="font-size:0.85rem;color:#ffffff;">(${smsCreditParenthetical})</span>
                                </label>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
                                <input id="er_wavesAnimation" type="checkbox" ${
                                  wavesAnimationChecked ? "checked" : ""
                                } style="width:1.15rem;height:1.15rem;">
                                <label for="er_wavesAnimation" style="margin:0;cursor:pointer;">نمایش انیمیشن پس‌زمینه</label>
                            </div>
                            <div style="display:flex;align-items:center;gap:15px;margin-top:8px;">
                                <span style="font-size:0.92rem;color:inherit;">نحوه دریافت گزارشات:</span>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <input type="radio" id="er_rptView" name="er_rptDownload" value="NO" ${
                                      rptDownloadVal !== "YES" ? "checked" : ""
                                    } style="cursor:pointer;">
                                    <label for="er_rptView" style="margin:0;cursor:pointer;font-size:0.9rem;">مشاهده</label>
                                </div>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <input type="radio" id="er_rptDownload" name="er_rptDownload" value="YES" ${
                                      rptDownloadVal === "YES" ? "checked" : ""
                                    } style="cursor:pointer;">
                                    <label for="er_rptDownload" style="margin:0;cursor:pointer;font-size:0.9rem;">دانلود</label>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:15px;margin-top:8px;">
                                <span style="font-size:0.92rem;color:inherit;">نحوه چاپ گزارش ملزومات اتاق تکثیر:</span>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <input type="radio" id="er_reprCourse" name="er_reproductionMode" value="course" ${
                                      reproductionReportModeVal !== "location"
                                        ? "checked"
                                        : ""
                                    } style="cursor:pointer;">
                                    <label for="er_reprCourse" style="margin:0;cursor:pointer;font-size:0.9rem;">بر اساس درس</label>
                                </div>
                                <div style="display:flex;align-items:center;gap:5px;">
                                    <input type="radio" id="er_reprLocation" name="er_reproductionMode" value="location" ${
                                      reproductionReportModeVal === "location"
                                        ? "checked"
                                        : ""
                                    } style="cursor:pointer;">
                                    <label for="er_reprLocation" style="margin:0;cursor:pointer;font-size:0.9rem;">بر اساس مکان</label>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>`;

      const modalResult = await Swal.fire({
        title: "ویرایش نقش‌ها و تنظیمات",
        html: formHtml,
        width: 720,
        showCancelButton: true,
        confirmButtonText: "ذخیره",
        cancelButtonText: "انصراف",
        focusConfirm: false,
        // Ensure buttons match the modal's styling
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
          cancelButton: "btn btn-cancel",
        },
        preConfirm: () => {
          const admin = document.getElementById("er_admin")?.value || "";
          const boss = document.getElementById("er_boss")?.value || "";
          const head = document.getElementById("er_head")?.value || "";
          const chair = document.getElementById("er_chair")?.value || "";
          const groupByCourse = document.getElementById("er_groupByCourse")
            ?.checked
            ? "YES"
            : "NO";
          const paperSaving = document.getElementById("er_paperSaving")?.checked
            ? "YES"
            : "NO";
          const sendSms = document.getElementById("er_sendSms")?.checked
            ? "YES"
            : "NO";
          const rptDownload =
            document.querySelector('input[name="er_rptDownload"]:checked')
              ?.value || "NO";
          const wavesAnimation = document.getElementById("er_wavesAnimation")
            ?.checked
            ? "YES"
            : "NO";
          const reproductionMode =
            document.querySelector('input[name="er_reproductionMode"]:checked')
              ?.value || "course";
          // return values to then handle save confirmation
          return {
            AdminNickName: admin.trim(),
            BossNickName: boss.trim(),
            HeadOfEDU: head.trim(),
            Chairman: chair.trim(),
            GroupByCourse: groupByCourse,
            PaperSaving: paperSaving,
            SendSMS: sendSms,
            rptDownload: rptDownload,
            WavesAnimation: wavesAnimation,
            ReproductionReportMode: reproductionMode,
          };
        },
      });

      if (!modalResult.isConfirmed) return;

      const values = modalResult.value || {};

      // Second confirmation before saving
      const second = await Swal.fire({
        title: "تأیید نهایی",
        html: '<div style="text-align:justify;line-height:1.8">ذخیره تغییرات باعث به‌روز‌رسانی اطلاعات صورتجلسه‌ها و تنظیمات خواهد شد. آیا مطمئن به ذخیره هستید؟</div>',
        icon: "question",
        showCancelButton: true,
        confirmButtonText: "بله، ذخیره کن",
        cancelButtonText: "بازگشت",
        reverseButtons: true,
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
          cancelButton: "btn btn-cancel",
        },
      });
      if (!second.isConfirmed) {
        // Re-open the edit roles modal with the same values
        editBtn.click();
        return;
      }

      // Perform save
      try {
        const saveResp = await guardedFetch("../API/saveConfig.php", {
          method: "POST",
          headers: { "Content-Type": "application/json; charset=utf-8" },
          body: JSON.stringify(values),
        });
        const saveJson = await saveResp.json();
        if (saveJson && saveJson.success) {
          // Update displayed admin name if changed
          if (values.AdminNickName) {
            try {
              const usernameEl = document.getElementById("adminUsername");
              if (usernameEl) {
                usernameEl.textContent = values.AdminNickName;
              }
            } catch (e) {}
          }
          // Update global config for immediate effect
          window.appConfig = { ...(window.appConfig || {}), ...values };
          // Apply waves animation setting immediately
          if (values.WavesAnimation === "NO") {
            document.body.classList.add("no-waves-animation");
          } else {
            document.body.classList.remove("no-waves-animation");
          }
          await Swal.fire({
            icon: "success",
            title: "ذخیره شد",
            text: "اطلاعات با موفقیت ذخیره شد",
            confirmButtonText: "باشه",
            customClass: {
              popup: "swal2-rtl swal2-glass",
              confirmButton: "btn btn-primary",
            },
          });
        } else {
          throw new Error(
            saveJson && saveJson.error ? saveJson.error : "خطا در ذخیره تنظیمات"
          );
        }
      } catch (err) {
        console.error("Save config failed:", err);
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: err && err.message ? err.message : "خطا در ذخیره تنظیمات",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
    });

    const smsSettingsBtn = document.getElementById("smsSettingsBtn");
    if (smsSettingsBtn) {
      smsSettingsBtn.addEventListener("click", (ev) => {
        ev.preventDefault();
        editBtn.click();
      });
    }
  }
} catch (e) {
  console.warn("Edit roles handler attach failed", e);
}

// Recipient Access Button Handler
if (!isRecipientView) {
  try {
    const recipientBtn = document.getElementById("recipientAccessBtn");
    if (recipientBtn) {
      recipientBtn.addEventListener("click", async () => {
        try {
          Swal.fire({
            title: "در حال آماده‌سازی...",
            html: "لطفاً صبر کنید",
            showConfirmButton: false,
            allowOutsideClick: false,
            customClass: { popup: "swal2-rtl swal2-glass" },
            didOpen: () => {
              Swal.showLoading();
            },
          });

          const response = await guardedFetch(
            "../API/getRecipientCredentials.php",
            {
              cache: "no-store",
            }
          );
          const data = await response.json();

          if (!response.ok || data.success !== true) {
            throw new Error(
              data && data.error
                ? data.error
                : "امکان دریافت رمز کاربر Recipient وجود ندارد"
            );
          }

          Swal.close();

          const username = data.username || "Recipient";
          const password = data.password || "";
          if (!password) {
            throw new Error("رمز عبور معتبر از سمت سرور دریافت نشد");
          }

          const escapeAttr = (value) =>
            String(value ?? "").replace(
              /["'&<>]/g,
              (ch) =>
                ({
                  '"': "&quot;",
                  "'": "&#39;",
                  "&": "&amp;",
                  "<": "&lt;",
                  ">": "&gt;",
                }[ch] || ch)
            );

          const modalHtml = `
          <div class="recipient-credential-card">
            <div class="recipient-credential-heading">
              <img src="/assets/app/recipient.png" alt="Recipient">
              <div>
                <p>هشدار ! این اطلاعات را فقط در اختیار افراد مجاز قرار دهید.</p>
              </div>
            </div>
            <div class="recipient-credential-field">
              <div class="recipient-credential-pair">
                <div class="recipient-credential-row">
                  <span class="recipient-row-label">نام کاربری</span>
                  <span class="recipient-credential-value recipient-row-value">${escapeHtml(
                    username
                  )}</span>
                </div>
                <div class="recipient-credential-row">
                  <span class="recipient-row-label">رمز عبور</span>
                  <span class="recipient-password-value recipient-row-value" role="button" tabindex="0" data-password="${escapeAttr(
                    password
                  )}">${escapeHtml(password)}</span>
                </div>
              </div>
              <div class="recipient-password-feedback" aria-live="polite"></div>
            </div>
          </div>
        `;

          await Swal.fire({
            title: "دسترسی کاربر گزارش‌گیری",
            html: modalHtml,
            focusConfirm: false,
            confirmButtonText: "بستن",
            customClass: {
              popup: "swal2-rtl swal2-glass recipient-modal",
              confirmButton: "btn btn-primary",
            },
            didOpen: (popup) => {
              try {
                const passwordEl = popup.querySelector(
                  ".recipient-password-value"
                );
                if (!passwordEl) return;
                const feedbackEl = popup.querySelector(
                  ".recipient-password-feedback"
                );
                const passwordValue =
                  passwordEl.getAttribute("data-password") || "";
                if (!passwordValue) return;

                // Ensure initial focus stays on the confirm button so the password stays blurred
                const confirmBtn = Swal.getConfirmButton();
                if (confirmBtn) {
                  confirmBtn.focus({ preventScroll: true });
                }
                passwordEl.blur();

                const updateFeedback = (state, message) => {
                  if (!feedbackEl) return;
                  feedbackEl.textContent = message;
                  feedbackEl.classList.remove("success", "error");
                  if (state) feedbackEl.classList.add(state);
                };

                const resetFeedback = () => updateFeedback("", "");

                const handleCopy = async () => {
                  const ok = await copyToClipboard(passwordValue);
                  if (ok) {
                    passwordEl.setAttribute("data-copied", "true");
                    updateFeedback("success", "رمز در کلیپ‌بورد کپی شد.");
                    setTimeout(() => {
                      passwordEl.removeAttribute("data-copied");
                      resetFeedback();
                    }, 1800);
                  } else {
                    updateFeedback("error", "کپی خودکار انجام نشد.");
                    setTimeout(resetFeedback, 1800);
                  }
                };

                passwordEl.addEventListener("click", handleCopy);
                passwordEl.addEventListener("keypress", (evt) => {
                  if (evt.key === "Enter" || evt.key === " ") {
                    evt.preventDefault();
                    handleCopy();
                  }
                });
              } catch (err) {
                console.warn("Recipient password popup init failed", err);
              }
            },
          });
        } catch (err) {
          Swal.close();
          if (err && err.isLicenseError) {
            return;
          }
          console.error("Failed to show recipient credentials", err);
          Swal.fire({
            icon: "error",
            title: "خطا",
            text:
              err && err.message
                ? err.message
                : "خطا در دریافت اطلاعات Recipient",
            confirmButtonText: "باشه",
            customClass: {
              popup: "swal2-rtl swal2-glass",
              confirmButton: "btn btn-primary",
            },
          });
        }
      });
    }
  } catch (e) {
    console.warn("Failed to init recipient credential modal", e);
  }
}

try {
  const proctorNoticeBtn = document.getElementById("proctorNoticeBtn");
  if (proctorNoticeBtn) {
    proctorNoticeBtn.addEventListener("click", () => {
      try {
        printProctorNotices();
      } catch (err) {
        console.error("printProctorNotices invocation failed:", err);
      }
    });
  }
} catch (e) {
  console.warn("Failed to init proctor notice button", e);
}

// Header shortcut for observers (proctor profiles)
try {
  const proctorProfilesBtn = document.getElementById("proctorProfilesBtn");
  if (proctorProfilesBtn) {
    proctorProfilesBtn.addEventListener("click", () => {
      window.location.href = "/dashboard/observers/";
    });
  }
} catch (e) {
  console.warn("Failed to init proctor profiles button", e);
}

// Header shortcut: ثبت غیبت (temporary toast)
try {
  const absentBtn = document.getElementById("absentBtn");
  if (absentBtn) {
    absentBtn.addEventListener("click", () => {
      try {
        Swal.fire({
          toast: true,
          position: "top-end",
          icon: "info",
          title: "ثبت غیبت پس از هماهنگی با پشتیبانی گلستان فعال خواهد شد.",
          showConfirmButton: false,
          timer: 2200,
          timerProgressBar: true,
          customClass: { popup: "swal2-rtl" },
        });
      } catch (err) {
        console.warn("Failed to show absent toast", err);
      }
    });
  }
} catch (e) {
  console.warn("Failed to init absent button", e);
}

// Update Database button: show temp table counts, warn and block in demo
try {
  const updateBtn = document.getElementById("updateDBBtn");
  if (updateBtn) {
    updateBtn.addEventListener("click", async () => {
      // Fetch current temp tables counts (e-exams / k-exams)
      let eCount = null,
        kCount = null;
      try {
        const resp = await guardedFetch("../API/getTempTablesCount.php", {
          cache: "no-store",
        });
        if (resp && resp.ok) {
          const data = await resp.json();
          eCount = Number(data.e_exams ?? 0);
          kCount = Number(data.k_exams ?? 0);
        }
      } catch (err) {
        // ignore, keep nulls
      }

      const toPd = (v) =>
        typeof toPersianDigits === "function" ? toPersianDigits(v) : String(v);
      const eTxt = eCount === null ? "نامشخص" : toPd(eCount);
      const kTxt = kCount === null ? "نامشخص" : toPd(kCount);

      if (eCount === 0 && kCount === 0) {
        await Swal.fire({
          icon: "info",
          title: "اطلاعات",
          text: "ابتدا اطلاعات آزمون‌ها را در قالب فایل اکسل آپلود کنید",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }

      const warningHtml = `
                <div style="text-align:justify;line-height:2">
                با انجام این عملیات، تمامی داده‌های فعلی پایگاه داده حذف می‌شود و <b>${eTxt}</b> رکورد از آزمون‌های الکترونیکی و <b>${kTxt}</b> رکورد از آزمون‌های کتبی برای جایگزینی داده‌های حذف‌شده استفاده خواهد شد. دقت کنید که این عمل غیر قابل بازگشت است.<br>آیا اطمینان دارید که برای این تغییر آماده هستید ؟
                </div>
            `;

      const res = await Swal.fire({
        icon: "warning",
        title: "تأیید به‌روزرسانی پایگاه داده",
        html: warningHtml,
        showCancelButton: true,
        confirmButtonText: "بله، موافقم",
        cancelButtonText: "لغو",
        reverseButtons: true,
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-danger",
          cancelButton: "btn btn-cancel",
        },
      });
      if (!res.isConfirmed) return;

      // Show update progress modal
      Swal.fire({
        title: "در حال به‌روزرسانی",
        html: `
                    <div style="text-align: center; padding: 1rem;">
                        <style>
                        /* Make progress digits monospaced/tabular for consistent width */
                        .tabular-digits { font-variant-numeric: tabular-nums; font-family: Vazir, 'DejaVu Sans Mono', monospace; letter-spacing: 0.01em; }
                        </style>
                        <div id="updateProgressDisplay" class="tabular-digits" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">۱٪</div>
                        <p id="updateProgressText" style="color: white; font-size: 1.1rem; margin:0;">در حال بررسی جداول موقت...</p>
                    </div>
                `,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: { popup: "swal2-rtl swal2-glass" },
      });

      // Animate and poll server progress
      let updProgress = 1;
      let updServerProgress = false;
      const updDisp = document.getElementById("updateProgressDisplay");
      const updText = document.getElementById("updateProgressText");

      const updAnim = setInterval(() => {
        if (updServerProgress) return;
        updProgress += Math.random() * 3 + 0.5;
        if (updProgress > 95) updProgress = 95;
        if (updDisp) {
          const val = Math.round(updProgress);
          const pers =
            typeof toPersianDigits === "function"
              ? toPersianDigits(val)
              : String(val);
          updDisp.textContent = pers + "٪";
        }
        if (updText) {
          if (updProgress < 30)
            updText.textContent = "در حال بررسی جداول موقت...";
          else if (updProgress < 60)
            updText.textContent = "در حال پاکسازی و آماده‌سازی...";
          else updText.textContent = "در حال درج اطلاعات...";
        }
      }, 300);

      const pollUpdate = async () => {
        try {
          const resp = await guardedFetch(
            `../API/getProcessProgress.php?filename=${encodeURIComponent(
              "update"
            )}`
          );
          if (!resp.ok) return;
          const payload = await resp.json();
          if (!payload) return;
          if (
            typeof payload.processedRows === "number" &&
            typeof payload.totalRows === "number" &&
            payload.totalRows > 0
          ) {
            updServerProgress = true;
            const percent = Math.min(
              99,
              Math.round((payload.processedRows / payload.totalRows) * 100)
            );
            const pers =
              typeof toPersianDigits === "function"
                ? toPersianDigits(percent)
                : String(percent);
            if (updDisp) updDisp.textContent = pers + "٪";
            if (updText)
              updText.textContent = payload.message || "در حال به‌روزرسانی...";
          } else if (payload.stage === "error") {
            updServerProgress = true;
            if (updDisp)
              updDisp.textContent =
                (typeof toPersianDigits === "function"
                  ? toPersianDigits(0)
                  : "0") + "٪";
            if (updText)
              updText.textContent = payload.message || "خطا در به‌روزرسانی";
          }
        } catch (e) {
          /* ignore */
        }
      };
      const updPoll = setInterval(pollUpdate, 500);

      try {
        const response = await guardedFetch("../API/updateDatabase.php", {
          method: "POST",
        });
        clearInterval(updAnim);
        clearInterval(updPoll);

        if (!response.ok) {
          Swal.close();
          if (response.status === 403) {
            let message = "دسترسی به این عملیات ممکن نیست.";
            try {
              const payload = await response.json();
              if (payload && payload.message) message = payload.message;
            } catch (err) {
              /* ignore */
            }
            showLicenseForbidden(message);
            return;
          }
          let errMsg = "خطا در به‌روزرسانی پایگاه داده";
          try {
            const payload = await response.json();
            if (payload && payload.error) errMsg = payload.error;
          } catch (e) {}
          throw new Error(errMsg);
        }

        const result = await response.json();
        if (result && result.success) {
          if (updDisp)
            updDisp.textContent =
              (typeof toPersianDigits === "function"
                ? toPersianDigits(100)
                : "100") + "٪";
          if (updText) updText.textContent = "به‌روزرسانی کامل شد!";
          setTimeout(async () => {
            Swal.close();
            const c = result.inserted?.courses ?? 0;
            const s = result.inserted?.students ?? 0;
            const es = result.inserted?.exam_seats ?? 0;
            const l = result.inserted?.locations ?? 0;
            const proctorResetHtml = result?.proctorsCleared
              ? '<br><span style="font-size:0.95rem;color:#ffffff;">جدول مشخصات مراقبین برای همگام‌سازی مجدد پاکسازی شد.</span>'
              : "";

            await Swal.fire({
              icon: "success",
              title: "موفق",
              width: "60rem",
              html: `به‌روزرسانی با موفقیت انجام شد<br>دروس: <b>${c}</b> | دانشجویان: <b>${s}</b> | صندلی‌ها: <b>${es}</b> | مکان‌ها: <b>${l}</b>${proctorResetHtml}`,
              confirmButtonText: "باشه",
              returnFocus: false, // Prevent scrolling back to the button
              customClass: {
                popup: "swal2-rtl swal2-glass",
                confirmButton: "btn btn-primary",
              },
            });
            // Refresh dashboard stats
            try {
              await loadDashboardData();
              // Ensure chart is refreshed
              try {
                await renderReportsChart();
                // Scroll to top of stats cards after chart refresh
                setTimeout(() => {
                  const statsRow = document.querySelector(
                    ".dashboard-container .row"
                  );
                  if (statsRow) {
                    statsRow.scrollIntoView({
                      behavior: "smooth",
                      block: "start",
                    });
                  }
                }, 500);
              } catch (e) {
                console.error("Chart refresh failed after update:", e);
              }
            } catch (e) {
              /* ignore */
            }
          }, 500);
        } else {
          Swal.close();
          throw new Error(result?.error || "خطای نامشخص");
        }
      } catch (error) {
        try {
          clearInterval(updAnim);
        } catch (e) {}
        try {
          clearInterval(updPoll);
        } catch (e) {}
        Swal.close();
        if (error?.isLicenseError) return; // already handled
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: error?.message || "خطا در به‌روزرسانی پایگاه داده",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
    });
  }
} catch (e) {
  console.warn("Failed to init updateDBBtn handler", e);
}

async function loadDashboardData() {
  try {
    const configResponse = await guardedFetch("../API/getConfig.php", {
      cache: "no-store",
    });
    const config = await configResponse.json();

    if (config.AdminNickName) {
      const usernameEl = document.getElementById("adminUsername");
      if (usernameEl) {
        usernameEl.textContent = config.AdminNickName;
      }
    }

    // Store config globally for use in reports
    window.appConfig = config;

    // Apply WavesAnimation setting on page load
    const wavesAnimationEnabled =
      String(config.WavesAnimation || "YES").toUpperCase() === "YES";
    if (wavesAnimationEnabled) {
      document.body.classList.remove("no-waves-animation");
    } else {
      document.body.classList.add("no-waves-animation");
    }

    const statsResponse = await guardedFetch("../API/getStatistics.php", {
      cache: "no-store",
    });
    const stats = await statsResponse.json();

    if (!stats.error) {
      document.getElementById("totalStudents").textContent =
        stats.totalStudents || 0;
      document.getElementById("totalCourses").textContent =
        stats.totalCourses || 0;
      document.getElementById("nextExamStudents").textContent =
        stats.nextExamStudents || 0;
      document.getElementById("nextExamDateTime").textContent =
        stats.nextExamDateTime || "آزمونی یافت نشد";

      // Disable/enable Students card based on value
      try {
        const studentsEl = document.getElementById("totalStudents");
        const studentsCard = studentsEl
          ? studentsEl.closest(".dashboard-card")
          : null;
        if (studentsCard) {
          if (!stats.totalStudents || stats.totalStudents === 0) {
            studentsCard.classList.add("stat-card-disabled");
            studentsCard.style.cursor = "default";
            studentsCard.style.pointerEvents = "none";
            try {
              studentsCard.onclick = null;
            } catch (e) {}
          } else {
            studentsCard.classList.remove("stat-card-disabled");
            studentsCard.style.cursor = "pointer";
            studentsCard.style.pointerEvents = "";
            try {
              studentsCard.onclick = showStudentReport;
            } catch (e) {}
          }
        }
      } catch (e) {
        /* ignore */
      }

      // Disable/enable Courses card based on value
      try {
        const coursesEl = document.getElementById("totalCourses");
        const coursesCard = coursesEl
          ? coursesEl.closest(".dashboard-card")
          : null;
        if (coursesCard) {
          if (!stats.totalCourses || stats.totalCourses === 0) {
            coursesCard.classList.add("stat-card-disabled");
            coursesCard.style.cursor = "default";
            coursesCard.style.pointerEvents = "none";
            try {
              coursesCard.onclick = null;
            } catch (e) {}
          } else {
            coursesCard.classList.remove("stat-card-disabled");
            coursesCard.style.cursor = "pointer";
            coursesCard.style.pointerEvents = "";
            try {
              coursesCard.onclick = showCourseReport;
            } catch (e) {}
          }
        }
      } catch (e) {
        /* ignore */
      }

      // Disable/enable Remaining Sessions card based on value
      if (typeof stats.remainingSessions !== "undefined") {
        const el = document.getElementById("remainingSessions");
        const card = el ? el.closest(".dashboard-card") : null;
        if (el) {
          if (!stats.remainingSessions || stats.remainingSessions === 0) {
            el.textContent = "۰";
            if (card) {
              card.classList.add("stat-card-disabled");

              card.style.cursor = "default";
            }
          } else {
            el.textContent = stats.remainingSessions;
            if (card) {
              card.classList.remove("stat-card-disabled");
              card.style.cursor = "pointer";
            }
          }
        }
      }

      // Disable/enable Next Exam card when no upcoming exam
      try {
        const nextLabelEl = document.getElementById("nextExamDateTime");
        const nextCard = nextLabelEl
          ? nextLabelEl.closest(".dashboard-card")
          : null;
        const noExam =
          !stats.nextExamStudents ||
          stats.nextExamStudents === 0 ||
          !stats.nextExamDateTime ||
          String(stats.nextExamDateTime).trim() === "" ||
          String(nextLabelEl?.textContent || "").trim() === "آزمونی یافت نشد";
        if (nextCard) {
          if (noExam) {
            nextCard.classList.add("stat-card-disabled");
            nextCard.style.cursor = "default";
            // neutralize click
            try {
              nextCard.onclick = null;
            } catch (e) {}
            nextCard.style.pointerEvents = "none";
          } else {
            nextCard.classList.remove("stat-card-disabled");
            nextCard.style.cursor = "pointer";
            nextCard.style.pointerEvents = "";
            // restore click handler in case it was removed
            try {
              nextCard.onclick = showNextExamReport;
            } catch (e) {}
          }
        }
      } catch (e) {
        /* ignore */
      }
    }

    // Render insight cards
    try {
      if (typeof renderInsightCards === "function") renderInsightCards(stats);
    } catch (e) {
      console.warn("Insight cards render failed", e);
    }

    try {
      renderReportsChart();
    } catch (e) {
      console.error("Chart render failed:", e);
    }
  } catch (error) {
    console.error("Error loading dashboard data:", error);
    if (!error?.isLicenseError) {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "خطا در بارگذاری اطلاعات",
      });
    }
  }
}

async function showRemainingSessions() {
  try {
    try {
      await loadChartJsIfNeeded();
    } catch (loadErr) {
      console.warn("Chart.js not available:", loadErr);
      const card = document.getElementById("reportsChartCard");
      if (card) {
        let ph = card.querySelector(".reports-chart-placeholder");
        if (!ph) {
          ph = document.createElement("div");
          ph.className = "reports-chart-placeholder";
          ph.style.cssText =
            "padding:1.5rem;color:var(--text-muted);text-align:center;font-size:1.05rem;";
          ph.textContent = "خطا در بارگذاری نمودار (Chart.js در دسترس نیست)";
          card.appendChild(ph);
        } else {
          ph.textContent = "خطا در بارگذاری نمودار (Chart.js در دسترس نیست)";
          ph.style.display = "block";
        }
      }
      return;
    }

    const resp = await guardedFetch("../API/getStatistics.php", {
      cache: "no-store",
    });
    const stats = await resp.json();
    const future = stats.futureExams || [];

    if (!future.length) {
      return Swal.fire({
        icon: "info",
        title: "اطلاعات",
        text: "جلسه آینده‌ای یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }

    let cardsHtml = '<div class="session-mini-grid">';
    future.forEach((f) => {
      const time = f.exam_time;
      const date = f.exam_date;
      const total = f.student_count || 0;

      const hour = parseInt((time || "00:00").split(":")[0], 10) || 0;
      const whenClass = hour < 12 ? "morning" : "afternoon";
      const label = `${time} | ${date}`;
      cardsHtml += `
                            <div class="session-mini-card ${whenClass}" data-exam-time="${time}" data-exam-date="${date}">
                                <div class="line1">${toPersianDigits(
                                  total
                                )}</div>
                                <div class="line2">${label}</div>
                            </div>`;
    });
    cardsHtml += "</div>";

    await Swal.fire({
      html: cardsHtml,
      width: "110rem",
      showCloseButton: false,
      showConfirmButton: false,
      customClass: { popup: "swal2-rtl swal2-glass" },
      didOpen: () => {
        const container = Swal.getHtmlContainer();
        if (!container) return;
        const cards = container.querySelectorAll(".session-mini-card");
        cards.forEach((card) => {
          card.addEventListener("click", () => {
            const t = card.getAttribute("data-exam-time");
            const d = card.getAttribute("data-exam-date");
            Swal.close();

            setTimeout(() => {
              applyNextExamOverride(d, t, {
                customTitle: `آزمون تاریخ ${d} ساعت ${t}`,
              });
              showNextExamReport();
            }, 120);
          });
        });
      },
    });
  } catch (err) {
    console.error("Error loading future exams:", err);
    Swal.fire({
      icon: "error",
      title: "خطا",
      text: "خطا در دریافت جلسات آینده",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

async function updateFooterUniversity() {
  try {
    const response = await guardedFetch("../API/getConfig.php", {
      cache: "no-store",
    });
    const config = await response.json();
    if (config.University) {
      document.getElementById(
        "footerText"
      ).textContent = `نسار - ${config.University}`;
    }
  } catch (error) {
    if (!error?.isLicenseError) {
      console.error("Error updating footer:", error);
    }
  }
}
updateFooterUniversity();

const copyrightFooter = document.getElementById("copyrightFooter");
if (copyrightFooter) {
  copyrightFooter.addEventListener("click", async () => {
    const VERSION = window.APP_VERSION;
    function toPersianDigits(num) {
      const persianDigits = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
      return String(num).replace(/\d/g, (d) => persianDigits[d]);
    }
    let countdownInterval;
    let university = "دانشگاه پیام نور مرکز بیجار";
    try {
      const configResponse = await guardedFetch("../API/getConfig.php", {
        cache: "no-store",
      });
      const config = await configResponse.json();
      if (config.University) {
        university = config.University;
      }
    } catch (error) {
      if (error?.isLicenseError) {
        return;
      }
      console.error("Error loading config for about modal:", error);
    }

    Swal.fire({
      title: "درباره نِسار",
      html: `
        <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
        داشبورد نِسار (نسخه ${VERSION}) یک اپلیکیشن تحت‌وب پیشرفته و مدرن است که با استفاده از خروجی‌های نرم‌افزار ساد، به همکاران دانشگاه پیام نور امکان می‌دهد برنامه‌ریزی و مدیریت آزمون‌ها، از جمله زمان‌بندی، تخصیص صندلی و ملزومات اجرایی را به‌صورت یکپارچه و متمرکز انجام داده و در عین حفظ ساختار رسمی در برگزاری، به صرفه‌جویی در زمان و منابع مورد نیاز برای آزمون کمک کند.
            <br>
      این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(
        university
      )}</span> و توسط <a href="https://t.me/RealDream" target="_blank" style="color: gold; font-weight: bold; text-decoration: none; border: none; outline: none;">مهدی حسنی</a> توسعه یافته است.
    </div>
    <div class="swal2-countdown">
      <span class="swal2-countdown-value">${toPersianDigits(30)}</span>
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
        if (valueEl) {
          let remaining = 30;
          countdownInterval = setInterval(() => {
            remaining--;
            if (remaining < 0) {
              clearInterval(countdownInterval);
            } else {
              valueEl.textContent = toPersianDigits(remaining);
            }
          }, 1000);
        }
      },
      willClose: () => {
        if (countdownInterval) clearInterval(countdownInterval);
      },
    });
  });
}

checkAuth().then((isAuthorized) => {
  if (isAuthorized) {
    loadDashboardData();
  }
});

let reportsChartInstance = null;

let chartDefaultsConfigured = false;

let reportsResizeRegistered = false;
let reportsResizeTimer = null;

function configureChartDefaults() {
  if (chartDefaultsConfigured) return;
  try {
    if (typeof Chart === "undefined") return;
    // Font family and global color
    Chart.defaults.font.family = "Vazir, sans-serif";
    // Keep default font size unchanged by not setting Chart.defaults.font.size
    // Set default color for axes and labels
    Chart.defaults.color = "#0b2a44";
    // Disable tooltips globally per user's preference
    if (!Chart.defaults.plugins) Chart.defaults.plugins = {};
    Chart.defaults.plugins.tooltip = Chart.defaults.plugins.tooltip || {};
    Chart.defaults.plugins.tooltip.enabled = false;
    // Legend labels styling
    Chart.defaults.plugins.legend = Chart.defaults.plugins.legend || {};
    Chart.defaults.plugins.legend.labels =
      Chart.defaults.plugins.legend.labels || {};
    Chart.defaults.plugins.legend.labels.family = "Vazir, sans-serif";
    Chart.defaults.plugins.legend.labels.color = "#0b2a44";
    // Set a sensible global aspect ratio
    Chart.defaults.maintainAspectRatio = true;
    Chart.defaults.aspectRatio = 16 / 9;
    chartDefaultsConfigured = true;
  } catch (e) {
    console.warn("Could not configure Chart defaults:", e);
  }
}

// Ensure Chart.js is loaded and available as a global. If it's not, dynamically load the vendor file.
function loadChartJsIfNeeded() {
  // Prefer dynamic import for ESM bundle and expose Chart as a global for legacy code
  return new Promise(async (resolve, reject) => {
    if (typeof Chart !== "undefined") return resolve();
    try {
      // Dynamic import of ESM Chart.js
      const mod = await import("../assets/vendor/chartjs/chart.min.js");
      // Chart may be named export or default
      const exported = mod.Chart || mod.default || mod;
      if (!exported)
        return reject(new Error("Chart module loaded but no export found"));
      // Expose as global for existing code
      window.Chart = exported;
      // Configure global defaults now that Chart is available
      try {
        configureChartDefaults();
      } catch (e) {
        /* ignore */
      }
      return resolve();
    } catch (err) {
      // Attempt fallback: try to load as a script tag (older browsers)
      try {
        const existing = document.querySelector("script[data-chart-loader]");
        if (existing) {
          existing.addEventListener("load", () => {
            if (typeof Chart !== "undefined") return resolve();
            return reject(new Error("Chart.js loaded but Chart is undefined"));
          });
          existing.addEventListener("error", () =>
            reject(new Error("Failed to load Chart.js"))
          );
          return;
        }
        const script = document.createElement("script");
        script.src = "../assets/vendor/chartjs/chart.min.js";
        script.async = true;
        script.setAttribute("data-chart-loader", "1");
        script.onload = () => {
          if (typeof Chart !== "undefined") {
            try {
              configureChartDefaults();
            } catch (e) {
              /* ignore */
            }
            return resolve();
          }
          return reject(new Error("Failed to load Chart.js"));
        };
        script.onerror = () => reject(new Error("Failed to load Chart.js"));
        document.head.appendChild(script);
      } catch (err2) {
        return reject(err);
      }
    }
  });
}

// Render a simple daily grouped bar chart of future exam sessions
async function renderReportsChart() {
  try {
    // Ensure Chart.js is available (dynamic import for ESM build)
    try {
      await loadChartJsIfNeeded();
      // ensure defaults configured before rendering any chart
      try {
        configureChartDefaults();
      } catch (e) {
        /* ignore */
      }
    } catch (loadErr) {
      console.warn("Chart.js not available for reports chart:", loadErr);
      const card = document.getElementById("reportsChartCard");
      if (card) {
        let ph = card.querySelector(".reports-chart-placeholder");
        if (!ph) {
          ph = document.createElement("div");
          ph.className = "reports-chart-placeholder";
          ph.style.cssText =
            "padding:1.5rem;color:var(--text-muted);text-align:center;font-size:1.05rem;";
          ph.textContent = "خطا در بارگذاری نمودار (Chart.js در دسترس نیست)";
          card.appendChild(ph);
        } else {
          ph.textContent = "خطا در بارگذاری نمودار (Chart.js در دسترس نیست)";
          ph.style.display = "block";
        }
      }
      return;
    }
    const resp = await guardedFetch("../API/getStatistics.php", {
      cache: "no-store",
    });
    const stats = await resp.json();
    const future = stats.futureExams || [];

    const card = document.getElementById("reportsChartCard");
    const canvas = document.getElementById("reportsChart");
    if (!card || !canvas) return;

    // Build mapping date -> time -> count, and collect ordered dates & times
    const dateMap = {}; // { date: { time: count } }
    const dateTs = {}; // earliest timestamp per date for sorting
    const timesSet = new Set();

    future.forEach((e) => {
      const d = e.exam_date || "";
      const t = e.exam_time || "";
      const cnt = Number(e.student_count) || 0;
      if (!dateMap[d]) dateMap[d] = {};
      if (!dateMap[d][t]) dateMap[d][t] = 0;
      dateMap[d][t] += cnt;
      timesSet.add(t);
      if (!dateTs[d] || e.timestamp < dateTs[d]) dateTs[d] = e.timestamp;
    });

    // Sort dates by earliest timestamp
    const labels = Object.keys(dateMap)
      .map((d) => ({ date: d, ts: dateTs[d] || 0 }))
      .sort((a, b) => a.ts - b.ts)
      .map((x) => x.date);
    // Sort times naturally (by hour and minute)
    const times = Array.from(timesSet).sort((a, b) => {
      const pa = (a || "00:00").split(":").map(Number);
      const pb = (b || "00:00").split(":").map(Number);
      return pa[0] - pb[0] || pa[1] - pb[1];
    });

    // Build datasets: one dataset per session time across all dates
    const palette = [
      "#1a6fa6",
      "#ff8a65",
      "#7bd5ff",
      "#9ccc65",
      "#ffca28",
      "#7e57c2",
      "#26a69a",
      "#ef5350",
    ];
    const datasets = times.map((time, idx) => {
      const dataArr = labels.map((date) =>
        dateMap[date] && dateMap[date][time] ? dateMap[date][time] : 0
      );
      return {
        // keep original (latin) label if needed, but display Persian digits in legend
        _rawLabel: time,
        label:
          typeof toPersianDigits === "function" ? toPersianDigits(time) : time,
        data: dataArr,
        backgroundColor: palette[idx % palette.length],
      };
    });

    // If the viewport is narrow, aggregate by date (sum across session times) to save horizontal space
    const viewportWidth =
      window.innerWidth || document.documentElement.clientWidth || 0;
    const isNarrow =
      viewportWidth < 900 ||
      (canvas && canvas.clientWidth && canvas.clientWidth < 520);
    let chartDatasets = datasets;
    if (isNarrow) {
      const aggregated = labels.map((_, idx) => {
        return datasets.reduce(
          (sum, ds) => sum + (Number(ds.data[idx]) || 0),
          0
        );
      });
      chartDatasets = [
        {
          label:
            typeof toPersianDigits === "function"
              ? toPersianDigits("مجموع")
              : "مجموع",
          data: aggregated,
          backgroundColor: "#1a6fa6",
        },
      ];
    }

    // If no data, show a compact placeholder (not a large empty chart)
    if (!labels.length) {
      try {
        canvas.style.display = "none";
      } catch (e) {
        /* ignore */
      }
      if (reportsChartInstance) {
        try {
          reportsChartInstance.destroy();
        } catch (er) {
          /* ignore */
        }
        reportsChartInstance = null;
      }

      // Ensure card is visible but show a small placeholder instead of a tall empty chart
      try {
        card.style.display = "block";
      } catch (e) {
        /* ignore */
      }

      // Remove any old placeholder
      try {
        const phOld = card.querySelector(".reports-chart-placeholder");
        if (phOld) phOld.remove();
      } catch (e) {
        /* ignore */
      }

      // Hide the chart wrapper area (the tall 16:9 box)
      try {
        const wrapper = card.querySelector(".chart-wrapper");
        if (wrapper) wrapper.style.display = "none";
      } catch (e) {
        /* ignore */
      }

      // Create a compact placeholder with a refresh action
      try {
        const ph = document.createElement("div");
        ph.className = "reports-chart-placeholder";
        ph.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:center;gap:10px;min-height:52px;">
                        <span style="color:var(--text-muted);">نموداری برای نمایش وجود ندارد.</span>
                    </div>
                `;
        card.appendChild(ph);
      } catch (e) {
        /* ignore */
      }
      return;
    }

    // Ensure the card is visible and remove any placeholder
    try {
      card.style.display = "block";
    } catch (e) {
      /* ignore */
    }
    const existingPh = card.querySelector(".reports-chart-placeholder");
    if (existingPh) existingPh.remove();
    canvas.style.display = "block";

    // Destroy previous instance if present
    if (reportsChartInstance) {
      try {
        reportsChartInstance.destroy();
      } catch (er) {
        /* ignore */
      }
      reportsChartInstance = null;
    }

    const ctx = canvas.getContext("2d");

    // (Removed) labels-on-bars plugin — per user request values above bars are not rendered.

    // convert x-axis labels (dates) to Persian digits for display
    const displayLabels =
      typeof toPersianDigits === "function"
        ? labels.map((l) => toPersianDigits(l))
        : labels;

    reportsChartInstance = new Chart(ctx, {
      type: "bar",
      data: {
        labels: displayLabels,
        datasets: chartDatasets,
      },
      options: {
        responsive: true,
        // prefer a 16:9 viewing frame on wide screens; allow flexible height on narrow
        maintainAspectRatio: !isNarrow,
        aspectRatio: 16 / 9,
        scales: {
          x: {
            ticks: {
              color: "#0b2a44",
              font: { family: "Vazir, sans-serif" },
              maxRotation: 90,
              minRotation: 90,
            },
            grid: { display: false },
            stacked: false,
          },
          y: {
            beginAtZero: true,
            ticks: {
              color: "#0b2a44",
              precision: 0,
              font: { family: "Vazir, sans-serif" },
              callback: function (value) {
                return typeof toPersianDigits === "function"
                  ? toPersianDigits(value)
                  : value;
              },
            },
            grid: { color: "rgba(11,42,68,0.06)" },
          },
        },
        plugins: {
          legend: {
            display: !isNarrow,
            position: "top",
            labels: { color: "#0b2a44", font: { family: "Vazir, sans-serif" } },
          },
          // enable a minimal tooltip that shows only the Persian-formatted value (no title, no color box)
          tooltip: {
            enabled: true,
            displayColors: false,
            bodyFont: { family: "Vazir, sans-serif" },
            callbacks: {
              title: function () {
                return "";
              },
              label: function (context) {
                // Prefer raw value, fallback to parsed y value
                const raw =
                  typeof context.raw !== "undefined"
                    ? context.raw
                    : context.parsed && context.parsed.y
                    ? context.parsed.y
                    : 0;
                const v = Number(raw) || 0;
                return typeof toPersianDigits === "function"
                  ? toPersianDigits(v)
                  : String(v);
              },
            },
          },
        },
      },
    });

    // Register a debounced resize handler once so chart re-renders and switches aggregation mode when window size changes
    try {
      if (!reportsResizeRegistered) {
        window.addEventListener("resize", () => {
          if (reportsResizeTimer) clearTimeout(reportsResizeTimer);
          reportsResizeTimer = setTimeout(() => {
            try {
              // Re-render reports chart which will pick aggregation based on current width
              renderReportsChart();
            } catch (e) {
              console.error("Error re-rendering reports chart on resize:", e);
            }
          }, 260);
        });
        reportsResizeRegistered = true;
      }
    } catch (e) {
      console.warn("Could not register resize handler for reports chart:", e);
    }

    // Render the two small overview pies beside the main chart
    // try { renderSmallOverviewPies(stats); } catch (e) { console.warn('Could not render overview pies:', e); }
  } catch (error) {
    console.error("Error rendering reports chart:", error);
  }
}
// Get max upload size from server
let MAX_UPLOAD_SIZE = 128 * 1024 * 1024; // Default 128MB
let MAX_UPLOAD_SIZE_FORMATTED = "۱۲۸ مگابایت";

async function loadUploadLimit() {
  try {
    const response = await guardedFetch("../API/getUploadLimit.php", {
      cache: "no-store",
    });
    const data = await response.json();
    if (data.maxSize) {
      MAX_UPLOAD_SIZE = data.maxSize;
      MAX_UPLOAD_SIZE_FORMATTED = data.maxSizeFormatted;
    }
  } catch (error) {
    console.error("Error loading upload limit:", error);
  }
}
loadUploadLimit();

// Database upload functionality
async function showUploadModal(examType) {
  const examTypeName = examType === "K" ? "کتبی" : "الکترونیکی";

  const { value: file } = await Swal.fire({
    title: `آپلود فایل آزمون‌های ${examTypeName}`,
    html: `
			<style>
				.upload-area {
					border: 3px dashed #28a745;
					border-radius: 15px;
					padding: 3rem 2rem;
					text-align: center;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.05) 0%, rgba(40, 167, 69, 0.1) 100%);
					cursor: pointer;
					transition: all 0.3s ease;
					margin-bottom: 1rem;
				}
				.upload-area:hover {
					border-color: #218838;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.15) 100%);
					transform: translateY(-2px);
				}
				.upload-area.dragover {
					border-color: #1e7e34;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.15) 0%, rgba(40, 167, 69, 0.2) 100%);
					transform: scale(1.02);
				}
				.upload-icon {
					font-size: 4rem;
					color: #28a745;
					margin-bottom: 1rem;
				}
				.upload-text {
					font-size: 1.2rem;
					color: #28a745;
					font-weight: 600;
					margin-bottom: 0.5rem;
				}
				.upload-hint {
					font-size: 0.95rem;
					color: #6c757d;
					margin-bottom: 1rem;
				}
				.file-name-display {
					background: #e8f5e9;
					padding: 0.75rem;
					border-radius: 8px;
					margin-top: 1rem;
					color: #28a745;
					font-weight: 600;
					display: none;
				}
				.browse-btn {
					background: #28a745;
					color: white;
					border: none;
					padding: 0.75rem 2rem;
					border-radius: 8px;
					font-family: 'Vazir', sans-serif;
					font-size: 1rem;
					cursor: pointer;
					transition: all 0.3s ease;
					font-weight: 600;
				}
				.browse-btn:hover {
					background: #218838;
					transform: translateY(-2px);
					box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
				}
				#databaseFile {
					display: none;
				}
			</style>
			<div style="text-align: center;">
				<div class="upload-area" id="uploadArea">
					<div class="upload-icon">📄</div>
					<div class="upload-text">فایل اکسل را اینجا بکشید</div>
					<div class="upload-hint">یا روی دکمه زیر کلیک کنید</div>
					<button type="button" class="browse-btn" id="browseBtn">انتخاب فایل</button>
					<div class="file-name-display" id="fileNameDisplay"></div>
				</div>
				<p style="font-size: 0.9rem; color: #6c757d; margin: 0;">
					فرمت‌ مجاز: XLSX | حداکثر حجم: ${MAX_UPLOAD_SIZE_FORMATTED}
				</p>
				<input type="file" id="databaseFile" accept=".xls,.xlsx">
			</div>
		`,
    showCancelButton: true,
    width: "60rem",
    confirmButtonText: "آپلود فایل",
    cancelButtonText: "انصراف",
    customClass: {
      popup: "swal2-rtl swal2-glass",
      confirmButton: "btn btn-primary",
      cancelButton: "btn btn-cancel",
    },
    preConfirm: () => {
      const fileInput = document.getElementById("databaseFile");
      if (!fileInput.files || fileInput.files.length === 0) {
        Swal.showValidationMessage("لطفاً یک فایل انتخاب کنید");
        return false;
      }

      const file = fileInput.files[0];
      const fileName = file.name.toLowerCase();

      if (!fileName.endsWith(".xls") && !fileName.endsWith(".xlsx")) {
        Swal.showValidationMessage(
          "فقط فایل‌های با پسوند XLS و XLSX مجاز هستند"
        );
        return false;
      }

      // Check file size (use server's max upload size)
      if (file.size > MAX_UPLOAD_SIZE) {
        Swal.showValidationMessage(
          `حجم فایل نباید بیشتر از ${MAX_UPLOAD_SIZE_FORMATTED} باشد`
        );
        return false;
      }

      return file;
    },
    didOpen: () => {
      const fileInput = document.getElementById("databaseFile");
      const uploadArea = document.getElementById("uploadArea");
      const browseBtn = document.getElementById("browseBtn");
      const fileNameDisplay = document.getElementById("fileNameDisplay");

      // Browse button click
      if (browseBtn) {
        browseBtn.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          fileInput.click();
        });
      }

      // Upload area click
      if (uploadArea) {
        uploadArea.addEventListener("click", (e) => {
          if (e.target !== browseBtn) {
            fileInput.click();
          }
        });
      }

      // File input change
      if (fileInput) {
        fileInput.addEventListener("change", (e) => {
          if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];
            fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
            fileNameDisplay.style.display = "block";
          }
        });
      }

      // Drag and drop events
      if (uploadArea) {
        ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
          uploadArea.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
          });
        });

        ["dragenter", "dragover"].forEach((eventName) => {
          uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.add("dragover");
          });
        });

        ["dragleave", "drop"].forEach((eventName) => {
          uploadArea.addEventListener(eventName, () => {
            uploadArea.classList.remove("dragover");
          });
        });

        uploadArea.addEventListener("drop", (e) => {
          const files = e.dataTransfer.files;
          if (files.length > 0) {
            fileInput.files = files;
            const file = files[0];
            fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
            fileNameDisplay.style.display = "block";
          }
        });
      }
    },
  });

  if (file) {
    await uploadDatabaseFile(file, examType, examTypeName);
  }
}

async function uploadDatabaseFile(file, examType, examTypeName) {
  // Show progress modal
  Swal.fire({
    title: "در حال آپلود",
    html: `
			<div style="text-align: center; padding: 1rem;">
                <style>
                /* Make progress digits monospaced/tabular for consistent width */
                .tabular-digits { font-variant-numeric: tabular-nums; font-family: Vazir, 'DejaVu Sans Mono', monospace; letter-spacing: 0.01em; }
                </style>
                <div id="uploadProgressDisplay" class="tabular-digits" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">۰٪</div>
                <p id="uploadProgressText" style="color: white; font-size: 1.1rem; margin:0; display:none;">در حال آپلود...</p>
			</div>
		`,
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: {
      popup: "swal2-rtl swal2-glass",
    },
  });

  const formData = new FormData();
  formData.append("file", file);
  formData.append("examType", examType);

  try {
    const xhr = new XMLHttpRequest();

    // Track upload progress
    xhr.upload.addEventListener("progress", (e) => {
      if (e.lengthComputable) {
        const percentComplete = Math.round((e.loaded / e.total) * 100);
        const progressDisplay = document.getElementById(
          "uploadProgressDisplay"
        );
        const progressText = document.getElementById("uploadProgressText");

        const pers =
          typeof toPersianDigits === "function"
            ? toPersianDigits(percentComplete)
            : String(percentComplete);

        if (progressDisplay) {
          progressDisplay.textContent = pers + "٪";
        }

        //if (progressText) {
        // progressText.textContent = `در حال آپلود...`;
        // }
      }
    });

    // Handle completion
    xhr.addEventListener("load", async () => {
      if (xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          if (response.success) {
            // Update existing modal to show processing status (don't create new Swal)
            const progressDisplay = document.getElementById(
              "uploadProgressDisplay"
            );
            if (progressDisplay) {
              progressDisplay.innerHTML = `<span class="spinner-border" style="width:2rem;height:2rem;" role="status"></span>`;
            }
            const progressText = document.getElementById("uploadProgressText");
            if (progressText) {
              progressText.textContent = "آپلود کامل شد. در حال پردازش فایل...";
              progressText.style.display = "block";
            }

            // Now process the uploaded Excel file (pass true to skip creating new modal)
            await processUploadedExcel(
              examType,
              examTypeName,
              response.filename,
              true // useExistingModal
            );
          } else {
            throw new Error(response.error || "خطای نامشخص");
          }
        } catch (parseError) {
          throw new Error("خطا در پردازش پاسخ سرور");
        }
      } else if (xhr.status === 403) {
        let errorMessage = "دسترسی به این عملیات ممکن نیست.";
        try {
          const errorResponse = JSON.parse(xhr.responseText);
          if (errorResponse && errorResponse.message) {
            errorMessage = errorResponse.message;
          }
        } catch (e) {
          // Use default message
        }
        showLicenseForbidden(errorMessage);
      } else {
        let errorMessage = "خطا در آپلود فایل";
        try {
          const errorResponse = JSON.parse(xhr.responseText);
          if (errorResponse && errorResponse.error) {
            errorMessage = errorResponse.error;
          }
        } catch (e) {
          // Use default message
        }

        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: errorMessage,
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
    });

    // Handle errors
    xhr.addEventListener("error", async () => {
      await Swal.fire({
        icon: "error",
        title: "خطا",
        text: "خطا در برقراری ارتباط با سرور",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    });

    // Get CSRF token
    const csrfToken = document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content");

    // Send request
    xhr.open("POST", "../API/uploadDatabase.php", true);
    if (csrfToken) {
      xhr.setRequestHeader("X-CSRF-Token", csrfToken);
    }
    xhr.send(formData);
  } catch (error) {
    console.error("Upload error:", error);
    await Swal.fire({
      icon: "error",
      title: "خطا",
      text: error.message || "خطا در آپلود فایل",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

// Process uploaded Excel file to temp table
async function processUploadedExcel(
  examType,
  examTypeName,
  filename,
  useExistingModal = false
) {
  // Show processing modal only if not using existing one
  if (!useExistingModal) {
    Swal.fire({
      title: "در حال پردازش",
      html: `
            <div style="text-align: center; padding: 1rem;">
                <style>
                /* Make progress digits monospaced/tabular for consistent width */
                .tabular-digits { font-variant-numeric: tabular-nums; font-family: Vazir, 'DejaVu Sans Mono', monospace; letter-spacing: 0.01em; }
                </style>
                <div id="processProgressDisplay" class="tabular-digits" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">۰٪</div>
                <p id="processProgressText" style="color: white; font-size: 1.1rem; margin:0;">در حال خواندن فایل اکسل...</p>
            </div>
        `,
      allowOutsideClick: false,
      allowEscapeKey: false,
      showConfirmButton: false,
      customClass: {
        popup: "swal2-rtl swal2-glass",
      },
    });
  }

  // Get progress elements (might be from upload modal or process modal)
  const progressDisplay = useExistingModal
    ? document.getElementById("uploadProgressDisplay")
    : document.getElementById("processProgressDisplay");
  const progressText = useExistingModal
    ? document.getElementById("uploadProgressText")
    : document.getElementById("processProgressText");

  // Before starting heavy processing, validate header mapping quickly on server
  try {
    const validateForm = new FormData();
    validateForm.append("filename", filename);
    validateForm.append("examType", examType);
    const validateResp = await guardedFetch("../API/validateExcelHeader.php", {
      method: "POST",
      body: validateForm,
    });
    if (!validateResp.ok) {
      // Read error message if possible
      let msg = "فایل منطبق با ساختار فایل نرم افزار ساد نیست";
      try {
        const payload = await validateResp.json();
        if (payload && payload.error) msg = payload.error;
      } catch (e) {}
      Swal.close();
      await Swal.fire({
        icon: "error",
        title: "خطا",
        text: msg,
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
      return;
    }
    const validateData = await validateResp.json();
    // If validate returned totalRows, we can show it in UI later
    const serverTotalRows = validateData.totalRows || 0;
  } catch (e) {
    // Validation failed unexpectedly; continue to processing which will handle it server-side
    console.warn("Header validation failed:", e);
  }

  // Animate progress bar and poll server for real progress
  let progress = 1;
  let serverProgressAvailable = false;

  const animInterval = setInterval(() => {
    if (serverProgressAvailable) return; // server will drive progress
    progress += Math.random() * 3 + 0.5; // Slower increase
    if (progress > 95) progress = 95; // Stay longer at high % until server finishes
    if (progressDisplay) {
      const pers =
        typeof toPersianDigits === "function"
          ? toPersianDigits(Math.round(progress))
          : String(Math.round(progress));
      progressDisplay.textContent = pers + "٪";
      progressDisplay.classList.add("tabular-digits");
    }
    if (progressText) {
      if (progress < 30) {
        progressText.textContent = "در حال خواندن فایل اکسل...";
      } else if (progress < 60) {
        progressText.textContent = "در حال پردازش داده‌ها...";
      } else {
        progressText.textContent = "در حال ذخیره در دیتابیس...";
      }
    }
  }, 300); // Slower interval

  // Polling server-side progress file
  const pollProgress = async () => {
    try {
      const resp = await guardedFetch(
        `../API/getProcessProgress.php?filename=${encodeURIComponent(filename)}`
      );
      if (!resp.ok) return;
      const payload = await resp.json();
      if (!payload) return;
      // If server provides totalRows, use it to compute real percent
      if (payload.totalRows && payload.totalRows > 0) {
        serverProgressAvailable = true;
        const percent = Math.min(
          99,
          Math.round((payload.processedRows / payload.totalRows) * 100)
        );
        const pers =
          typeof toPersianDigits === "function"
            ? toPersianDigits(percent)
            : String(percent);
        if (progressDisplay) progressDisplay.textContent = pers + "٪";
        if (progressText)
          progressText.textContent = payload.message || "در حال پردازش...";
      } else if (payload.stage === "error") {
        // show server-side validation error
        serverProgressAvailable = true;
        if (progressDisplay)
          progressDisplay.textContent =
            (typeof toPersianDigits === "function" ? toPersianDigits(0) : "0") +
            "٪";
        if (progressText)
          progressText.textContent = payload.message || "خطا در پردازش";
      }
    } catch (e) {
      // ignore polling errors
    }
  };

  const pollInterval = setInterval(pollProgress, 500);
  try {
    const formData = new FormData();
    formData.append("examType", examType);
    formData.append("filename", filename);

    const response = await guardedFetch("../API/processExcelToTemp.php", {
      method: "POST",
      body: formData,
    });

    // Stop polling and animation (server will have final status)
    clearInterval(animInterval);
    clearInterval(pollInterval);

    if (!response.ok) {
      Swal.close();
      if (response.status === 403) {
        let message = "دسترسی به این عملیات ممکن نیست.";
        try {
          const payload = await response.json();
          if (payload && payload.message) {
            message = payload.message;
          }
        } catch (error) {
          // Ignore JSON parsing errors
        }
        showLicenseForbidden(message);
        return;
      } else {
        let errorMessage = "خطا در پردازش فایل";
        try {
          const errorResponse = await response.json();
          if (errorResponse && errorResponse.error) {
            errorMessage = errorResponse.error;
          }
        } catch (e) {
          // Use default message
        }
        throw new Error(errorMessage);
      }
    }

    const result = await response.json();
    if (result.success) {
      // Set to 100%
      if (progressDisplay) {
        progressDisplay.textContent =
          (typeof toPersianDigits === "function"
            ? toPersianDigits(100)
            : "100") + "٪";
      }
      if (progressText) {
        progressText.textContent = "پردازش کامل شد!";
      }
      // Wait a bit then show success
      setTimeout(() => {
        Swal.close();
        Swal.fire({
          icon: "success",
          title: "موفق",
          html: `فایل اکسل با موفقیت پردازش شد<br>تعداد ردیف‌ها: ${result.rows}<br>تعداد ستون‌ها: ${result.columns}`,
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }, 500);
    } else {
      Swal.close();
      throw new Error(result.error || "خطای نامشخص");
    }
  } catch (error) {
    if (typeof animInterval !== "undefined")
      try {
        clearInterval(animInterval);
      } catch (e) {}
    if (typeof pollInterval !== "undefined")
      try {
        clearInterval(pollInterval);
      } catch (e) {}
    Swal.close();
    console.error("Process error:", error);
    await Swal.fire({
      icon: "error",
      title: "خطا",
      text: error.message || "خطا در پردازش فایل",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

async function filterStudentsByCourse(courseCode) {
  // Remove active class from all course items
  document
    .querySelectorAll(".course-item")
    .forEach((item) => item.classList.remove("active"));
  // Add active to clicked item
  event.currentTarget.classList.add("active");

  try {
    const response = await guardedFetch(
      `../API/getCourseReport.php?course_code=${encodeURIComponent(
        courseCode
      )}`,
      { cache: "no-store" }
    );
    const data = await response.json();

    if (data.error) {
      throw new Error(data.error);
    }

    const students = data.students;

    // Update tbody
    let tbodyHtml = "";
    students.forEach((student, index) => {
      tbodyHtml += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${student.student_id}</td>
                    <td><span class="text-secondary">${
                      student.last_name
                    }</span></td>
                    <td>${student.first_name}</td>
                    <td>${courseCode}</td>
                    <td>${data.course.course_name}</td>
                    <td><span class="text-secondary">${
                      student.seat_number
                    }</span></td>
                    <td>${student.class_name}</td>
                    <td><span class="badge ${getExamBadgeClass(
                      student.exam_type
                    )}">${student.exam_type}</span></td>
                </tr>
            `;
    });

    const tbody = document.querySelector("#studentsTableBody");
    if (tbody) tbody.innerHTML = tbodyHtml;

    // Update header to show course name
    const studentsHeader = document.getElementById("studentsListHeader");
    if (studentsHeader) {
      studentsHeader.innerHTML = `<span style="color:#e91e63">${courseCode}</span> <span style="color:#00bcd4">${data.course.course_name}</span>`;
    }

    // Remove the initial info prompt (only show it until the first time names are loaded)
    const info = document.getElementById("studentsTableInfo");
    if (info)
      try {
        info.remove();
      } catch (e) {
        info.style.display = "none";
      }

    // Reveal the students table wrapper if hidden
    const wrap = document.getElementById("studentsTableWrap");
    if (wrap && wrap.classList.contains("d-none"))
      wrap.classList.remove("d-none");

    // Scroll the top of the students list into view
    setTimeout(() => {
      const studentsHeader = document.getElementById("studentsListHeader");
      if (studentsHeader) {
        studentsHeader.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }, 300);
  } catch (error) {
    console.error("Error:", error);
    Swal.fire({
      icon: "error",
      title: "خطا",
      text: "خطا در فیلتر دانشجویان",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

function showAllStudents() {
  // Remove active class from all course items
  document
    .querySelectorAll(".course-item")
    .forEach((item) => item.classList.remove("active"));
  // Add active to clicked item
  event.currentTarget.classList.add("active");

  // Update tbody with all students
  let tbodyHtml = "";
  window.allStudents.forEach((student, index) => {
    tbodyHtml += `
            <tr>
                <td>${index + 1}</td>
                <td>${student.student_id}</td>
                <td><span class="text-secondary">${
                  student.last_name
                }</span></td>
                <td>${student.first_name}</td>
                <td>${student.course_code}</td>
                <td>${student.course_name}</td>
                <td><span class="text-secondary">${
                  student.seat_number
                }</span></td>
                <td>${student.class_name}</td>
                <td><span class="badge ${getExamBadgeClass(
                  student.exam_type
                )}">${student.exam_type}</span></td>
            </tr>
        `;
  });

  const tbody = document.querySelector("#studentsTableBody");
  if (tbody) tbody.innerHTML = tbodyHtml;

  // Reset header to default
  const studentsHeader = document.getElementById("studentsListHeader");
  if (studentsHeader) {
    studentsHeader.textContent = "لیست دانشجویان";
  }

  // Remove the initial info prompt (only show it until the first time names are loaded)
  const info = document.getElementById("studentsTableInfo");
  if (info)
    try {
      info.remove();
    } catch (e) {
      info.style.display = "none";
    }

  // Reveal the students table wrapper if hidden
  const wrap = document.getElementById("studentsTableWrap");
  if (wrap && wrap.classList.contains("d-none"))
    wrap.classList.remove("d-none");

  // Scroll the top of the students list into view
  setTimeout(() => {
    const studentsHeader = document.getElementById("studentsListHeader");
    if (studentsHeader) {
      studentsHeader.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, 300);
}

// Add event listeners to upload buttons
const uploadWrittenBtn = document.getElementById("uploadWrittenBtn");
if (uploadWrittenBtn) {
  uploadWrittenBtn.addEventListener("click", () => {
    showUploadModal("K");
  });
}

const uploadElectronicBtn = document.getElementById("uploadElectronicBtn");
if (uploadElectronicBtn) {
  uploadElectronicBtn.addEventListener("click", () => {
    showUploadModal("E");
  });
}

function scrollReportCardIntoView_OLD() {
  const reportCard = document.getElementById("reportCard");
  if (!reportCard) return;

  reportCard.scrollIntoView({ behavior: "smooth", block: "start" });
}
function clearReport() {
  document.getElementById("reportCard").style.display = "none";
  document.getElementById("reportContent").innerHTML = "";
  // Smooth scroll to top of page
  const container = document.querySelector(".dashboard-container");
  if (!container) return;
  const targetTop = container.getBoundingClientRect().top + window.pageYOffset;
  window.scrollTo({ top: Math.max(0, targetTop), behavior: "smooth" });
  // Rebuild the reports chart when the report is cleared
  try {
    renderReportsChart();
  } catch (e) {
    /* ignore */
  }
}

async function showStudentReport() {
  const { value: studentId } = await Swal.fire({
    title: "جستجوی دانشجو",
    html: '<input id="studentIdInput" type="tel" inputmode="numeric" class="swal2-input" placeholder="شماره دانشجویی" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "جستجو",
    cancelButtonText: "انصراف",
    customClass: {
      popup: "swal2-rtl swal2-glass",
      confirmButton: "btn btn-primary",
      cancelButton: "btn btn-cancel",
    },
    preConfirm: () => {
      const input = document.getElementById("studentIdInput");
      if (!input.value) {
        Swal.showValidationMessage("لطفاً شماره دانشجویی را وارد کنید");
        return false;
      }
      return input.value;
    },
  });

  if (studentId) {
    try {
      // حذف Swal.fire بارگذاری
      const response = await guardedFetch(
        `../API/getStudentReport.php?student_id=${encodeURIComponent(
          studentId
        )}`,
        { cache: "no-store" }
      );
      const data = await response.json();

      if (data.error) {
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: data.error,
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }

      // Display student info and exams
      const student = data.student;
      const exams = data.exams;

      let html = `
				<div class="mb-4">
					<h5 class="text-primary mb-3">مشخصات دانشجو</h5>
					<div class="table-responsive">
						<table class="table table-bordered">
							<tr>
								<th style="width: 30%;">شماره دانشجویی</th>
								<td>${student.student_id}</td>
							</tr>
							<tr>
								<th>نام و نام خانوادگی</th>
								<td>${student.first_name} ${student.last_name}</td>
							</tr>
							<tr>
								<th>کد ملی</th>
								<td>${student.national_id}</td>
							</tr>
							<tr>
								<th>مقطع تحصیلی</th>
								<td>${student.degree}</td>
							</tr>
						</table>
					</div>
				</div>
			`;

      if (exams && exams.length > 0) {
        html += `
					<div>
						<h5 class="text-primary mb-3">آزمون‌های دانشجو</h5>
						<div class="table-responsive">
							<table class="table table-striped table-hover">
								<thead class="table-light">
									<tr>
										<th>ردیف</th>
										<th>کد درس</th>
										<th>نام درس</th>
										<th>تاریخ</th>
										<th>ساعت</th>
										<th>شماره صندلی</th>
										<th>کلاس</th>
										<th>نوع درس</th>
										<th>نوع آزمون</th>
									</tr>
								</thead>
								<tbody>
					`;

        exams.forEach((exam, index) => {
          html += `
						<tr>
							<td>${index + 1}</td>
							<td>${exam.course_code}</td>
							<td>${exam.course_name}</td>
							<td>${exam.exam_date}</td>
							<td>${exam.exam_time}</td>
							<td><span class="text-secondary">${exam.seat_number}</span></td>
							<td>${exam.class_name}</td>
							<td><span class="badge bg-${
                exam.course_type === "کتبی" ? "success" : "info"
              }">${exam.course_type}</span></td>
                            <td><span class="badge ${getExamBadgeClass(
                              exam.exam_type
                            )}">${exam.exam_type}</span></td>
						</tr>
					`;
        });

        html += `
								</tbody>
							</table>
						</div>
					</div>
				`;
      } else {
        html +=
          '<p class="text-muted text-center mt-3">این دانشجو در هیچ آزمونی ثبت‌نام نکرده است.</p>';
      }

      document.getElementById("reportContent").innerHTML = html;
      const reportCard = document.getElementById("reportCard");
      reportCard.style.display = "block";
      reportCard.classList.add("fade-in-up");
      setTimeout(() => scrollToReportCardWithRetry(), 500);
    } catch (error) {
      console.error("Error:", error);
      if (!error?.isLicenseError) {
        Swal.fire({
          icon: "error",
          title: "خطا",
          text: "خطا در دریافت اطلاعات",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
    }
  }
}

async function loadCourseReportByCode(courseCode, options = {}) {
  const { showErrors = true } = options;
  if (!courseCode) return false;
  try {
    const response = await guardedFetch(
      `../API/getCourseReport.php?course_code=${encodeURIComponent(
        courseCode
      )}`,
      { cache: "no-store" }
    );
    const data = await response.json();

    if (data.error) {
      if (showErrors) {
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: data.error,
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
      }
      return false;
    }

    const course = data.course;
    const students = data.students;

    let html = `
            <div class="mb-4">
                <h5 class="text-primary mb-3">مشخصات درس</h5>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <th style="width: 30%;">کد درس</th>
                            <td>${course.course_code}</td>
                        </tr>
                        <tr>
                            <th>نام درس</th>
                            <td>${course.course_name}</td>
                        </tr>
                        <tr>
                            <th>تاریخ آزمون</th>
                            <td>${course.exam_date}</td>
                        </tr>
                        <tr>
                            <th>ساعت آزمون</th>
                            <td>${course.exam_time}</td>
                        </tr>
                        <tr>
                            <th>نوع درس</th>
                            <td><span class="badge bg-${
                              course.course_type === "کتبی" ? "success" : "info"
                            }">${course.course_type}</span></td>
                        </tr>
                        <tr>
                            <th>تعداد دانشجویان</th>
                            <td><span class="text-secondary">${
                              students.length
                            }</span> نفر</td>
                        </tr>
                    </table>
                </div>
            </div>
        `;

    if (students && students.length > 0) {
      html += `
            <div>
                <h5 class="text-primary mb-3">لیست دانشجویان</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ردیف</th>
                                <th>شماره دانشجویی</th>
                                <th>نام خانوادگی</th>
                                <th>نام</th>
                                <th>مقطع</th>
                                <th>شماره صندلی</th>
                                <th>کلاس</th>
                                <th>نوع آزمون</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

      students.forEach((student, index) => {
        html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${student.student_id}</td>
                        <td><span class="text-secondary">${
                          student.last_name
                        }</span></td>
                        <td>${student.first_name}</td>
                        <td>${student.degree}</td>
                        <td><span class="text-secondary">${
                          student.seat_number
                        }</span></td>
                        <td>${student.class_name}</td>
                            <td><span class="badge ${getExamBadgeClass(
                              student.exam_type
                            )}">${student.exam_type}</span></td>
                    </tr>
                `;
      });

      html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    } else {
      html +=
        '<p class="text-muted text-center mt-3">هیچ دانشجویی در این درس ثبت‌نام نکرده است.</p>';
    }

    document.getElementById("reportContent").innerHTML = html;
    const reportCard = document.getElementById("reportCard");
    reportCard.style.display = "block";
    reportCard.classList.add("fade-in-up");
    setTimeout(() => scrollToReportCardWithRetry(), 500);
    return true;
  } catch (error) {
    console.error("Error:", error);
    if (!error?.isLicenseError && showErrors) {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "خطا در دریافت اطلاعات",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
    return false;
  }
}

async function showCourseReport() {
  const { value: courseCode } = await Swal.fire({
    title: "جستجوی درس",
    html: '<input id="courseCodeInput" type="tel" inputmode="numeric" class="swal2-input" placeholder="کد درس" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "جستجو",
    cancelButtonText: "انصراف",
    customClass: {
      popup: "swal2-rtl swal2-glass",
      confirmButton: "btn btn-primary",
      cancelButton: "btn btn-cancel",
    },
    preConfirm: () => {
      const input = document.getElementById("courseCodeInput");
      if (!input.value) {
        Swal.showValidationMessage("لطفاً کد درس را وارد کنید");
        return false;
      }
      return input.value;
    },
  });

  if (courseCode) {
    await loadCourseReportByCode(courseCode, { showErrors: true });
  }
}

function applyNextExamOverride(examDate, examTime, options = {}) {
  if (!examDate || !examTime) return;
  const nextEl = document.getElementById("nextExamDateTime");
  if (!nextEl) return;

  const normalizedDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
  const normalizedTime = toEnglishDigits(String(examTime));

  setLastExamContext(normalizedDate, normalizedTime);

  if (window._overrideExamContext && window._overrideExamContext.active) {
    // Update existing override with new values but keep original label reference
    window._overrideExamContext.exam_date = normalizedDate;
    window._overrideExamContext.exam_time = normalizedTime;
    window._overrideExamContext.customTitle = options.customTitle || null;
    window._overrideExamContext.display_text = `${normalizedTime} | ${normalizedDate}`;
  } else {
    window._overrideExamContext = {
      exam_date: normalizedDate,
      exam_time: normalizedTime,
      previous_text: nextEl.textContent,
      customTitle: options.customTitle || null,
      active: true,
      display_text: `${normalizedTime} | ${normalizedDate}`,
    };
  }

  nextEl.textContent = window._overrideExamContext.display_text;
  if (window._overrideExamContext.customTitle) {
    window.customExamReportTitle = window._overrideExamContext.customTitle;
  }
}

async function showNextExamReport() {
  const nextEl = document.getElementById("nextExamDateTime");
  const override =
    window._overrideExamContext && window._overrideExamContext.active
      ? window._overrideExamContext
      : null;
  const originalLabel = override
    ? override.previous_text ?? (nextEl ? nextEl.textContent : "")
    : null;

  try {
    let examTime = "";
    let examDate = "";
    if (override) {
      examTime = override.exam_time;
      examDate = override.exam_date;
      setLastExamContext(examDate, examTime);
    } else {
      if (!nextEl) {
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: "زمان آزمون در دسترس نیست",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }
      // Get exam date and time from the label
      const nextExamDateTimeText = nextEl.textContent;

      // Check if there's no exam
      if (
        nextExamDateTimeText === "بارگذاری..." ||
        nextExamDateTimeText === "آزمونی یافت نشد"
      ) {
        await Swal.fire({
          icon: "info",
          title: "اطلاعات",
          text: "آزمون بعدی یافت نشد",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }

      // Parse the format: "HH:MM | YYYY/MM/DD"
      const parts = nextExamDateTimeText.split("|").map((s) => s.trim());
      if (parts.length !== 2) {
        await Swal.fire({
          icon: "error",
          title: "خطا",
          text: "فرمت تاریخ و ساعت نامعتبر است",
          confirmButtonText: "باشه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }

      examTime = toEnglishDigits(parts[0]);
      examDate = toEnglishDigits(parts[1]).replace(/-/g, "/");
      setLastExamContext(examDate, examTime);
    }

    // حذف Swal.fire بارگذاری
    const response = await guardedFetch(
      `../API/getNextExamReport.php?exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}`,
      { cache: "no-store" }
    );
    const data = await response.json();

    if (data.error) {
      await Swal.fire({
        icon: "error",
        title: "خطا",
        text: data.error,
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
      return;
    }

    // Display exam info and students
    const courses = data.courses;
    const students = data.students;

    // Store students and full report globally for other actions (printing, essentials)
    window.allStudents = students;
    // keep the full report response so printing helpers can reuse it
    window.currentExamReport = data;
    setLastExamContext(data.exam_date, data.exam_time);

    // Build a compact inline info string that always shows 4 items: date | time | courses | students
    const dateDisplayInline = data.exam_date
      ? toPersianDigits(data.exam_date)
      : "بدون تاریخ";
    const timeDisplayInline = data.exam_time
      ? toPersianDigits(data.exam_time)
      : "بدون ساعت";
    const courseCountInline = Array.isArray(courses) ? courses.length : 0;
    const studentCountInline = Array.isArray(students) ? students.length : 0;
    const quickStatsInfo = `${toPersianDigits(
      courseCountInline
    )} درس | ${toPersianDigits(studentCountInline)} نفر`;

    const headerTitle =
      window.customExamReportTitle ||
      `آزمون تاریخ ${dateDisplayInline} ساعت ${timeDisplayInline}`;
    let html = `
            <div class="mb-4">
                <h5 class="text-primary mb-3">${headerTitle}</h5>
        `;

    // Show course list for any number of courses (including one)
    if (courses) {
      // Prepare header breakdown badges from API (examTypeCounts and courseTypeCounts)
      // Render number badge (gray) first, then a label badge using the same color mapping as in the course rows
      const et = data.examTypeCounts || {};
      const ct = data.courseTypeCounts || {};
      const badgeParts = [];

      function labelColorFor(type) {
        // Keep the same simple mapping used elsewhere: 'کتبی' -> success (green), others -> info (turquoise)
        return type === "کتبی" ? "success" : "info";
      }

      // Exam-type counts (e.g., کتبی / الکترونیکی)
      for (const [type, cnt] of Object.entries(et)) {
        const numBadge = `<span class="badge bg-secondary">${toPersianDigits(
          cnt
        )}</span>`;
        // use exam-type color mapping (electronic -> warning, written -> dark)
        const labelBadge = `<span class="badge ${getExamBadgeClass(
          type
        )}">${type}</span>`;
        // show label first then number so they read as "label number" and appear as a single unit
        badgeParts.push(
          `<span class="badge-pair me-2">${labelBadge}${numBadge}</span>`
        );
      }

      // Course-type counts (e.g., تستی / تشریحی)
      for (const [type, cnt] of Object.entries(ct)) {
        const numBadge = `<span class="badge bg-secondary">${toPersianDigits(
          cnt
        )}</span>`;
        const labelBadge = `<span class="badge bg-${labelColorFor(
          type
        )}">${type}</span>`;
        badgeParts.push(
          `<span class="badge-pair me-2">${labelBadge}${numBadge}</span>`
        );
      }

      const badgesHtml = badgeParts.join("");

      html += `
                <h6 class="text-secondary mb-2 mt-3">لیست دروس این جلسه آزمون:</h6>
                <ul class="list-group mb-3">
                    <li class="list-group-item d-flex justify-content-between align-items-center course-item active" style="cursor: pointer;" onclick="showAllStudents()">
                        <span><strong>همه دروس</strong></span>
                        <div>
                            ${badgesHtml}
                        </div>
                    </li>
            `;

      // Insert mini pie charts area (course / exam-type / course-type)
      html += `
                    <li class="list-group-item">
                        <div id="miniPieSection" class="d-flex flex-column gap-2">
                            <h6 class="mb-2">آمار سریع جلسه <span class="text-muted">(${quickStatsInfo})</span></h6>
                            <div class="d-flex flex-row justify-content-center align-items-center gap-3">
                                <div class="text-center">
                                    <canvas id="miniPieCourse" class="mini-pie" aria-label="نمایش فراوانی دروس" role="img" title="نمایش فراوانی دروس"></canvas>
                                </div>
                                <div class="text-center">
                                    <canvas id="miniPieExamType" class="mini-pie" aria-label="نمایش نوع آزمون" role="img" title="نمایش نوع آزمون"></canvas>
                                </div>
                                <div class="text-center">
                                    <canvas id="miniPieCourseType" class="mini-pie" aria-label="نمایش نوع درس" role="img" title="نمایش نوع درس"></canvas>
                                </div>
                                <button id="examEssentialsBtn" class="btn btn-link p-0" type="button" title="ملزومات آزمون" onclick="try{ examEssentialsHandler(); }catch(e){ console.error(e); }">
                                    <img class="icon" src="/assets/app/Essentials.png" alt="ملزومات آزمون">
                                </button>
                            </div>
                        </div>
                    </li>
                `;
      courses.forEach((course) => {
        html += `
					<li class="list-group-item d-flex justify-content-between align-items-center course-item" style="cursor: pointer;" onclick="filterStudentsByCourse('${
            course.course_code
          }')">
						<span><span class="text-secondary">${course.course_code}</span> - ${
          course.course_name
        }</span>
                        <div>
                            <span class="badge bg-secondary me-2">${
                              course.student_count
                            }</span>
                            <span class="badge bg-${
                              course.course_type === "کتبی" ? "success" : "info"
                            }">${course.course_type}</span>
                        </div>
					</li>
				`;
      });
      html += `
				</ul>
			`;
    }

    html += `</div>`;

    // By default we don't render the full student list to avoid heavy initial payloads.
    // Users should select a course (or click "همه دروس") to load the names.
    html += `
            <div>
                <h5 id="studentsListHeader" class="text-primary mb-3">لیست دانشجویان</h5>
                <div id="studentsTableInfo" class="alert alert-info text-center" role="alert" style="margin-bottom:1rem;">
                    برای مشاهدهٔ اسامی، لطفاً یک درس را از لیست دروس انتخاب کنید یا روی «همه دروس» کلیک کنید.
                </div>
                <div class="table-responsive d-none" id="studentsTableWrap">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ردیف</th>
                                <th>شماره دانشجویی</th>
                                <th>نام خانوادگی</th>
                                <th>نام</th>
                                <th>کد درس</th>
                                <th>نام درس</th>
                                <th>شماره صندلی</th>
                                <th>کلاس</th>
                                <th>نوع آزمون</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody"></tbody>
                    </table>
                </div>
            </div>
        `;

    document.getElementById("reportContent").innerHTML = html;
    const reportCard = document.getElementById("reportCard");
    reportCard.style.display = "block";
    reportCard.classList.add("fade-in-up");
    setTimeout(() => scrollToReportCardWithRetry(), 100);
    // Render mini pies for this report
    try {
      renderMiniPiesFromReport(data);
    } catch (e) {
      console.error("Could not render mini pies:", e);
    }
    // clear any custom title after rendering
    try {
      delete window.customExamReportTitle;
    } catch (e) {
      window.customExamReportTitle = undefined;
    }
  } catch (error) {
    console.error("Error:", error);
    if (!error?.isLicenseError) {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "خطا در دریافت اطلاعات",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } finally {
    if (override && override.active) {
      if (
        nextEl &&
        originalLabel !== null &&
        typeof originalLabel !== "undefined"
      ) {
        nextEl.textContent = originalLabel;
      }
      window._overrideExamContext = null;
    }
  }
}

// Mini pie chart instances for the report card
let miniPieInstances = { course: null, examType: null, courseType: null };
let largePieInstance = null;

function destroyMiniPies() {
  try {
    Object.values(miniPieInstances).forEach((inst) => {
      if (inst && typeof inst.destroy === "function") inst.destroy();
    });
  } catch (e) {
    /* ignore */
  }
  miniPieInstances = { course: null, examType: null, courseType: null };
}

function renderMiniPiesFromReport(data) {
  // data: response from getNextExamReport.php
  try {
    const courses = data.courses || [];
    const examTypeCounts = data.examTypeCounts || {};
    const courseTypeCounts = data.courseTypeCounts || {};

    // Prepare course pie data (limit to top 10 to avoid clutter)
    const courseLabels = courses.map((c) => `${c.course_name}`);
    const courseValues = courses.map((c) => Number(c.student_count) || 0);

    // Exam type
    const examLabels = Object.keys(examTypeCounts);
    const examValues = examLabels.map((k) => Number(examTypeCounts[k]) || 0);

    // Course type
    const ctLabels = Object.keys(courseTypeCounts);
    const ctValues = ctLabels.map((k) => Number(courseTypeCounts[k]) || 0);

    // Colors
    const palette = [
      "#1a6fa6",
      "#ff8a65",
      "#7bd5ff",
      "#9ccc65",
      "#ffca28",
      "#7e57c2",
      "#26a69a",
      "#ef5350",
      "#90a4ae",
      "#5c6bc0",
    ];

    destroyMiniPies();

    // Course pie (DPR-aware)
    (function () {
      const miniCourseEl = document.getElementById("miniPieCourse");
      if (!miniCourseEl) return;
      const ratio = window.devicePixelRatio || 1;
      const cssW = miniCourseEl.clientWidth || 140;
      const cssH = miniCourseEl.clientHeight || 140;
      // set backing store size to CSS pixels * DPR
      miniCourseEl.width = Math.max(1, Math.floor(cssW * ratio));
      miniCourseEl.height = Math.max(1, Math.floor(cssH * ratio));
      // keep CSS size unchanged
      miniCourseEl.style.width = cssW + "px";
      miniCourseEl.style.height = cssH + "px";
      const cCtx = miniCourseEl.getContext("2d");
      try {
        cCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
      } catch (e) {
        /* ignore if not supported */
      }
      miniPieInstances.course = new Chart(cCtx, {
        type: "pie",
        data: {
          labels: courseLabels,
          datasets: [
            {
              data: courseValues,
              backgroundColor: courseLabels.map(
                (_, i) => palette[i % palette.length]
              ),
              borderColor: "rgba(255,255,255,0.6)",
              borderWidth: 4,
            },
          ],
        },
        options: {
          responsive: false,
          devicePixelRatio: ratio,
          plugins: {
            legend: { display: false },
            tooltip: {
              enabled: true,
              displayColors: false,
              title: { display: false },
              bodyFont: { family: "Vazir, sans-serif" },
              callbacks: {
                label: function (context) {
                  const v = context.raw || 0;
                  try {
                    return toPersianDigits(v);
                  } catch (e) {
                    return String(v);
                  }
                },
              },
            },
          },
        },
      });
    })();

    // Exam type pie (DPR-aware)
    (function () {
      const miniExamEl = document.getElementById("miniPieExamType");
      if (!miniExamEl) return;
      const ratio = window.devicePixelRatio || 1;
      const cssW = miniExamEl.clientWidth || 140;
      const cssH = miniExamEl.clientHeight || 140;
      miniExamEl.width = Math.max(1, Math.floor(cssW * ratio));
      miniExamEl.height = Math.max(1, Math.floor(cssH * ratio));
      miniExamEl.style.width = cssW + "px";
      miniExamEl.style.height = cssH + "px";
      const eCtx = miniExamEl.getContext("2d");
      try {
        eCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
      } catch (e) {}
      miniPieInstances.examType = new Chart(eCtx, {
        type: "pie",
        data: {
          labels: examLabels,
          datasets: [
            {
              data: examValues,
              backgroundColor: examLabels.map(
                (_, i) => palette[i % palette.length]
              ),
              borderColor: "rgba(255,255,255,0.6)",
              borderWidth: 4,
            },
          ],
        },
        options: {
          responsive: false,
          devicePixelRatio: ratio,
          plugins: {
            legend: { display: false },
            tooltip: {
              enabled: true,
              displayColors: false,
              title: { display: false },
              bodyFont: { family: "Vazir, sans-serif" },
              callbacks: {
                label: function (context) {
                  const v = context.raw || 0;
                  try {
                    return toPersianDigits(v);
                  } catch (e) {
                    return String(v);
                  }
                },
              },
            },
          },
        },
      });
    })();

    // Course type pie (DPR-aware)
    (function () {
      const miniCtEl = document.getElementById("miniPieCourseType");
      if (!miniCtEl) return;
      const ratio = window.devicePixelRatio || 1;
      const cssW = miniCtEl.clientWidth || 140;
      const cssH = miniCtEl.clientHeight || 140;
      miniCtEl.width = Math.max(1, Math.floor(cssW * ratio));
      miniCtEl.height = Math.max(1, Math.floor(cssH * ratio));
      miniCtEl.style.width = cssW + "px";
      miniCtEl.style.height = cssH + "px";
      const ctCtx = miniCtEl.getContext("2d");
      try {
        ctCtx.setTransform(ratio, 0, 0, ratio, 0, 0);
      } catch (e) {}
      miniPieInstances.courseType = new Chart(ctCtx, {
        type: "pie",
        data: {
          labels: ctLabels,
          datasets: [
            {
              data: ctValues,
              backgroundColor: ctLabels.map(
                (_, i) => palette[i % palette.length]
              ),
              borderColor: "rgba(255,255,255,0.6)",
              borderWidth: 4,
            },
          ],
        },
        options: {
          responsive: false,
          devicePixelRatio: ratio,
          plugins: {
            legend: { display: false },
            tooltip: {
              enabled: true,
              displayColors: false,
              title: { display: false },
              bodyFont: { family: "Vazir, sans-serif" },
              callbacks: {
                label: function (context) {
                  const v = context.raw || 0;
                  try {
                    return toPersianDigits(v);
                  } catch (e) {
                    return String(v);
                  }
                },
              },
            },
          },
        },
      });
    })();

    // Make canvases clickable to open large view (buttons removed)
    try {
      const miniCourseEl = document.getElementById("miniPieCourse");
      if (miniCourseEl)
        miniCourseEl.onclick = () =>
          showLargePie("فراوانی دروس", courseLabels, courseValues, palette);
    } catch (e) {
      /* ignore */
    }
    try {
      const miniExamEl = document.getElementById("miniPieExamType");
      if (miniExamEl)
        miniExamEl.onclick = () =>
          showLargePie("نوع آزمون", examLabels, examValues, palette);
    } catch (e) {
      /* ignore */
    }
    try {
      const miniCourseTypeEl = document.getElementById("miniPieCourseType");
      if (miniCourseTypeEl)
        miniCourseTypeEl.onclick = () =>
          showLargePie("نوع درس", ctLabels, ctValues, palette);
    } catch (e) {
      /* ignore */
    }

    // Add a "چاپ شماره صندلی" button next to the mini pies for printable seat list
    try {
      const miniSection = document.getElementById("miniPieSection");
      if (miniSection) {
        // Rewrap the existing mini-pie canvases so layout stays consistent.
        const container = miniSection.querySelector(".d-flex.flex-row");
        if (container) {
          const wrapper = document.createElement("div");
          wrapper.className = "d-flex align-items-center gap-2";
          while (container.firstChild)
            wrapper.appendChild(container.firstChild);
          container.appendChild(wrapper);
        }
      }
    } catch (e) {
      /* ignore */
    }
  } catch (err) {
    console.error("Error rendering mini pies:", err);
  }
}

function showLargePie(title, labels, values, palette) {
  try {
    Swal.fire({
      title: title,
      html: `<div style="width:100%;height:380px"><canvas id="largePieCanvas" style="width:100%;height:100%"></canvas></div>`,
      width: "50rem",
      showCancelButton: false,
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
      willOpen: () => {
        // placeholder
      },
      didOpen: () => {
        try {
          if (largePieInstance) largePieInstance.destroy();
        } catch (e) {}
        const ctx = document.getElementById("largePieCanvas").getContext("2d");
        largePieInstance = new Chart(ctx, {
          type: "doughnut",
          data: {
            labels: labels,
            datasets: [
              {
                data: values,
                backgroundColor: labels.map(
                  (_, i) => palette[i % palette.length]
                ),
                borderColor: "rgba(255,255,255,0.85)",
                borderWidth: 6,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "30%",
            plugins: {
              // legend removed per request (we'll use tooltips instead)
              legend: { display: false },
              tooltip: {
                enabled: true,
                displayColors: false,
                title: { display: false },
                bodyFont: { family: "Vazir, sans-serif" },
                callbacks: {
                  // remove title duplication and show a single label line with Persian numbers
                  title: function () {
                    return "";
                  },
                  label: function (context) {
                    const v = context.raw || 0;
                    const label =
                      context.label ||
                      (context.dataset && context.dataset._rawLabel) ||
                      "";
                    const valText =
                      typeof toPersianDigits === "function"
                        ? toPersianDigits(v)
                        : String(v);
                    return `${label}: ${valText}`;
                  },
                },
              },
            },
          },
        });
      },
      willClose: () => {
        try {
          if (largePieInstance) {
            largePieInstance.destroy();
            largePieInstance = null;
          }
        } catch (e) {}
      },
    });
  } catch (err) {
    console.error("Error showing large pie:", err);
  }
}

// Build and open a printable seat numbers report. Uses window.allStudents (set by showNextExamReport)
async function printSeatNumbersReport() {
  try {
    const context = window._lastExamContext || null;
    const normalizeDate = (value) =>
      toEnglishDigits(String(value || "")).replace(/-/g, "/");
    const normalizeTime = (value) => toEnglishDigits(String(value || ""));

    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (!examDate || !examTime) {
      const nextExamDateTimeText =
        document.getElementById("nextExamDateTime")?.textContent || "";
      const parts = nextExamDateTimeText.split("|").map((s) => s.trim());
      if (parts.length === 2) {
        examTime = toEnglishDigits(parts[0]);
        examDate = toEnglishDigits(parts[1]).replace(/-/g, "/");
      }
    }

    if (examDate && examTime) {
      const url = `../API/generatePDF.php?report_type=seat&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, "شماره صندلی آزمون");
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printProctorNotices() {
  try {
    // Check if there are any proctor assignments first
    const response = await guardedFetch("../API/getProctorNotifications.php", {
      cache: "no-store",
    });
    const payload = await response.json();

    if (!response.ok || !payload || payload.success !== true) {
      throw new Error(
        payload?.message || payload?.error || "خطا در دریافت اطلاعات"
      );
    }

    const proctors = Array.isArray(payload.proctors) ? payload.proctors : [];
    if (!proctors.length) {
      return Swal.fire({
        icon: "info",
        title: "اطلاعات",
        text: "هنوز هیچ ابلاغی برای مراقبین صادر نشده است.",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }

    const url = `../API/generatePDF.php?report_type=proctor_notice&_t=${new Date().getTime()}`;
    showReportModal(url, "ابلاغ مراقبین");
  } catch (e) {
    console.error(e);
    Swal.fire({
      icon: "error",
      title: "خطا",
      text: e.message || "خطا در دریافت اطلاعات",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

async function examEssentialsHandler() {
  const context = window._lastExamContext || null;
  let examDate = context?.exam_date;
  let examTime = context?.exam_time;

  // Check what types of exams exist for this session
  let hasDescriptive = false;
  let hasLocations = false;

  if (examDate && examTime) {
    const cleanDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
    const cleanTime = toEnglishDigits(String(examTime));

    try {
      const checkResp = await guardedFetch(
        `../API/getNextExamReport.php?exam_date=${encodeURIComponent(
          cleanDate
        )}&exam_time=${encodeURIComponent(cleanTime)}`,
        { cache: "no-store" }
      );
      const checkData = await checkResp.json();

      if (checkData && !checkData.error) {
        const courses = Array.isArray(checkData.courses)
          ? checkData.courses
          : [];
        // Check for descriptive (essay) exams
        hasDescriptive = courses.some(
          (c) => c.course_type && c.course_type.includes("تشریحی")
        );
        // Check for locations (written exams have locations)
        hasLocations = courses.some(
          (c) => c.exam_type === "کتبی" || !c.exam_type
        );
      }
    } catch (e) {
      console.warn("Failed to check exam types:", e);
      // Show buttons by default if check fails
      hasDescriptive = true;
      hasLocations = true;
    }
  }

  // Build dynamic button HTML
  let buttonsHtml = `
    <div style="display:flex;flex-direction:column;gap:12px;margin-top:1rem;">
      <button id="essentialsPrintSessionBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('session'); }catch(e){ console.error(e); }">
        صورتجلسه آزمون
      </button>
      
      <button id="essentialsAttendanceBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('attendance'); }catch(e){ console.error(e); }">
        فهرست حضور و غیاب
      </button>
      <button id="essentialsPrintSeatBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('seat'); }catch(e){ console.error(e); }">
        شماره‌ صندلی‌آزمون
      </button>
      <button id="essentialsSecretaryBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('secretary'); }catch(e){ console.error(e); }">
        ملزومات منشی جلسه
      </button>
      <button id="essentialsReproductionBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('reproduction'); }catch(e){ console.error(e); }">
        ملزومات اتاق تکثیر
      </button>`;

  if (hasDescriptive) {
    buttonsHtml += `
      <button id="essentialsDescriptiveBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('descriptive'); }catch(e){ console.error(e); }">
        برچسب پاکت‌های تشریحی
      </button>`;
  }

  if (hasLocations) {
    buttonsHtml += `
      <button id="essentialsLocationLabelsBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('locationLabels'); }catch(e){ console.error(e); }">
        برچسب پاکت سوالات
      </button>`;
  }

  buttonsHtml += `</div>`;

  Swal.fire({
    icon: "info",
    title: "ملزومات جلسه آزمون",
    html: buttonsHtml,
    showConfirmButton: false,
    showCancelButton: false,
    customClass: { popup: "swal2-rtl swal2-glass" },
  });
}

function startEssentialsPrint(kind) {
  // Flag to reopen the menu after the report modal closes
  window._reopenEssentialsMenu = true;
  setTimeout(() => {
    try {
      if (kind === "session") printSessionReport();
      else if (kind === "seat") printSeatNumbersReport();
      else if (kind === "secretary") printEssentialsSecretary();
      else if (kind === "proctorNotice") printProctorNotices();
      else if (kind === "reproduction") printEssentialsReproduction();
      else if (kind === "attendance") printAttendanceSheet();
      else if (kind === "descriptive") printEssentialsDescriptive();
      else if (kind === "locationLabels") printLocationLabels();
      else if (kind === "test") printEssentialsTest();
    } catch (e) {
      console.error("startEssentialsPrint error:", e);
    }
  }, 100);
}

async function printEssentialsSecretary() {
  try {
    const context = window._lastExamContext || null;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (examDate && examTime) {
      examDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
      examTime = toEnglishDigits(String(examTime));
      const url = `../API/generatePDF.php?report_type=secretary&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, "ملزومات منشی جلسه");
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printEssentialsReproduction() {
  try {
    const context = window._lastExamContext || null;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (examDate && examTime) {
      examDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
      examTime = toEnglishDigits(String(examTime));

      // Check config for report mode
      let reportType = "reproduction";
      let reportTitle = "ملزومات اتاق تکثیر";
      try {
        const cfgResp = await guardedFetch("../API/getConfig.php", {
          cache: "no-store",
        });
        if (cfgResp && cfgResp.ok) {
          const cfg = await cfgResp.json();
          if (
            String(cfg.ReproductionReportMode || "").toLowerCase() ===
            "location"
          ) {
            reportType = "location";
            reportTitle = "ملزومات اتاق تکثیر (بر اساس مکان)";
          }
        }
      } catch (e) {
        console.warn("Could not load config for reproduction report mode", e);
      }

      const url = `../API/generatePDF.php?report_type=${reportType}&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, reportTitle);
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printAttendanceSheet() {
  try {
    const context = window._lastExamContext || null;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (examDate && examTime) {
      examDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
      examTime = toEnglishDigits(String(examTime));

      const url = `../API/generatePDF.php?report_type=attendance_sheet&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, "فهرست حضور و غیاب");
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printEssentialsDescriptive() {
  try {
    const context = window._lastExamContext || null;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (examDate && examTime) {
      examDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
      examTime = toEnglishDigits(String(examTime));

      const url = `../API/generatePDF.php?report_type=descriptive&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, "برچسب پاکت‌های تشریحی");
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printLocationLabels() {
  try {
    const context = window._lastExamContext || null;
    let examDate = context?.exam_date;
    let examTime = context?.exam_time;

    if (examDate && examTime) {
      examDate = toEnglishDigits(String(examDate)).replace(/-/g, "/");
      examTime = toEnglishDigits(String(examTime));

      const url = `../API/generatePDF.php?report_type=location_labels&exam_date=${encodeURIComponent(
        examDate
      )}&exam_time=${encodeURIComponent(examTime)}&_t=${new Date().getTime()}`;
      showReportModal(url, "برچسب پاکت سوالات مکان‌ها");
    } else {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "اطلاعات آزمون یافت نشد",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  } catch (e) {
    console.error(e);
  }
}

async function printEssentialsTest() {
  try {
    Swal.fire({
      title: "در حال ساخت گزارش",
      html: "لطفاً منتظر بمانید...",
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      },
      customClass: { popup: "swal2-rtl swal2-glass" },
      showConfirmButton: false,
    });

    const { headMarkup: fontHeadMarkup } = getVazirFontMeta();
    const university =
      (document.getElementById("footerText")?.textContent || "")
        .trim()
        .replace(/^نسار\s*-\s*/, "") || "برچسب پاکت‌های تستی";

    let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>برچسب پاکت‌های تستی</title>${fontHeadMarkup}`;
    docHtml += `<style>
            @page { size: A5 landscape; margin: 4mm; }
            html, body { margin: 0; padding: 0; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 10pt; }
            .page { width: 210mm; height: 148mm; box-sizing: border-box; padding: 8mm; }
            @media print {
                .no-print { display: none !important; }
            }
        </style></head><body>`;
    docHtml += `<div class="page">`;
    docHtml += `<div style="text-align:center;font-size:16pt;font-weight:700;margin-bottom:15mm;">${university}</div>`;
    docHtml += `<div style="text-align:center;font-size:14pt;font-weight:600;margin-bottom:8mm;">برچسب پاکت‌های تستی</div>`;
    docHtml += `<div style="text-align:center;color:#666;margin-top:30mm;">محتوای گزارش به زودی اضافه خواهد شد</div>`;
    docHtml += `</div></body></html>`;

    const iframe = document.createElement("iframe");
    iframe.style.position = "fixed";
    iframe.style.left = "-10000px";
    iframe.style.top = "0";
    iframe.style.width = "210mm";
    iframe.style.height = "148mm";
    iframe.style.border = "0";
    iframe.style.visibility = "hidden";
    document.body.appendChild(iframe);

    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(docHtml);
    doc.close();

    try {
      Swal.close();
    } catch (e) {}

    const cw = iframe.contentWindow;
    let cleaned = false;
    const cleanup = () => {
      if (cleaned) return;
      cleaned = true;
      try {
        closeSwalLoadingHard();
      } catch (e) {}
      try {
        document.body.removeChild(iframe);
      } catch (e) {}
      try {
        window.removeEventListener("focus", onFocusOnce, true);
      } catch (e) {}
      try {
        reopenEssentialsMenuIfRequested();
      } catch (e) {}
    };
    const onFocusOnce = () => {
      setTimeout(cleanup, 150);
    };
    try {
      if (cw) {
        cw.onafterprint = cleanup;
        window.addEventListener("focus", onFocusOnce, true);
        try {
          safePrintIframe(iframe, cw);
        } catch (e) {
          console.error("Print invoke error:", e);
        }
        setTimeout(cleanup, 5000);
      } else {
        setTimeout(cleanup, 300);
      }
    } catch (e) {
      console.error("Print error:", e);
      setTimeout(cleanup, 300);
    }
  } catch (err) {
    console.error("Error building printable report:", err);
    Swal.fire({
      icon: "error",
      title: "خطا",
      text: "خطا در آماده‌سازی گزارش چاپ",
      confirmButtonText: "باشه",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  }
}

function scrollToReportCardWithRetry(maxRetries = 10) {
  const reportCard = document.getElementById("reportCard");
  if (
    reportCard &&
    (reportCard.offsetHeight > 0 || reportCard.style.display !== "none")
  ) {
    reportCard.scrollIntoView({ behavior: "smooth", block: "start" });
  } else if (maxRetries > 0) {
    setTimeout(() => scrollToReportCardWithRetry(maxRetries - 1), 100);
  }
}

function renderInsightCards(stats) {
  const insightContainer = document.getElementById("insightCardsContainer");
  if (!insightContainer || !stats.quickInsights) return;

  insightContainer.innerHTML = "";
  const insights = stats.quickInsights;
  const definitions = [
    {
      key: "busiestSession",
      label: "شلوغ‌ترین جلسه آزمون",
      category: "session",
      variant: "insight-busy",
      valueKey: "student_count",
      unit: "نفر",
    },
    {
      key: "quietestSession",
      label: "خلوت‌ترین جلسه آزمون",
      category: "session",
      variant: "insight-quiet",
      valueKey: "student_count",
      unit: "نفر",
    },
    {
      key: "maxCourseFrequency",
      label: "بیشترین تعداد درس در جلسه",
      category: "session",
      variant: "insight-course",
      valueKey: "course_count",
      unit: "درس",
    },
    {
      key: "maxWritten",
      label: "بیشترین تعداد کتبی",
      category: "session",
      variant: "insight-written",
      valueKey: "student_count",
      unit: "نفر",
    },
  ];

  definitions.forEach((def) => {
    const entry = insights[def.key];
    if (!entry) return;

    const rawValue = def.valueKey
      ? entry[def.valueKey]
      : entry.student_count ?? entry.count ?? entry.value ?? 0;
    const count = Number(rawValue ?? 0);
    if (!Number.isFinite(count) || count < 0) return;

    const unitText = def.unit || (def.category === "session" ? "نفر" : "");
    const displayCount = unitText
      ? `${toPersianDigits(count)} ${unitText}`
      : toPersianDigits(count);

    const rawTime = entry.exam_time || "";
    const rawDate = entry.exam_date || "";
    const line2Parts = [];
    if (rawTime) line2Parts.push(rawTime);
    if (rawDate) line2Parts.push(rawDate);
    const displayLine2 = line2Parts.length
      ? toPersianDigits(line2Parts.join(" | "))
      : "بدون تاریخ";

    let displayLabelText = def.label;
    const tieCount = Number(entry.tie_count || 0);
    if (tieCount > 1) {
      displayLabelText = `${displayLabelText} (x${tieCount})`;
    }

    const col = document.createElement("div");
    col.className = "col-md-3 mb-3";

    const card = document.createElement("div");
    card.className = `session-mini-card insight-card ${def.variant}`;
    card.style.height = "100%";
    card.style.display = "flex";
    card.style.flexDirection = "column";
    card.style.justifyContent = "center";

    card.setAttribute("data-insight-type", def.category);
    card.setAttribute("data-label", displayLabelText);
    card.setAttribute("data-label-base", def.label);
    card.setAttribute("data-display-line2", displayLine2);
    if (rawDate) card.setAttribute("data-exam-date", rawDate);
    if (rawTime) card.setAttribute("data-exam-time", rawTime);
    if (entry.course_code)
      card.setAttribute("data-course-code", entry.course_code);
    card.setAttribute("data-tie-count", tieCount);
    if (entry.matches)
      card.setAttribute("data-matches", JSON.stringify(entry.matches));

    card.innerHTML = `
      <div class="line1">${escapeHtml(displayCount)}</div>
      <div class="line2">${escapeHtml(displayLine2)}</div>
      <div class="line3">${escapeHtml(displayLabelText)}</div>
    `;

    card.addEventListener("click", () => {
      // Scroll to report card with retry for slow networks
      scrollToReportCardWithRetry();

      const type = def.category;
      const matches = entry.matches || [];

      if (type === "session" && tieCount > 1 && matches.length) {
        if (typeof openTieModal === "function")
          openTieModal(displayLabelText, def.label, matches);
      } else if (type === "session" && rawDate && rawTime) {
        if (typeof applyNextExamOverride === "function") {
          applyNextExamOverride(rawDate, rawTime, {
            customTitle: `${displayLabelText} (${displayLine2})`,
          });
        }
        if (typeof showNextExamReport === "function") showNextExamReport();
      } else if (type === "course" && entry.course_code) {
        if (typeof loadCourseReportByCode === "function")
          loadCourseReportByCode(entry.course_code, { showErrors: true });
      }
    });

    col.appendChild(card);
    insightContainer.appendChild(col);
  });
}

// =====================================================
// Push Notification Management for Admin Dashboard
// =====================================================
(function initPushNotificationAdmin() {
  const sendBtn = document.getElementById("sendPushBtn");
  const titleInput = document.getElementById("pushTitle");
  const bodyInput = document.getElementById("pushBody");
  const studentsCheckbox = document.getElementById("pushStudents");
  const proctorsCheckbox = document.getElementById("pushProctors");
  const resultDiv = document.getElementById("pushResult");

  if (
    !sendBtn ||
    !titleInput ||
    !bodyInput ||
    !studentsCheckbox ||
    !proctorsCheckbox
  ) {
    return; // Not on admin dashboard
  }

  // Fetch subscriber counts
  (async function fetchSubscriberCounts() {
    try {
      const response = await guardedFetch(
        "../API/getPushSubscribersCount.php",
        {
          cache: "no-store",
        }
      );
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          const studentLabel = document.querySelector(
            'label[for="pushStudents"]'
          );
          const proctorLabel = document.querySelector(
            'label[for="pushProctors"]'
          );

          if (studentLabel) {
            studentLabel.textContent = `دانشجویان (${toPersianDigits(
              data.students || 0
            )})`;
          }
          if (proctorLabel) {
            proctorLabel.textContent = `مراقبین (${toPersianDigits(
              data.proctors || 0
            )})`;
          }
        }
      }
    } catch (e) {
      console.warn("Failed to fetch push subscriber counts", e);
    }
  })();

  sendBtn.addEventListener("click", async () => {
    const title = titleInput.value.trim();
    const body = bodyInput.value.trim();
    const sendToStudents = studentsCheckbox.checked;
    const sendToProctors = proctorsCheckbox.checked;

    if (!title) {
      showPushResult("danger", "لطفاً عنوان پیام را وارد کنید.");
      return;
    }

    if (!body) {
      showPushResult("danger", "لطفاً متن پیام را وارد کنید.");
      return;
    }

    if (!sendToStudents && !sendToProctors) {
      showPushResult("danger", "لطفاً حداقل یک گروه گیرنده را انتخاب کنید.");
      return;
    }

    // Determine recipients text and type
    let recipientsText = "";
    let userTypes = [];
    if (sendToStudents && sendToProctors) {
      recipientsText = "همه (دانشجویان + مراقبین)";
      userTypes = ["student", "proctor"];
    } else if (sendToStudents) {
      recipientsText = "فقط دانشجویان";
      userTypes = ["student"];
    } else {
      recipientsText = "فقط مراقبین";
      userTypes = ["proctor"];
    }

    // Confirm before sending
    const confirmation = await Swal.fire({
      icon: "question",
      title: "ارسال اعلان؟",
      html: `<div style="text-align:right;direction:rtl;">
        <p><strong>عنوان:</strong> ${escapeHtml(title)}</p>
        <p><strong>متن:</strong> ${escapeHtml(body)}</p>
        <p><strong>گیرندگان:</strong> ${recipientsText}</p>
      </div>`,
      showCancelButton: true,
      confirmButtonText: "بله، ارسال کن",
      cancelButtonText: "انصراف",
      reverseButtons: true,
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary mx-2",
        cancelButton: "btn btn-cancel mx-2",
      },
      buttonsStyling: false,
    });

    if (!confirmation.isConfirmed) {
      return;
    }

    // Show loading state
    const spinner = sendBtn.querySelector(".spinner-border");
    sendBtn.disabled = true;
    if (spinner) spinner.classList.remove("d-none");

    try {
      let totalSent = 0;
      let totalFailed = 0;
      let totalExpired = 0;

      // Send to each user type
      for (const userType of userTypes) {
        const payload = {
          title: title,
          body: body,
          icon: "/pwa-icons/icon-192.png",
          tag: "admin-broadcast-" + Date.now(),
          user_type: userType,
        };

        const response = await guardedFetch("/API/push/send.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });

        const result = await response.json();

        if (result.success) {
          totalSent += result.sent || 0;
          totalFailed += result.failed || 0;
          totalExpired += result.expired || 0;
        }
      }

      showPushResult(
        "success",
        `اعلان با موفقیت ارسال شد! ارسال: ${totalSent}، ناموفق: ${totalFailed}، منقضی: ${totalExpired}`
      );
      // Clear form
      titleInput.value = "";
      bodyInput.value = "";
    } catch (error) {
      console.error("Push send error:", error);
      showPushResult("danger", "خطا در ارتباط با سرور");
    } finally {
      sendBtn.disabled = false;
      if (spinner) spinner.classList.add("d-none");
    }
  });

  function showPushResult(type, message) {
    if (!resultDiv) return;
    resultDiv.className = `alert alert-${type}`;
    resultDiv.textContent = message;
    resultDiv.classList.remove("d-none");

    // Auto-hide after 5 seconds
    setTimeout(() => {
      resultDiv.classList.add("d-none");
    }, 5000);
  }
})();

// =====================================================
// Service Worker Message Handler for Push Notifications
// =====================================================
if (navigator.serviceWorker) {
  navigator.serviceWorker.addEventListener("message", (event) => {
    // Handle notification click - show SweetAlert with notification content
    if (event.data?.type === "show-notification-alert") {
      const { title, body } = event.data;

      // Build HTML content
      const htmlContent = `<div style="text-align:right;direction:rtl;line-height:1.8;color:#fff;">${
        body || ""
      }</div>`;

      Swal.fire({
        title: title || "اعلان نسار",
        html: htmlContent,
        icon: null,
        showConfirmButton: true,
        confirmButtonText: "متوجه شدم",
        allowOutsideClick: true,
        customClass: {
          popup: "swal2-rtl swal2-glass swal2-notification-alert",
          title: "swal2-notification-title",
          htmlContainer: "swal2-notification-body",
          confirmButton: "btn btn-primary",
        },
        buttonsStyling: false,
      });
    }
  });
}

// =====================================================
// Student Photo Upload Module
// =====================================================
(function initStudentPhotoUpload() {
  const uploadBtn = document.getElementById("upload");
  if (!uploadBtn) return;

  uploadBtn.addEventListener("click", openPhotoUploadModal);

  async function getStudentsWithoutPhoto() {
    try {
      const response = await guardedFetch(
        "../API/getStudentsWithoutPhoto.php",
        {
          cache: "no-store",
        }
      );
      if (response.ok) {
        return await response.json();
      }
    } catch (e) {
      console.warn("Failed to fetch students without photo", e);
    }
    return null;
  }

  async function openPhotoUploadModal() {
    // First fetch current status
    const status = await getStudentsWithoutPhoto();
    const withoutPhoto = status?.withoutPhoto ?? "---";
    const totalStudents = status?.totalStudents ?? "---";

    const modalHtml = `
      <div class="photo-upload-container" style="text-align:right;direction:rtl;">
        <div class="photo-upload-stats" style="display:flex;gap:20px;margin-bottom:20px;justify-content:center;">
          <div class="stat-box-mini" style="background:rgba(255,255,255,0.1);padding:15px 25px;border-radius:12px;text-align:center;">
            <div style="font-size:24px;font-weight:bold;color:#4ade80;">${toPersianDigits(
              totalStudents
            )}</div>
            <div style="font-size:12px;color:#94a3b8;">کل دانشجویان</div>
          </div>
          <div class="stat-box-mini" id="withoutPhotoStat" style="background:rgba(255,255,255,0.1);padding:15px 25px;border-radius:12px;text-align:center;cursor:pointer;" title="کلیک برای مشاهده لیست">
            <div style="font-size:24px;font-weight:bold;color:#f87171;" id="withoutPhotoCount">${toPersianDigits(
              withoutPhoto
            )}</div>
            <div style="font-size:12px;color:#94a3b8;">بدون عکس</div>
          </div>
        </div>
        
        <div class="photo-upload-dropzone" id="photoDropzone" style="border:2px dashed #475569;border-radius:12px;padding:40px 20px;text-align:center;cursor:pointer;transition:all 0.3s;margin-bottom:20px;">
          <div style="font-size:48px;margin-bottom:10px;">📸</div>
          <p style="margin:0;color:#94a3b8;">فایل‌های JPG را اینجا رها کنید یا کلیک کنید</p>
          <p style="margin:5px 0 0;font-size:12px;color:#64748b;">نام فایل باید ۹ رقم انگلیسی باشد (شماره دانشجویی)</p>
          <input type="file" id="photoFileInput" multiple accept=".jpg,.jpeg" style="display:none;">
        </div>
        
        <div id="uploadProgressSection" style="display:none;">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span id="uploadProgressText">در حال آپلود...</span>
            <span id="uploadProgressPercent">۰٪</span>
          </div>
          <div style="background:#1e293b;border-radius:8px;height:12px;overflow:hidden;">
            <div id="uploadProgressBar" style="background:linear-gradient(90deg,#3b82f6,#60a5fa);height:100%;width:0%;transition:width 0.3s;"></div>
          </div>
          <div style="margin-top:8px;text-align:center;">
            <span id="uploadCountText">۰ از ۰</span>
          </div>
        </div>
        
        <div id="uploadResultSection" style="display:none;margin-top:20px;padding:15px;background:rgba(74,222,128,0.1);border-radius:12px;border:1px solid rgba(74,222,128,0.3);">
          <div style="display:flex;justify-content:space-around;text-align:center;">
            <div>
              <div style="font-size:20px;font-weight:bold;color:#4ade80;" id="uploadedCount">۰</div>
              <div style="font-size:12px;color:#94a3b8;">آپلود شده</div>
            </div>
            <div>
              <div style="font-size:20px;font-weight:bold;color:#f87171;" id="failedCount">۰</div>
              <div style="font-size:12px;color:#94a3b8;">ناموفق</div>
            </div>
          </div>
        </div>
      </div>
    `;

    const result = await Swal.fire({
      title: "آپلود عکس دانشجویان",
      html: modalHtml,
      showConfirmButton: false,
      showCloseButton: true,
      width: "500px",
      customClass: {
        popup: "swal2-rtl swal2-glass",
      },
      didOpen: (popup) => {
        const dropzone = popup.querySelector("#photoDropzone");
        const fileInput = popup.querySelector("#photoFileInput");
        const withoutPhotoStat = popup.querySelector("#withoutPhotoStat");

        // Click on dropzone opens file picker
        dropzone.addEventListener("click", () => fileInput.click());

        // Drag and drop
        dropzone.addEventListener("dragover", (e) => {
          e.preventDefault();
          dropzone.style.borderColor = "#3b82f6";
          dropzone.style.background = "rgba(59,130,246,0.1)";
        });

        dropzone.addEventListener("dragleave", (e) => {
          e.preventDefault();
          dropzone.style.borderColor = "#475569";
          dropzone.style.background = "transparent";
        });

        dropzone.addEventListener("drop", (e) => {
          e.preventDefault();
          dropzone.style.borderColor = "#475569";
          dropzone.style.background = "transparent";
          const files = e.dataTransfer.files;
          if (files.length > 0) {
            handleFiles(files, popup);
          }
        });

        // File input change
        fileInput.addEventListener("change", () => {
          if (fileInput.files.length > 0) {
            handleFiles(fileInput.files, popup);
          }
        });

        // Click on without photo stat to show list
        if (withoutPhotoStat) {
          withoutPhotoStat.addEventListener("click", () => {
            showStudentsWithoutPhotoList();
          });
        }
      },
    });
  }

  async function handleFiles(files, popup) {
    const progressSection = popup.querySelector("#uploadProgressSection");
    const progressBar = popup.querySelector("#uploadProgressBar");
    const progressPercent = popup.querySelector("#uploadProgressPercent");
    const progressText = popup.querySelector("#uploadProgressText");
    const uploadCountText = popup.querySelector("#uploadCountText");
    const resultSection = popup.querySelector("#uploadResultSection");
    const uploadedCountEl = popup.querySelector("#uploadedCount");
    const failedCountEl = popup.querySelector("#failedCount");
    const dropzone = popup.querySelector("#photoDropzone");
    const withoutPhotoCountEl = popup.querySelector("#withoutPhotoCount");

    // Filter valid files
    const validFiles = [];
    const invalidFiles = [];

    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      const ext = file.name.split(".").pop().toLowerCase();
      const nameWithoutExt = file.name.replace(/\.[^/.]+$/, "");

      if (
        (ext === "jpg" || ext === "jpeg") &&
        /^[0-9]{9}$/.test(nameWithoutExt)
      ) {
        validFiles.push(file);
      } else {
        invalidFiles.push(file.name);
      }
    }

    // Store ignored count for final report
    const ignoredCount = invalidFiles.length;

    if (validFiles.length === 0) {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: "هیچ فایل معتبری یافت نشد. فایل‌ها باید JPG باشند و نام آن‌ها ۹ رقم انگلیسی باشد.",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
      return;
    }

    // Continue without asking - invalid files are silently ignored

    // Hide dropzone and show progress
    dropzone.style.display = "none";
    progressSection.style.display = "block";
    resultSection.style.display = "none";

    const totalFiles = validFiles.length;
    let uploadedCount = 0;
    let failedCount = 0;
    const batchSize = 10; // Upload 10 files at a time

    progressText.textContent = "در حال آپلود...";

    for (let i = 0; i < totalFiles; i += batchSize) {
      const batch = validFiles.slice(i, Math.min(i + batchSize, totalFiles));
      const formData = new FormData();

      batch.forEach((file) => {
        formData.append("photos[]", file);
      });

      try {
        const response = await guardedFetch("../API/uploadStudentPhotos.php", {
          method: "POST",
          body: formData,
        });

        const result = await response.json();
        if (result.success) {
          uploadedCount += result.uploaded || 0;
          failedCount += result.failed || 0;
        } else {
          failedCount += batch.length;
        }
      } catch (e) {
        console.error("Upload batch error", e);
        failedCount += batch.length;
      }

      // Update progress
      const processed = Math.min(i + batchSize, totalFiles);
      const percent = Math.round((processed / totalFiles) * 100);
      progressBar.style.width = percent + "%";
      progressPercent.textContent = toPersianDigits(percent) + "٪";
      uploadCountText.textContent =
        toPersianDigits(processed) + " از " + toPersianDigits(totalFiles);

      // Update without photo count in real-time
      try {
        const status = await getStudentsWithoutPhoto();
        if (status && withoutPhotoCountEl) {
          withoutPhotoCountEl.textContent = toPersianDigits(
            status.withoutPhoto
          );
        }
      } catch (e) {}
    }

    // Show results
    progressText.textContent = "آپلود کامل شد";
    resultSection.style.display = "block";
    uploadedCountEl.textContent = toPersianDigits(uploadedCount);
    failedCountEl.textContent = toPersianDigits(failedCount);

    // Show ignored count if any
    if (ignoredCount > 0) {
      const ignoredInfo = document.createElement("div");
      ignoredInfo.style.cssText =
        "margin-top:10px;text-align:center;font-size:12px;color:#94a3b8;";
      ignoredInfo.textContent = `${toPersianDigits(
        ignoredCount
      )} فایل نامعتبر نادیده گرفته شد`;
      resultSection.appendChild(ignoredInfo);
    }

    // Final update of without photo count
    try {
      const status = await getStudentsWithoutPhoto();
      if (status && withoutPhotoCountEl) {
        withoutPhotoCountEl.textContent = toPersianDigits(status.withoutPhoto);

        // If there are still students without photo, make the stat clickable and highlighted
        if (status.withoutPhoto > 0) {
          const withoutPhotoStat = popup.querySelector("#withoutPhotoStat");
          if (withoutPhotoStat) {
            withoutPhotoStat.style.background = "rgba(248,113,113,0.2)";
            withoutPhotoStat.style.border = "1px solid rgba(248,113,113,0.5)";
          }
        }
      }
    } catch (e) {}
  }

  async function showStudentsWithoutPhotoList() {
    // Show loading
    Swal.fire({
      title: "در حال بارگذاری...",
      html: "لطفاً صبر کنید",
      showConfirmButton: false,
      allowOutsideClick: false,
      customClass: { popup: "swal2-rtl swal2-glass" },
      didOpen: () => Swal.showLoading(),
    });

    try {
      const response = await guardedFetch(
        "../API/getStudentsWithoutPhoto.php?full=true",
        {
          cache: "no-store",
        }
      );
      const data = await response.json();

      if (!data.success) {
        throw new Error(data.error || "خطا در دریافت اطلاعات");
      }

      if (data.withoutPhoto === 0) {
        Swal.fire({
          icon: "success",
          title: "همه دانشجویان عکس دارند",
          text: "هیچ دانشجویی بدون عکس وجود ندارد.",
          confirmButtonText: "عالی!",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        });
        return;
      }

      // Build table
      let tableHtml = `
        <style>.no-photo-list::-webkit-scrollbar{display:none;}</style>
        <div class="no-photo-list" style="max-height:400px;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;direction:rtl;">
          <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead style="position:sticky;top:0;background:#1e293b;">
              <tr>
                <th style="padding:10px;text-align:right;border-bottom:1px solid #475569;">#</th>
                <th style="padding:10px;text-align:right;border-bottom:1px solid #475569;">شماره دانشجویی</th>
                <th style="padding:10px;text-align:right;border-bottom:1px solid #475569;">نام و نام خانوادگی</th>
              </tr>
            </thead>
            <tbody>
      `;

      data.students.forEach((student, index) => {
        tableHtml += `
          <tr style="border-bottom:1px solid #334155;">
            <td style="padding:8px;text-align:right;">${toPersianDigits(
              index + 1
            )}</td>
            <td style="padding:8px;text-align:right;font-family:monospace;">${escapeHtml(
              student.student_id
            )}</td>
            <td style="padding:8px;text-align:right;">${escapeHtml(
              student.first_name
            )} ${escapeHtml(student.last_name)}</td>
          </tr>
        `;
      });

      tableHtml += `</tbody></table></div>`;

      Swal.fire({
        title: `دانشجویان بدون عکس (${toPersianDigits(data.withoutPhoto)} نفر)`,
        html: tableHtml,
        width: "600px",
        showConfirmButton: true,
        confirmButtonText: "بستن",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
        didClose: () => {
          // Reopen the upload modal so user can continue uploading
          openPhotoUploadModal();
        },
      });
    } catch (e) {
      console.error("Failed to load students without photo", e);
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: e.message || "خطا در دریافت لیست دانشجویان",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  }
})();

// =====================================================
// Photo Update Requests Notification Module
// =====================================================
(function initPhotoUpdateRequests() {
  const photoRequestsBtn = document.getElementById("photoRequestsBtn");
  const photoRequestsBadge = document.getElementById("photoRequestsBadge");

  if (!photoRequestsBtn || !photoRequestsBadge) return;

  let pendingRequests = [];

  // Fetch pending requests count
  async function fetchPendingRequests() {
    try {
      const response = await guardedFetch("../API/getPhotoUpdateRequests.php", {
        cache: "no-store",
      });
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          pendingRequests = data.requests || [];
          updateBadge(data.count || 0);
        }
      }
    } catch (e) {
      console.warn("Failed to fetch photo update requests", e);
    }
  }

  function updateBadge(count) {
    if (count > 0) {
      photoRequestsBadge.textContent = toPersianDigits(count);
      photoRequestsBadge.style.display = "block";
    } else {
      photoRequestsBadge.style.display = "none";
    }
  }

  // Initial fetch and periodic refresh
  fetchPendingRequests();
  setInterval(fetchPendingRequests, 30000); // Refresh every 30 seconds

  // Click handler
  photoRequestsBtn.addEventListener("click", async () => {
    if (pendingRequests.length === 0) {
      Swal.fire({
        icon: "info",
        title: "بدون درخواست",
        text: "هیچ درخواست تغییر عکسی در انتظار بررسی نیست.",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
      return;
    }

    // Show list of pending requests
    showPhotoRequestsList();
  });

  async function showPhotoRequestsList() {
    // Refresh the list first
    await fetchPendingRequests();

    if (pendingRequests.length === 0) {
      Swal.fire({
        icon: "success",
        title: "همه درخواست‌ها بررسی شد",
        text: "هیچ درخواستی در انتظار نیست.",
        confirmButtonText: "عالی!",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
      return;
    }

    let listHtml = `
      <style>.photo-req-list::-webkit-scrollbar{display:none;}</style>
      <div class="photo-req-list" style="max-height:400px;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;direction:rtl;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <thead style="position:sticky;top:0;background:#1e293b;">
            <tr>
              <th style="padding:10px;text-align:right;border-bottom:1px solid #475569;">دانشجو</th>
              <th style="padding:10px;text-align:right;border-bottom:1px solid #475569;">تاریخ</th>
              <th style="padding:10px;text-align:center;border-bottom:1px solid #475569;">عملیات</th>
            </tr>
          </thead>
          <tbody>
    `;

    pendingRequests.forEach((req) => {
      listHtml += `
        <tr style="border-bottom:1px solid #334155;" data-request-id="${
          req.id
        }">
          <td style="padding:8px;text-align:right;">
            <div>${escapeHtml(req.first_name)} ${escapeHtml(
        req.last_name
      )}</div>
            <div style="font-size:11px;color:#94a3b8;font-family:monospace;">${escapeHtml(
              req.student_id
            )}</div>
          </td>
          <td style="padding:8px;text-align:right;font-size:12px;">${escapeHtml(
            req.created_at_formatted
          )}</td>
          <td style="padding:8px;text-align:center;">
            <button class="btn btn-sm btn-primary review-photo-btn" data-request-id="${
              req.id
            }" style="padding:4px 12px;font-size:12px;">بررسی</button>
          </td>
        </tr>
      `;
    });

    listHtml += `</tbody></table></div>`;

    const result = await Swal.fire({
      title: `درخواست‌های تغییر عکس (${toPersianDigits(
        pendingRequests.length
      )})`,
      html: listHtml,
      width: "600px",
      showConfirmButton: true,
      confirmButtonText: "بستن",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
      didOpen: (popup) => {
        const reviewBtns = popup.querySelectorAll(".review-photo-btn");
        reviewBtns.forEach((btn) => {
          btn.addEventListener("click", async (e) => {
            e.preventDefault();
            const requestId = parseInt(btn.getAttribute("data-request-id"), 10);
            const request = pendingRequests.find((r) => r.id === requestId);
            if (request) {
              Swal.close();
              await showPhotoReviewModal(request);
            }
          });
        });
      },
    });
  }

  async function showPhotoReviewModal(request) {
    const currentPhotoHtml = request.has_current_photo
      ? `<img src="${escapeHtml(
          request.current_photo_url
        )}?t=${Date.now()}" style="max-width:180px;max-height:220px;border-radius:8px;border:2px solid #475569;" onerror="this.parentElement.innerHTML='<div style=\\'padding:40px;color:#94a3b8;\\'>خطا در بارگذاری</div>'">`
      : `<div style="padding:40px 20px;color:#f87171;background:rgba(248,113,113,0.1);border-radius:8px;border:1px dashed #f87171;">فاقد عکس در آرشیو</div>`;

    const modalHtml = `
      <div style="text-align:right;direction:rtl;">
        <div style="margin-bottom:15px;padding:10px;background:rgba(255,255,255,0.05);border-radius:8px;">
          <strong>${escapeHtml(request.first_name)} ${escapeHtml(
      request.last_name
    )}</strong>
          <span style="margin-right:10px;font-family:monospace;color:#94a3b8;">${escapeHtml(
            request.student_id
          )}</span>
        </div>
        
        <div style="display:flex;gap:20px;justify-content:center;margin-bottom:20px;">
          <div style="text-align:center;">
            <div style="font-size:12px;color:#94a3b8;margin-bottom:8px;">عکس فعلی</div>
            ${currentPhotoHtml}
          </div>
          <div style="text-align:center;">
            <div style="font-size:12px;color:#94a3b8;margin-bottom:8px;">عکس جدید</div>
            <img src="${escapeHtml(
              request.new_photo_url
            )}?t=${Date.now()}" style="max-width:180px;max-height:220px;border-radius:8px;border:2px solid #4ade80;" onerror="this.parentElement.innerHTML='<div style=\\'padding:40px;color:#94a3b8;\\'>خطا در بارگذاری</div>'">
          </div>
        </div>
        
        <div style="margin-bottom:15px;padding:10px;background:rgba(251,191,36,0.1);border-radius:8px;border:1px solid rgba(251,191,36,0.3);">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" id="faceVerifiedCheckbox" style="width:18px;height:18px;">
            <span style="color:#fbbf24;">چهره دانشجو به صورت حضوری رؤیت شد</span>
          </label>
        </div>
      </div>
    `;

    const result = await Swal.fire({
      title: "بررسی درخواست تغییر عکس",
      html: modalHtml,
      width: "650px",
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: "✓ تایید و جایگزینی",
      denyButtonText: "✗ رد درخواست",
      cancelButtonText: "انصراف",
      reverseButtons: true,
      customClass: {
        popup: "swal2-rtl swal2-glass",
        actions: "swal2-actions-inline",
        confirmButton: "btn btn-success mx-1",
        denyButton: "btn btn-cancel mx-1",
        cancelButton: "btn btn-cancel mx-1",
      },
      buttonsStyling: false,
      didOpen: (popup) => {
        const confirmBtn = Swal.getConfirmButton();
        const checkbox = popup.querySelector("#faceVerifiedCheckbox");

        // Initially disable confirm button
        confirmBtn.disabled = true;
        confirmBtn.style.opacity = "0.5";

        checkbox.addEventListener("change", () => {
          confirmBtn.disabled = !checkbox.checked;
          confirmBtn.style.opacity = checkbox.checked ? "1" : "0.5";
        });
      },
      preConfirm: () => {
        const checkbox = Swal.getPopup().querySelector("#faceVerifiedCheckbox");
        if (!checkbox.checked) {
          Swal.showValidationMessage("ابتدا تیک رؤیت حضوری را بزنید");
          return false;
        }
        return true;
      },
    });

    if (result.isConfirmed) {
      await processPhotoRequest(request.id, "approve");
    } else if (result.isDenied) {
      await processPhotoRequest(request.id, "reject");
    } else {
      // User cancelled, go back to list
      showPhotoRequestsList();
    }
  }

  async function processPhotoRequest(requestId, action) {
    Swal.fire({
      title: "در حال پردازش...",
      html: "لطفاً صبر کنید",
      allowOutsideClick: false,
      showConfirmButton: false,
      customClass: { popup: "swal2-rtl swal2-glass" },
      didOpen: () => Swal.showLoading(),
    });

    try {
      const csrfToken = document.querySelector(
        'meta[name="csrf-token"]'
      )?.content;
      const response = await guardedFetch(
        "../API/reviewPhotoUpdateRequest.php",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrfToken || "",
          },
          body: JSON.stringify({ request_id: requestId, action: action }),
        }
      );

      const data = await response.json();

      if (data.success) {
        await fetchPendingRequests(); // Refresh the list

        Swal.fire({
          icon: "success",
          title: action === "approve" ? "تایید شد" : "رد شد",
          text: data.message,
          confirmButtonText: "ادامه",
          customClass: {
            popup: "swal2-rtl swal2-glass",
            confirmButton: "btn btn-primary",
          },
        }).then(() => {
          if (pendingRequests.length > 0) {
            showPhotoRequestsList();
          }
        });
      } else {
        throw new Error(data.error || "خطا در پردازش درخواست");
      }
    } catch (e) {
      Swal.fire({
        icon: "error",
        title: "خطا",
        text: e.message || "خطا در پردازش درخواست",
        confirmButtonText: "باشه",
        customClass: {
          popup: "swal2-rtl swal2-glass",
          confirmButton: "btn btn-primary",
        },
      });
    }
  }
})();
