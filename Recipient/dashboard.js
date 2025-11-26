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

  // Global wrapper: Fix scroll jumping by disabling heightAuto
  (function wrapSwalScrollPreservationRecipient() {
    try {
      if (typeof Swal === "undefined" || Swal._ns_scroll_patched_recipient)
        return;

      const SwalNoAutoHeight = Swal.mixin({ heightAuto: false });
      const _origFire = SwalNoAutoHeight.fire.bind(SwalNoAutoHeight);
      Swal.fire = function (opts) {
        return _origFire.apply(SwalNoAutoHeight, arguments);
      };
      Swal._ns_scroll_patched_recipient = true;
    } catch (e) {}
  })();
})();
