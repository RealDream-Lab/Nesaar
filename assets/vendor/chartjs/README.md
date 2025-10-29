# Chart.js (vendor)

This folder is intended to host a local copy of Chart.js so the app can use an offline/local vendor version instead of loading from a CDN.

Why place Chart.js here?
- Avoid runtime dependency on external CDNs for privacy/offline use.
- Lock a specific Chart.js version for compatibility and reproducible builds.

What to put here
- Download the Chart.js minified bundle (e.g. `chart.min.js`) from the official release:
  - https://www.jsdelivr.com/package/npm/chart.js (or https://github.com/chartjs/Chart.js/releases)

Recommended filename and path inside this repo:

  assets/vendor/chartjs/chart.min.js

Example curl command (replace VERSION with desired Chart.js version, e.g. 4.3.0):

```bash
# download Chart.js production build and save to project vendor folder
curl -L https://cdn.jsdelivr.net/npm/chart.js@VERSION/dist/chart.min.js -o assets/vendor/chartjs/chart.min.js
```

Loader helper
- This folder contains `chartjs-loader.js` which tries to load the local `chart.min.js` and falls back to the CDN if the local file is not present.

How to use in your pages
- Include the loader before any script that uses Chart.js (e.g. in your dashboard HTML):

```html
<script src="/assets/vendor/chartjs/chartjs-loader.js"></script>
<script>
  // Now window.Chart will be available (or loaded asynchronously). Build charts afterwards.
</script>
```

Notes
- For production builds consider vendorizing using a build step (npm install chart.js and bundle with your frontend pipeline), or include `chart.min.js` in your build artifacts.
- If you need server-side rendering of charts (PNG/PDF), consider a separate Node service using `chartjs-node-canvas`.
