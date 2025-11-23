<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';

$licenseStatus = license_guard_validate();
if (($licenseStatus['valid'] ?? false) !== true) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($licenseStatus['message'] ?? 'دسترسی به سامانه عوامل اجرائی به دلیل مشکل لایسنس امکان‌پذیر نیست.', ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html lang=\"fa\" dir=\"rtl\"><head><meta charset=\"utf-8\"><title>خطای لایسنس</title><style>body{font-family:'Vazir',Tahoma,Arial,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;} .card{background:rgba(15,23,42,0.85);padding:2.5rem;border-radius:18px;max-width:520px;text-align:center;line-height:2;box-shadow:0 35px 80px rgba(15,23,42,0.45);} h1{margin-top:0;font-size:1.8rem;} .hint{margin-top:1.5rem;font-size:0.95rem;color:#cbd5f5;}</style><link rel=\"stylesheet\" href=\"../assets/fonts/vazir/vazir.css\"></head><body><div class=\"card\"><h1>اعتبار لایسنس تایید نشد</h1><p>{$message}</p><p class=\"hint\">لطفاً با مدیر سامانه برای تمدید لایسنس تماس بگیرید.</p></div></body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <?php echo csrf_meta_tag(); ?>
    <title>سامانه عوامل اجرائی - نسار</title>
    <link rel="icon" type="image/png" href="../assets/app/logo.png">
    <link rel="apple-touch-icon" sizes="192x192" href="../pwa-icons/icon-192.png">
    <link rel="stylesheet" href="../assets/fonts/vazir/vazir.css">
    <link rel="stylesheet" href="../assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/app/style.css">
    <link rel="stylesheet" href="style.css">
</head>

<body class="coworker-body">
    <div class="coworker-hero">
        <div class="coworker-brand">
            <img src="../assets/app/Pnulogo.png" alt="لوگو" class="coworker-logo">
            <div class="coworker-brand-text">
                <strong>سامانه نسار</strong>
                <span>پنل اختصاصی عوامل اجرائی آزمون</span>
            </div>
        </div>
        <div class="coworker-card" id="coworkerLoginCard">
            <div class="card-header text-center">
                <h1>ورود عوامل اجرائی</h1>
                <p>کد ملی و شماره همراه ثبت‌شده را وارد کنید</p>
            </div>
            <div class="card-body">
                <form id="coworkerForm" novalidate>
                    <div class="mb-3">
                        <label for="coworkerNationalId" class="form-label">کد ملی</label>
                        <input type="tel" class="form-control form-control-lg text-center" id="coworkerNationalId"
                            placeholder="مثال: 1234567890" inputmode="numeric" maxlength="10" required>
                    </div>
                    <div class="mb-4">
                        <label for="coworkerPhone" class="form-label">شماره همراه</label>
                        <input type="tel" class="form-control form-control-lg text-center" id="coworkerPhone"
                            placeholder="مثال: 09xxxxxxxxx" inputmode="numeric" maxlength="11" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg" id="coworkerSubmitBtn">
                            <span class="spinner-border spinner-border-sm d-none" role="status"
                                aria-hidden="true"></span>
                            <span class="text">ورود به پنل</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <section id="coworkerDashboard" class="d-none">
        <div class="session-card" id="coworkerProfile" aria-live="polite">
            <div>
                <div class="profile-name" id="coworkerName">-</div>
                <div class="profile-meta" id="coworkerMeta">در انتظار داده</div>
            </div>
            <div class="profile-actions">
                <button type="button" class="btn btn-outline-light btn-sm" id="statsBtn">نمایش آمار</button>
                <button type="button" class="btn btn-danger btn-sm" id="coworkerLogoutBtn">خروج</button>
            </div>
        </div>
        <div id="assignmentCards" class="exam-cards-container"></div>
        <div class="empty-state d-none" id="coworkerEmptyState">
            <p>جلسه‌ای برای شما ثبت نشده است.</p>
        </div>
    </section>

    <footer class="coworker-footer" id="coworkerFooter">
        <span id="coworkerFooterText">نسار - دانشگاه پیام نور</span>
        <span class="footer-clock" id="coworkerClock">--:--</span>
    </footer>

    <div class="waves-header" aria-hidden="true">
        <div class="waves-inner-header"></div>
        <svg class="waves" xmlns="http://www.w3.org/2000/svg" viewBox="0 24 150 28" preserveAspectRatio="none"
            shape-rendering="auto">
            <defs>
                <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s58 18 88 18 58-18 88-18 58 18 88 18v44h-352z" />
            </defs>
            <g class="parallax">
                <use xlink:href="#gentle-wave" x="48" y="0" fill="rgba(12,114,173,0.22)" />
                <use xlink:href="#gentle-wave" x="48" y="3" fill="rgba(18,126,189,0.28)" />
                <use xlink:href="#gentle-wave" x="48" y="5" fill="rgba(24,140,205,0.32)" />
                <use xlink:href="#gentle-wave" x="48" y="7" fill="#1a6fa6" />
            </g>
        </svg>
    </div>

    <script src="../assets/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/sweetalert2/sweetalert2.min.js"></script>
    <script src="../assets/app/version.js"></script>
    <script src="coworker.js"></script>
</body>

</html>