document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("coworkerForm");
  const nationalIdInput = document.getElementById("coworkerNationalId");
  const phoneInput = document.getElementById("coworkerPhone");
  const submitBtn = document.getElementById("coworkerSubmitBtn");
  const submitSpinner = submitBtn?.querySelector(".spinner-border");
  const submitText = submitBtn?.querySelector(".text");
  const dashboard = document.getElementById("coworkerDashboard");
  const cardsContainer = document.getElementById("assignmentCards");
  const emptyState = document.getElementById("coworkerEmptyState");
  const profileCard = document.getElementById("coworkerProfile");
  const profileName = document.getElementById("coworkerName");
  const profileMeta = document.getElementById("coworkerMeta");
  const logoutBtn = document.getElementById("coworkerLogoutBtn");
  const statsBtn = document.getElementById("statsBtn");
  const footerClock = document.getElementById("coworkerClock");
  const footerText = document.getElementById("coworkerFooterText");
  const loginCard = document.getElementById("coworkerLoginCard");

  const STORAGE_KEY = "coworkerSession";
  const API_ENDPOINT = "../API/getCoworkerSessions.php";

  let currentSessions = [];
  let currentStats = null;
  let currentCoworker = null;
  let currentCredentials = null;

  const persianDigits = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];

  const escapeHtml = (value) => {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
  };

  const toEnglishDigits = (value) => {
    const map = {
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
    return String(value ?? "")
      .split("")
      .map((char) => map[char] ?? char)
      .join("");
  };

  const toPersianDigits = (value) => {
    return String(value ?? "").replace(/\d/g, (d) => persianDigits[d] ?? d);
  };

  const getCsrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : null;
  };

  const setFormLoading = (isLoading, loadingText) => {
    if (!submitBtn || !submitSpinner || !submitText) return;
    if (isLoading) {
      submitSpinner.classList.remove("d-none");
      submitText.textContent = loadingText || "در حال بررسی...";
      submitBtn.disabled = true;
    } else {
      submitSpinner.classList.add("d-none");
      submitText.textContent = "ورود به پنل";
      submitBtn.disabled = false;
    }
  };

  const encryptData = (data) => {
    try {
      const json = JSON.stringify(data);
      return btoa(unescape(encodeURIComponent(json)));
    } catch (error) {
      console.error("encryptData failed", error);
      return null;
    }
  };

  const guardedFetch = async (url, options = {}) => {
    const opts = { ...options };
    opts.headers = opts.headers || {};
    if ((opts.method || "GET").toUpperCase() !== "GET") {
      const csrf = getCsrfToken();
      if (csrf) {
        opts.headers["X-CSRF-Token"] = csrf;
      }
    }
    const response = await fetch(url, opts);
    if (response.status === 403) {
      let message = "دسترسی محدود شده است.";
      try {
        const payload = await response.clone().json();
        if (payload?.message) message = payload.message;
      } catch (_) {
        /* ignore */
      }
      await Swal.fire({
        icon: "error",
        title: "خطای دسترسی",
        text: message,
        customClass: { popup: "swal2-rtl swal2-glass" },
      });
      throw new Error("forbidden");
    }
    return response;
  };

  const fetchCoworkerSessions = async (nationalId, phone) => {
    const encrypted = encryptData({ national_id: nationalId, phone });
    if (!encrypted) {
      throw new Error("خطا در آماده‌سازی اطلاعات");
    }
    const body = new FormData();
    body.append("encrypted_data", encrypted);
    const response = await guardedFetch(API_ENDPOINT, { method: "POST", body });
    const payload = await response.json();
    if (!response.ok || payload.success !== true) {
      const error =
        payload?.message || payload?.error || "خطا در دریافت اطلاعات";
      throw new Error(error);
    }
    return payload;
  };

  const saveSession = (payload) => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch (error) {
      console.warn("Failed to persist session", error);
    }
  };

  const clearSession = () => {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (error) {
      console.warn("Failed to clear session", error);
    }
  };

  const updateClock = () => {
    if (!footerClock) return;
    const now = new Date();
    footerClock.textContent = now.toLocaleTimeString("fa-IR", {
      hour: "2-digit",
      minute: "2-digit",
    });
  };
  updateClock();
  setInterval(updateClock, 60000);

  const loadFooterInfo = async () => {
    try {
      const response = await guardedFetch("../API/getConfig.php", {
        cache: "no-store",
      });
      if (response.ok) {
        const data = await response.json();
        if (data?.University && footerText) {
          footerText.textContent = `نسار - ${data.University}`;
        }
      }
    } catch (error) {
      console.debug("Footer config load failed", error);
    }
  };
  loadFooterInfo();

  const formatMetaLine = (session) => {
    const examDate = toPersianDigits(session.exam_date ?? "----/--/--");
    const examTime = session.exam_time
      ? `ساعت ${toPersianDigits(session.exam_time)}`
      : "ساعت --:--";
    return `${examTime} | ${examDate}`;
  };

  const formatBadgeLine = (session) => {
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
  };

  const buildSessionCard = (session, index) => {
    const statusClass = session.status === "past" ? "past" : "upcoming";
    const badgeLine = formatBadgeLine(session);
    const countdown =
      session.minutes_until != null
        ? `<div class="exam-countdown">حدود ${toPersianDigits(
            Math.max(session.minutes_until, 0)
          )} دقیقه تا شروع</div>`
        : "";
    return `
          <div class="exam-card coworker-session-card ${statusClass}" data-index="${index}" tabindex="0">
                <div class="exam-title text-center">${toPersianDigits(
                  session.exam_time
                )} | ${toPersianDigits(session.exam_date)}</div>
                ${
                  badgeLine
                    ? `<div class="badge mt-2">${escapeHtml(badgeLine)}</div>`
                    : ""
                }
                ${countdown}
            </div>
        `;
  };

  const showSessionModal = (session) => {
    if (!session) return;
    const badgeLine =
      formatBadgeLine(session) || "اطلاعات تکمیلی برای این جلسه ثبت نشده است.";
    const countdown =
      session.minutes_until != null && session.minutes_until > 0
        ? `<div style="margin-top:0.8rem;color:#16a34a;">حدود ${toPersianDigits(
            session.minutes_until
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
                    <div style="margin-top:0.8rem;">${escapeHtml(
                      badgeLine
                    )}</div>
                    ${countdown}
                </div>
            `,
      confirmButtonText: "بستن",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  };

  const attachCardHandlers = () => {
    if (!cardsContainer) return;
    cardsContainer.querySelectorAll(".exam-card").forEach((card) => {
      if (card.dataset.bound === "1") return;
      card.dataset.bound = "1";
      // Removed click and keypress handlers for SweetAlert modal
    });
  };

  const showStatsModal = () => {
    if (!currentStats) return;
    const rows = [
      { label: "مجموع جلسات", value: currentStats.total_sessions },
      { label: "روزهای حضور", value: currentStats.unique_days },
      { label: "جلسات باقی‌مانده", value: currentStats.upcoming_sessions },
      { label: "جلسات برگزارشده", value: currentStats.completed_sessions },
    ];
    const html = rows
      .map(
        (row) => `
            <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid rgba(148,163,184,0.25);">
                <span>${row.label}</span>
                <strong>${toPersianDigits(row.value ?? 0)}</strong>
            </div>
        `
      )
      .join("");

    let nextSessionHtml = "";
    if (currentStats.next_session) {
      const next = currentStats.next_session;
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
      html: `<div style="text-align:right;line-height:1.9;">${html}${nextSessionHtml}</div>`,
      confirmButtonText: "بستن",
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  };

  const renderDashboard = (payload) => {
    currentSessions = Array.isArray(payload.sessions) ? payload.sessions : [];
    currentStats = payload.stats || null;
    currentCoworker = payload.coworker || null;

    if (profileName && currentCoworker) {
      const fullName =
        `${currentCoworker.first_name || ""} ${
          currentCoworker.last_name || ""
        }`.trim() || "همکار نسار";
      profileName.textContent = fullName;
    }
    if (profileMeta) {
      if (currentStats) {
        profileMeta.textContent = `${toPersianDigits(
          currentStats.total_sessions || 0
        )} جلسه · ${toPersianDigits(
          currentStats.upcoming_sessions || 0
        )} باقی مانده`;
      } else {
        profileMeta.textContent = "در انتظار داده";
      }
    }

    if (!cardsContainer) {
      emptyState?.classList.add("d-none");
    } else if (!currentSessions.length) {
      cardsContainer.innerHTML = "";
      emptyState?.classList.remove("d-none");
    } else {
      emptyState?.classList.add("d-none");
      const markup = currentSessions
        .map((session, index) => buildSessionCard(session, index))
        .join("");
      cardsContainer.innerHTML = markup;
      attachCardHandlers();
    }

    dashboard?.classList.remove("d-none");
    profileCard?.classList.remove("d-none");

    if (currentStats && currentStats.total_sessions) {
      showStatsModal();
    }
  };

  const handleLoginSuccess = (payload, credentials) => {
    currentCredentials = credentials;
    saveSession({
      national_id: credentials.nationalId,
      phone: credentials.phone,
      stored_at: Date.now(),
    });
    renderDashboard(payload);
    if (dashboard) {
      const target = Math.max(0, dashboard.offsetTop - 40);
      window.scrollTo({ top: target, behavior: "smooth" });
    }
  };

  const handleError = (error) => {
    const message = error?.message || "خطای ناشناخته رخ داد.";
    Swal.fire({
      icon: "error",
      title: "خطا",
      text: message,
      customClass: {
        popup: "swal2-rtl swal2-glass",
        confirmButton: "btn btn-primary",
      },
    });
  };

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const nationalId = toEnglishDigits(nationalIdInput.value)
      .replace(/[^0-9]/g, "")
      .trim();
    const phone = toEnglishDigits(phoneInput.value)
      .replace(/[^0-9]/g, "")
      .trim();

    if (
      nationalId.length !== 10 ||
      (phone.length !== 11 && phone.length !== 10)
    ) {
      handleError(new Error("کد ملی یا شماره همراه صحیح نیست."));
      return;
    }

    setFormLoading(true, "در حال دریافت جلسات...");
    try {
      const payload = await fetchCoworkerSessions(nationalId, phone);
      handleLoginSuccess(payload, { nationalId, phone });
    } catch (error) {
      handleError(error);
    } finally {
      setFormLoading(false);
    }
  });

  logoutBtn?.addEventListener("click", () => {
    clearSession();
    currentCredentials = null;
    currentSessions = [];
    currentStats = null;
    dashboard?.classList.add("d-none");
    cardsContainer.innerHTML = "";
    emptyState?.classList.add("d-none");
    profileCard?.classList.add("d-none");
    nationalIdInput.value = "";
    phoneInput.value = "";
  });

  statsBtn?.addEventListener("click", (event) => {
    event.preventDefault();
    showStatsModal();
  });

  const autoLogin = async () => {
    let stored = null;
    try {
      stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
    } catch (error) {
      console.warn("Invalid stored session", error);
    }
    if (!stored?.national_id || !stored?.phone) return;

    setFormLoading(true, "در حال بازیابی نشست قبلی...");
    try {
      const payload = await fetchCoworkerSessions(
        stored.national_id,
        stored.phone
      );
      handleLoginSuccess(payload, {
        nationalId: stored.national_id,
        phone: stored.phone,
      });
    } catch (error) {
      clearSession();
      console.warn("Auto login failed", error);
    } finally {
      setFormLoading(false);
    }
  };

  autoLogin();
});
