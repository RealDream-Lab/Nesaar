(function () {
  window.DASHBOARD_CONTEXT = { role: "recipient" };

  document.documentElement.classList.add("recipient-dashboard");
  document.body.classList.add("recipient-dashboard-body");

  document.addEventListener("DOMContentLoaded", () => {
    const homeBtn = document.getElementById("dashboardHomeBtn");
    if (homeBtn) {
      homeBtn.setAttribute("title", "بازگشت به داشبورد گزارش‌گیری");
    }

    // Check if proctor assignments are complete to show observers module button
    checkAndShowProctorModule();
  });

  // Check if proctor assignments are complete and show the button
  async function checkAndShowProctorModule() {
    const proctorProfilesBtn = document.getElementById("proctorProfilesBtn");
    if (!proctorProfilesBtn) return;

    try {
      // Check if there are any exam assignments
      const response = await fetch(
        "../API/getExamAssignments.php?check_only=1",
        {
          cache: "no-store",
        }
      );

      if (response.ok) {
        const data = await response.json();
        // Show button if assignments exist (meaning proctor assignment is done)
        if (data.success && data.hasAssignments) {
          proctorProfilesBtn.style.display = "block";
          proctorProfilesBtn.addEventListener("click", () => {
            window.location.href = "/dashboard/observers/";
          });
        }
      }
    } catch (e) {
      console.warn("Failed to check proctor assignments", e);
    }
  }

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
