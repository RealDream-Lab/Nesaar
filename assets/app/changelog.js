/**
 * Changelog data for version update notifications
 * Each version should have a changes array with the list of changes
 */
window.CHANGELOG = {
  "1.3.5": {
    date: "۱۴۰۴/۱۰/۰۵",
    changes: [
      "جداسازی صفحه دانشجویان چندآزمونی در گزارشات (وقتی صرفه‌جویی کاغذ غیرفعال است)",
      "بهبود سیستم ارسال خودکار نوتیفیکیشن - جلوگیری از ارسال تکراری",
      "بهبود تقویم جلسات: نمایش کم‌رنگ آزمون‌های گذشته",
      "اضافه شدن آمار ساختمان‌ها به برچسب پاکت تستی",
      "بهبود تنظیمات گزارش شماره صندلی",
      "اصلاح نمایش نام درس در فهرست حضور و غیاب",
      "اضافه / حذف شدن دروس دانشجویان چند آزمونی به برچسب پاکت سوالات",
    ],
  },

  "1.3.0": {
    date: "۱۴۰۴/۱۰/۰۴",
    changes: [
      "اضافه شدن مودال نمایش تغییرات نسخه در اولین ورود",
      "اضافه شدن راهنمای کوتاه (تولتیپ) برای گزینه‌های تنظیمات",
      "جداسازی صفحه دانشجویان چندآزمونی در گزارشات (وقتی صرفه‌جویی کاغذ غیرفعال است)",
      "بهبود سیستم ارسال خودکار نوتیفیکیشن - جلوگیری از ارسال تکراری",
      "بهبود تقویم جلسات: نمایش کم‌رنگ آزمون‌های گذشته",
      "اضافه شدن آمار ساختمان‌ها به برچسب پاکت تستی",
      "بهبود تنظیمات گزارش شماره صندلی",
      "اصلاح نمایش نام درس در فهرست حضور و غیاب",
      "اضافه / حذف شدن دروس دانشجویان چند آزمونی در برچسب پاکت سوالات",
    ],
  },
  "1.2.5": {
    date: "۱۴۰۴/۱۰/۰۵",
    changes: [
      "بهبود تقویم جلسات: نمایش کم‌رنگ آزمون‌های گذشته",
      "اضافه شدن چک داده قبل از تولید گزارشات PDF",
      "بهبود سیستم ارسال خودکار نوتیفیکیشن‌های یادآوری",
      "اضافه شدن آمار ساختمان‌ها به برچسب پاکت تستی",
      "اصلاح نمایش نام درس در فهرست حضور و غیاب",
    ],
  },
  "1.2.4": {
    date: "۱۴۰۴/۰۹/۲۸",
    changes: [
      "بهبود عملکرد مدیریت چندآزمونی",
      "اضافه شدن گزینه تفکیک حضور و غیاب بر اساس درس",
      "بهبود گزارش منشی جلسه",
    ],
  },
};

/**
 * Show version changelog modal on first login after version update
 * Saves seen version to localStorage to avoid showing again
 */
async function showVersionChangelogModal() {
  const currentVersion = window.APP_VERSION;
  if (!currentVersion) return;

  const storageKey = "nesaar_seen_version";
  const seenVersion = localStorage.getItem(storageKey);

  // If user has already seen this version, skip
  if (seenVersion === currentVersion) return;

  // Get changelog for current version
  const changelog = window.CHANGELOG?.[currentVersion];
  if (!changelog || !changelog.changes || changelog.changes.length === 0) {
    // No changelog for this version, just mark as seen
    localStorage.setItem(storageKey, currentVersion);
    return;
  }

  // Build changelog HTML
  let changesHtml = `
    <div style="text-align:right;direction:rtl;line-height:1.9;">
      <div style="font-size:1.1em;margin-bottom:12px;color:#60a5fa;">
        نسخه <strong>${currentVersion}</strong> ${
    changelog.date ? `- ${changelog.date}` : ""
  }
      </div>
      <ul style="margin:0;padding-right:20px;padding-left:0;">
  `;

  changelog.changes.forEach((change) => {
    changesHtml += `<li style="margin-bottom:6px;">${change}</li>`;
  });

  changesHtml += `</ul></div>`;

  // Show modal with 30-second countdown before allowing close
  const countdownSeconds = 30;
  let remainingSeconds = countdownSeconds;
  let countdownInterval;

  await Swal.fire({
    title: "✨ به‌روزرسانی جدید",
    html: changesHtml,
    width: 550,
    showCloseButton: false,
    showConfirmButton: false,
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: {
      popup: "swal2-rtl swal2-glass",
    },
    didOpen: () => {
      const popup = Swal.getPopup();

      // Create countdown element in top-left corner
      const countdownEl = document.createElement("div");
      countdownEl.className = "version-countdown";
      countdownEl.style.cssText =
        "position:absolute;top:12px;left:12px;font-size:1.2rem;font-weight:bold;color:#94a3b8;font-family:monospace;min-width:2ch;text-align:center;";
      countdownEl.textContent = remainingSeconds;
      popup?.appendChild(countdownEl);

      countdownInterval = setInterval(() => {
        remainingSeconds--;
        countdownEl.textContent = remainingSeconds;

        if (remainingSeconds <= 0) {
          clearInterval(countdownInterval);

          // Remove countdown element
          countdownEl.remove();

          // Add close button dynamically
          const closeBtn = document.createElement("button");
          closeBtn.type = "button";
          closeBtn.className = "swal2-close";
          closeBtn.innerHTML = "×";
          closeBtn.style.cssText =
            "position:absolute;top:8px;left:8px;font-size:2rem;color:#94a3b8;background:none;border:none;cursor:pointer;z-index:10;";
          closeBtn.onclick = () => Swal.close();
          popup?.appendChild(closeBtn);
        }
      }, 1000);
    },
    willClose: () => {
      if (countdownInterval) {
        clearInterval(countdownInterval);
      }
      // Mark version as seen when modal closes
      localStorage.setItem(storageKey, currentVersion);
    },
  });
}
