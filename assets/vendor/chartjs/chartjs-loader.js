/* chartjs-loader.js
 * Loads a local copy of Chart.js (assets/vendor/chartjs/chart.min.js) if present.
 * If the local file isn't loaded within a short timeout, falls back to the CDN.
 * Usage: include this script before any code that depends on Chart.
 */
(function () {
  const localPath = '/assets/vendor/chartjs/chart.min.js';
  const cdnUrl = 'https://cdn.jsdelivr.net/npm/chart.js/dist/chart.min.js';
  const scriptId = 'chartjs-local-or-cdn';

  function insertScript(src, onload, onerror) {
    if (document.getElementById(scriptId)) {
      if (onload) onload();
      return;
    }
    const s = document.createElement('script');
    s.id = scriptId;
    s.src = src;
    s.async = false; // load in order
    if (onload) s.onload = onload;
    if (onerror) s.onerror = onerror;
    document.head.appendChild(s);
  }

  function tryLocalThenCdn() {
    // Try inserting the local script first
    insertScript(localPath, function () {
      // If Chart is already available after loading local file, done.
      if (window.Chart) return;
      // Otherwise, fallback to CDN
      insertScript(cdnUrl);
    }, function () {
      // local load failed, fall back to CDN
      insertScript(cdnUrl);
    });

    // Safety: if local script doesn't define window.Chart within 1.5s, try CDN
    setTimeout(function () {
      if (!window.Chart) {
        insertScript(cdnUrl);
      }
    }, 1500);
  }

  // Execute loader
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryLocalThenCdn);
  } else {
    tryLocalThenCdn();
  }
})();
