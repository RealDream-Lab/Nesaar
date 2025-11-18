(function () {
  window.DASHBOARD_CONTEXT = { role: "recipient" };

  document.documentElement.classList.add("recipient-dashboard");
  document.body.classList.add("recipient-dashboard-body");

  document.addEventListener("DOMContentLoaded", () => {
    const homeBtn = document.getElementById("dashboardHomeBtn");
    if (homeBtn) {
      homeBtn.setAttribute("title", "بازگشت به داشبورد گزارش‌گیری");
    }
  });
})();
