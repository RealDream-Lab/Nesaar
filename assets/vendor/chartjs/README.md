# Chart.js local vendor

This directory is intended to hold a local copy of the Chart.js UMD build so the demo page can work without CDN access.

Recommended file to place here:
  - `chart.umd.min.js` (Chart.js UMD bundle, e.g. from https://www.jsdelivr.com/package/npm/chart.js)

How to download the file locally (example using curl):

```bash
mkdir -p assets/vendor/chartjs
curl -L -o assets/vendor/chartjs/chart.umd.min.js \
  https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js
```

After placing `chart.umd.min.js` here, the demo at `/dashboard/chartjs-cdn-demo.html` will use the local file and will not load the CDN.
