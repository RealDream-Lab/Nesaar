/* Small helper to show Swal with predefined animations (flip / slide)
   Usage: showSwalWithEffect({ title: 'Hello' }, 'flip')
*/
(function () {
  const effects = {
    flip: {
      showClass: { popup: "swal2-flip-in" },
      hideClass: { popup: "swal2-flip-out" },
    },
    slide: {
      showClass: { popup: "swal2-slide-in" },
      hideClass: { popup: "swal2-slide-out" },
    },
  };

  window.showSwalWithEffect = function (options = {}, effect = "flip") {
    if (
      typeof Swal === "undefined" ||
      !Swal ||
      typeof Swal.fire !== "function"
    ) {
      console.warn("SweetAlert2 not loaded");
      return Promise.reject(new Error("SweetAlert2 not loaded"));
    }
    const chosen = effects[effect] || effects.flip;
    const opts = Object.assign({}, options);
    if (!opts.showClass) opts.showClass = chosen.showClass;
    if (!opts.hideClass) opts.hideClass = chosen.hideClass;
    return Swal.fire(opts);
  };

  // Optional convenience wrappers
  window.SwalFlip = function (options) {
    return window.showSwalWithEffect(options, "flip");
  };
  window.SwalSlide = function (options) {
    return window.showSwalWithEffect(options, "slide");
  };
})();
