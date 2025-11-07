<?php
// observers module entry
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/license_guard.php';
require_once __DIR__ . '/../../includes/csrf_protection.php';

$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($licenseStatus['message'] ?? 'دسترسی به ماژول مراقبین به دلیل مشکل لایسنس ممنوع است.', ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html lang=\"fa\" dir=\"rtl\"><head><meta charset=\"utf-8\"><title>خطای لایسنس</title><style>body{font-family:'Vazir',Tahoma,Arial,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;} .card{background:rgba(15,23,42,0.85);padding:2.5rem;border-radius:18px;max-width:520px;text-align:center;line-height:2;box-shadow:0 35px 80px rgba(15,23,42,0.45);} h1{margin-top:0;font-size:1.8rem;} .hint{margin-top:1.5rem;font-size:0.95rem;color:#cbd5f5;}</style><link rel=\"stylesheet\" href=\"/assets/fonts/vazir/vazir.css\"></head><body><div class=\"card\"><h1>اعتبار لایسنس تایید نشد</h1><p>{$message}</p><p class=\"hint\">لطفاً برای تمدید یا بررسی لایسنس با پشتیبانی تماس بگیرید.</p></div></body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo csrf_meta_tag(); ?>
    <title>ماژول مراقبین - داشبورد</title>
    <link rel="icon" type="image/png" href="/assets/app/logo.png" />
    <link rel="stylesheet" href="/assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/fonts/vazir/vazir.css">
    <link rel="stylesheet" href="/assets/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/app/style.css">
    <style>
        /* Minimal observers-specific tweaks */
        .observers-main { padding: 2rem; }
        .module-card { max-width: 920px; margin: 1.2rem auto; padding: 1rem; }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">
        <div class="dashboard-container">
            <!-- Header: reuse dashboard style, but logout becomes back-to-dashboard -->
            <div class="dashboard-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="admin-info" style="margin-right:24px;">
                            <div>
                                <h5 class="mb-0">ماژول مراقبین</h5>
                                <small class="text-muted" id="adminUsername">مدیر سیستم</small>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <button id="backToDashboardBtn" class="btn btn-icon p-0" type="button" title="بازگشت به داشبورد" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/home.png" alt="بازگشت" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                    </div>
                </div>
            </div>

            <div class="observers-main">
                <!-- کارت مکان‌ها -->
                <div class="dashboard-card module-card" id="locationsCard" style="max-width:980px;margin:1.2rem auto;padding:1rem;">
                    <h4>مکان‌های معرفی‌شده برگزاری آزمون</h4>
                    <p style="margin-bottom:0.6rem;color:var(--text-muted);">لیست کلاس‌ها و تعداد مراقبین مورد نیاز را در اینجا مشاهده و ویرایش کنید.</p>
                    <div id="locationsList" style="margin-top:0.8rem;"></div>
                </div>
            </div>

            <!-- Footer (same as dashboard) -->
            <footer class="fixed-footer" id="copyrightFooter" style="cursor: pointer; text-align: center;">
                <span class="footer-text" id="footerText"
                    style="color: white; font-weight: 600; text-shadow: 0 0 3px rgba(0,0,0,0.8), 0 0 5px rgba(0,0,0,0.6);">نسار - دانشگاه پیام نور</span>
            </footer>

        </div>
    </div>

    <script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/sweetalert2/sweetalert2.min.js"></script>
    <script src="/assets/app/version.js"></script>
    <script src="/assets/vendor/chartjs/chart.min.js"></script>
    <script src="observers.js"></script>

    <!-- Waves Background (same as dashboard) -->
    <div class="waves-header" aria-hidden="true">
        <div class="waves-inner-header"></div>
        <svg class="waves" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
            viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
            <defs>
                <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s58 18 88 18 58-18 88-18 58 18 88 18v44h-352z" />
            </defs>
            <g class="parallax">
                <use xlink:href="#gentle-wave" x="48" y="0" fill="rgba(12, 114, 173, 0.22)" />
                <use xlink:href="#gentle-wave" x="48" y="3" fill="rgba(18, 126, 189, 0.28)" />
                <use xlink:href="#gentle-wave" x="48" y="5" fill="rgba(24, 140, 205, 0.32)" />
                <use xlink:href="#gentle-wave" x="48" y="7" fill="#1a6fa6" />
            </g>
        </svg>
    </div>
</body>

</html>
