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
<?php
// Server-side check: if there are any locations with required_proctors = 0,
// show the locations card automatically on page load.
try {
    require_once __DIR__ . '/../../API/db_init.php';
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `locations` WHERE required_proctors = 0");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $showLocationsCard = ($row && intval($row['c'] ?? 0) > 0) ? true : false;
    }
} catch (Exception $e) {
    // ignore DB errors here; default to not showing the card
    $showLocationsCard = false;
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
        <!-- observers page uses global dashboard styles from /assets/app/style.css -->
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
                        <!-- Locations quick-open button (hidden: shows locations card when clicked) -->
                        <button id="showLocationsBtn" class="btn btn-icon p-0" type="button" title="نمایش مکان‌ها" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/observers/locations.png" alt="مکان‌ها" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                        <!-- Stats quick-open button: shows the session stats card when clicked -->
                        <button id="showStatsBtn" class="btn btn-icon p-0" type="button" title="نمایش نمودار" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/statices.png" alt="نمودار" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                        <!-- Exams detail quick-open button-->
                        <button id="showExamsDetailBtn" class="btn btn-icon p-0" type="button" title="جزئیات جلسات" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/exams.png" alt="جزئیات" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                        <!-- Proctors quick-open button-->
                        <button id="showProctorsBtn" class="btn btn-icon p-0" type="button" title="مشخصات مراقبین" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/proctors.png" alt="مراقبین" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button> 
                        <button id="backToDashboardBtn" class="btn btn-icon p-0" type="button" title="بازگشت به داشبورد" style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/home.png" alt="بازگشت" style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                    </div>
                </div>
            </div>

            <div class="observers-main">
                <!-- کارت آمار جلسات و مراقبین -->
                <div class="dashboard-card module-card no-hover" id="sessionStatsCard" style="display:none;">
                    <h4>آمار جلسات و مراقبین</h4>
                    <div id="sessionStatsContent" style="margin-top:0.6rem; position:relative;">
                        <!-- legend for time slots will be injected here -->
                        <div id="sessionTimeLegend" style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-bottom:0.6rem;align-items:center;
                            font-size:0.92rem;color:var(--text-muted);"></div>
                        <div id="sessionChartWrapper" style="position:relative;">
                            <canvas id="sessionStatsChart" style="width:100%;height:520px;display:block;" aria-label="نمودار نیاز مراقبین" role="img"></canvas>
                            <!-- spinner overlay (hidden by default) -->
                            <div id="sessionChartSpinner" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(255,255,255,0.0);">
                                <div style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                                    <svg width="44" height="44" viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg">
                                        <defs>
                                            <linearGradient id="g" x1="0%" x2="100%" y1="0%" y2="100%">
                                                <stop offset="0%" stop-color="#1a6fa6" />
                                                <stop offset="100%" stop-color="#ffc107" />
                                            </linearGradient>
                                        </defs>
                                        <g fill="none" fill-rule="evenodd" stroke="url(#g)" stroke-width="4">
                                            <path d="M22 2 A20 20 0 0 1 42 22" stroke-linecap="round">
                                                <animateTransform attributeName="transform" type="rotate" from="0 22 22" to="360 22 22" dur="1s" repeatCount="indefinite" />
                                            </path>
                                        </g>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <!-- کارت مکان‌ها -->
                <div class="dashboard-card module-card no-hover" id="locationsCard" style="<?php echo (!empty(
$showLocationsCard) ? '' : 'display:none;'); ?>">
                    <h4>مکان‌های معرفی‌شده برگزاری آزمون</h4>
                    <p style="margin-bottom:0.6rem;color:#04202a;">لیست کلاس‌ها و تعداد مراقبین مورد نیاز را در اینجا مشاهده و ویرایش کنید.</p>
                    <div id="locationsList" style="margin-top:0.8rem;"></div>
                    <div style="margin-top:0.8rem;text-align:center;">
                        <button id="saveAllBtn" class="btn btn-success" style="width: 300px;" disabled>ذخیره همه و قفل ویرایش</button>
                    </div>
                </div>
                <!-- کارت جزئیات مراقبین هر جلسه -->
                <div class="dashboard-card module-card no-hover" id="examsDetailCard" style="display:none;">
                    <h4>جزئیات مراقبین هر جلسه</h4>
                    <p style="margin-bottom:0.6rem;color:#04202a;">لیست تعداد مراقبین مورد نیاز برای هر جلسه را مشاهده و ویرایش کنید. می‌توانید هر سطر را جدا ذخیره کنید یا از دکمهٔ "ذخیره همه" استفاده کنید.</p>
                    <div id="examsDetailList" style="margin-top:0.8rem;"></div>
                    <div style="margin-top:0.8rem;text-align:center;">
                        <button id="saveExamsDetailAllBtn" class="btn btn-success" style="width: 200px; margin-inline-end: 10px;" disabled>ذخیره همه، ادامه</button>
                        <button id="noChangeNeededBtn" class="btn btn-secondary" style="width: 200px;" disabled>تغییر لازم نیست، ادامه</button>
                    </div>
                </div>
                <!-- کارت مشخصات مراقبین -->
                <div class="dashboard-card module-card no-hover" id="proctorsCard" style="display:none;">
                    <h4>مشخصات مراقبین</h4>
                    <div style="position:absolute;top:1rem;left:1rem;font-size:0.9rem;" id="proctorsStats"></div>
                    <p style="margin-bottom:0.6rem;color:#04202a;">مشخصات مراقبین را اضافه یا ویرایش کنید.</p>
                    <!-- Edit section -->
                    <div id="proctorEditSection" style="margin-bottom:1rem;padding:1rem;border:1px solid #dee2e6;border-radius:0.5rem;background:#f8f9fa;">
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">جنسیت</label>
                                <select class="form-select" id="proctorGender">
                                    <option value="">انتخاب کنید</option>
                                    <option value="زن">زن</option>
                                    <option value="مرد">مرد</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">نام</label>
                                <input type="text" class="form-control" id="proctorFirstName" maxlength="40">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">نام خانوادگی</label>
                                <input type="text" class="form-control" id="proctorLastName" maxlength="40">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">شماره همراه</label>
                                <input type="text" class="form-control" id="proctorPhone" maxlength="11" inputmode="numeric">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button id="saveProctorBtn" class="btn btn-primary me-2">اضافه/ویرایش</button>
                                <button id="clearProctorBtn" class="btn btn-secondary">پاک کردن</button>
                            </div>
                        </div>
                    </div>
                    <!-- Table section -->
                    <div id="proctorsList" style="margin-top:0.8rem;"></div>
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
