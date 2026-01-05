<?php
// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';

$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($licenseStatus['message'] ?? 'دسترسی به داشبورد به دلیل مشکل لایسنس ممنوع است.', ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html lang=\"fa\" dir=\"rtl\"><head><meta charset=\"utf-8\"><title>خطای لایسنس</title><style>body{font-family:'Vazir',Tahoma,Arial,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;} .card{background:rgba(15,23,42,0.85);padding:2.5rem;border-radius:18px;max-width:520px;text-align:center;line-height:2;box-shadow:0 35px 80px rgba(15,23,42,0.45);} h1{margin-top:0;font-size:1.8rem;} .hint{margin-top:1.5rem;font-size:0.95rem;color:#cbd5f5;}</style><link rel=\"stylesheet\" href=\"../assets/fonts/vazir/vazir.css\"></head><body><div class=\"card\"><h1>اعتبار لایسنس تایید نشد</h1><p>{$message}</p><p class=\"hint\">لطفاً برای تمدید یا بررسی لایسنس با پشتیبانی تماس بگیرید.</p></div></body></html>";
    exit;
}

// Read WavesAnimation and University config
$wavesAnimationDisabled = false;
$pageTitle              = 'پنل مدیریت نسار';
try {
    require_once __DIR__ . '/../API/db_init.php';
    $stmt = $pdo->prepare("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('WavesAnimation', 'University')");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        if ($row['ConfigName'] === 'WavesAnimation' && strtoupper($row['ConfigValue']) === 'NO') {
            $wavesAnimationDisabled = true;
        }
        if ($row['ConfigName'] === 'University' && !empty(trim($row['ConfigValue']))) {
            $pageTitle = 'پنل مدیریت نسار - ' . trim($row['ConfigValue']);
        }
    }
} catch (Exception $e) {
    // Ignore - default to animation enabled
}
$bodyClass = $wavesAnimationDisabled ? 'class="no-waves-animation"' : '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2196F3">
    <?php echo csrf_meta_tag(); ?>
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- Favicons -->
    <link rel="icon" type="image/png" href="../assets/app/logo.png" />
    <link rel="shortcut icon" type="image/png" href="../assets/app/logo.png" />
    <link rel="apple-touch-icon" sizes="192x192" href="../pwa-icons/icon-192.png" />
    <link rel="apple-touch-icon" sizes="512x512" href="../pwa-icons/icon-512.png" />

    <link rel="stylesheet" href="../assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fonts/vazir/vazir.css">
    <link rel="stylesheet" href="../assets/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/sweetalert2/swal-animations.css">
    <link rel="stylesheet" href="../assets/vendor/jalalidatepicker/jalalidatepicker.min.css">
    <link rel="stylesheet" href="../assets/app/style.css">
</head>

<body <?php echo $bodyClass; ?>>
    <div class="dashboard-wrapper">
        <div class="dashboard-container">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="admin-info" style="margin-right:24px;">
                            <div>
                                <h5 class="mb-0">خوش آمدید</h5>
                                <small class="text-muted" id="adminUsername">مدیر سیستم</small>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <button id="dashboardHomeBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="بازگشت به داشبورد" aria-label="بازگشت به داشبورد"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;"
                            onclick="window.location.href='/dashboard';">
                            <img src="/dashboard/home.png" alt="بازگشت به داشبورد"
                                style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>

                        <button id="absentBtn" class="btn btn-icon p-0" type="button" data-tooltip="ثبت غیبت"
                            aria-label="ثبت غیبت"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/assets/app/absent.png" alt="ثبت غیبت"
                                style="width:40px;height:40px;object-fit:contain;display:block;padding-bottom: 2px;padding-top: 3px;">
                        </button>
                        <button id="proctorProfilesBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="ماژول مراقبین و عوامل اجرائی" aria-label="ماژول مراقبین و عوامل اجرائی"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/observers/users.png" alt="مشخصات مراقبین"
                                style="width:40px;height:40px;object-fit:contain;display:block;margin-bottom: 5px;">
                        </button>
                        <button id="manageLocationsBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="معرفی و ویرایش مکان‌های برگزاری آزمون"
                            aria-label="معرفی و ویرایش مکان‌های برگزاری آزمون"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/assets/app/building.png" alt="معرفی و ویرایش مکان‌ها"
                                style="width:40px;height:40px;object-fit:contain;display:block; margin-top: 3px !important ;padding-top: 2px;padding-bottom: 2px;">
                        </button>
                        <button id="proctorNoticeBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="ابلاغ مراقبین" aria-label="ابلاغ مراقبین"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/observers/calendar.png" alt="ابلاغ مراقبین"
                                style="width:40px;height:40px;object-fit:contain;display:block; margin-top: 3px !important;">
                        </button>
                        <button id="recipientAccessBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="کاربر استخراج گزارش‌ها" aria-label="کاربر استخراج گزارش‌ها"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/assets/app/recipient.png" alt="کاربر Recipient"
                                style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                        <button id="upload" class="btn btn-icon p-0" type="button" data-tooltip="آپلود عکس دانشجویان"
                            aria-label="آپلود عکس دانشجویان"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/upload.png" alt="آپلود عکس دانشجویان"
                                style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>
                        <!-- Photo Update Requests Notification Bell -->
                        <button id="photoRequestsBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="درخواست‌های تغییر عکس" aria-label="درخواست‌های تغییر عکس"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;position:relative;">
                            <img src="/dashboard/bell.png" alt="درخواست‌های تغییر عکس"
                                style="width:40px;height:40px;object-fit:contain;display:block;padding-top: 3px;padding-bottom: 3px;">
                            <span id="photoRequestsBadge" class="notification-badge"
                                style="display:none;position:absolute;top:-5px;right:-5px;background:#ef4444;color:white;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:10px;min-width:18px;text-align:center;"></span>
                        </button>
                        <button id="editRolesBtn" class="btn btn-icon p-0" type="button"
                            data-tooltip="پیکربندی و ویرایش نقش‌ها" aria-label="پیکربندی و ویرایش نقش‌ها"
                            style="background:transparent;border:none;margin-inline-end:8px;padding:0;">
                            <img src="/dashboard/config.png" alt="پیکربندی و ویرایش نقش‌ها" class="rotating-icon"
                                style="width:40px;height:40px;object-fit:contain;display:block;">
                        </button>

                        <button class="btn btn-logout" id="logoutBtn" type="button" data-tooltip="خروج"
                            aria-label="خروج"
                            style="background:transparent;border:none;margin-inline-start:0;padding:0;">
                            <img src="/dashboard/logout.png" alt="خروج"
                                style="width:40px;height:40px;object-fit:contain;display:block;transform:rotate(180deg);">
                        </button>
                    </div>
                </div>
            </div> <!-- Stats -->
            <div class="row">
                <div class="col-md-3">
                    <div class="dashboard-card stat-card" style="cursor: pointer;" onclick="showStudentReport()">
                        <div class="stat-box">
                            <h3 id="totalStudents">-</h3>
                            <p>دانشجو</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card stat-card" style="cursor: pointer;" onclick="showCourseReport()">
                        <div class="stat-box">
                            <h3 id="totalCourses">-</h3>
                            <p>آزمون</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card stat-card" style="cursor: pointer;" onclick="showProctorSearch()">
                        <div class="stat-box">
                            <h3 id="totalProctors">-</h3>
                            <p>مراقب</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card stat-card" style="cursor: pointer;" onclick="showNextExamReport()">
                        <div class="stat-box">
                            <h3 id="nextExamStudents">-</h3>
                            <p id="nextExamDateTime">بارگذاری...</p>
                        </div>
                    </div>
                </div>
            </div>



        </div>

        <!-- Converted custom cards into three full-width red buttons (side-by-side) -->
        <!-- Buttons are placed under the main reports chart below so they together span the full width -->
        <!-- The IDs are preserved for compatibility with any JS hooks (customCardOne/Two/Three) -->

        <!-- Session Calendar Card -->
        <div class="dashboard-card no-hover collapsible-card" id="sessionCalendarCard">
            <div class="card-header-collapsible" onclick="toggleCardCollapse(this)">
                <h4 class="mb-0">تقویم جلسات آزمون</h4>
                <span class="collapse-icon">▼</span>
            </div>
            <div class="card-body-collapsible">
                <div id="sessionCalendarContainer">
                    <div class="calendar-loading">در حال بارگذاری تقویم...</div>
                </div>
            </div>
        </div>

        <!-- Latest Request Report -->
        <div class="dashboard-card no-hover" id="reportCard" style="display: none;">
            <h4 class="mb-3">آخرین گزارش درخواستی</h4>
            <div id="reportContent"></div>
            <div class="text-center mt-3">
                <button class="btn btn-danger" onclick="clearReport()">پاک کردن گزارش</button>
            </div>
        </div>


        <!-- Reports Chart Card -->
        <div class="dashboard-card no-hover collapsible-card" id="reportsChartCard">
            <div class="card-header-collapsible" onclick="toggleCardCollapse(this)">
                <h4 class="mb-0">نمودار جلسات باقی‌مانده آزمون</h4>
                <span class="collapse-icon">▼</span>
            </div>
            <div class="card-body-collapsible">
                <div class="reports-overview">
                    <div class="chart-wrapper">
                        <canvas id="reportsChart" aria-label="نمودار جلسات آینده" role="img"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Insight Cards Row - DISABLED -->
        <!-- <div class="row mt-4" id="insightCardsContainer"></div> -->

        <!-- Quick Action Cards Row -->
        <div class="row mt-4" id="quickActionCardsContainer">
            <div class="col-md-3 mb-3">
                <div class="quick-action-card" id="examBookletCard" data-action="exam-booklet">
                    <div class="quick-action-icon">📋</div>
                    <div class="quick-action-title">دفترچه کلی آزمون‌ها</div>
                    <div class="quick-action-desc">گزارش کامل آمار آزمون‌ها به تفکیک جلسه</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="quick-action-card" id="seatNumbersCard" data-action="seat-numbers">
                    <div class="quick-action-icon">🪑</div>
                    <div class="quick-action-title">چاپ شماره‌ صندلی‌</div>
                    <div class="quick-action-desc">برچسب شماره صندلی برای الصاق روی صندلی‌ها</div>
                </div>
            </div><!--
            <div class="col-md-3 mb-3">
                <div class="quick-action-card disabled" id="card3" data-action="card3">
                    <div class="quick-action-icon">📊</div>
                    <div class="quick-action-title">کارت شماره سه</div>
                    <div class="quick-action-desc">به زودی...</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="quick-action-card disabled" id="card4" data-action="card4">
                    <div class="quick-action-icon">📈</div>
                    <div class="quick-action-title">کارت شماره چهار</div>
                    <div class="quick-action-desc">به زودی...</div>
                </div>
            </div>-->
        </div>

        <!-- Push Notification Management Card -->
        <div class="dashboard-card no-hover collapsible-card" id="pushNotificationCard">
            <div class="card-header-collapsible" onclick="toggleCardCollapse(this)">
                <h4 class="mb-0">ارسال اعلان (پوش نوتیفیکیشن)</h4>
                <span class="collapse-icon">▼</span>
            </div>
            <div class="card-body-collapsible">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p style="color:#666; margin-bottom:0;">ارسال پیام به دانشجویان و مراقبین ثبت‌نام شده در سیستم اعلان
                    </p>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="openPushSettingsBtn"
                        title="تنظیمات یادآوری خودکار">
                        <i class="bi bi-gear"></i> تنظیمات
                    </button>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label">عنوان پیام</label>
                        <input type="text" class="form-control" id="pushTitle" placeholder="مثال: اطلاعیه مهم"
                            maxlength="100">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">گیرندگان</label>
                        <div class="d-flex gap-3 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pushStudents" checked>
                                <label class="form-check-label" for="pushStudents"
                                    style="color:#1e293b;font-weight:600;">دانشجویان</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pushProctors" checked>
                                <label class="form-check-label" for="pushProctors"
                                    style="color:#1e293b;font-weight:600;">مراقبین</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">متن پیام</label>
                        <textarea class="form-control" id="pushBody" rows="3" placeholder="متن پیام را وارد کنید..."
                            maxlength="500"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="scheduledPush" role="switch">
                                <label class="form-check-label" for="scheduledPush"
                                    style="color:#1e293b;font-weight:600;">ارسال زمان‌بندی شده</label>
                            </div>
                        </div>
                        <div id="scheduledPushContainer" class="d-none">
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">تاریخ و ساعت ارسال</label>
                                    <input type="text" class="form-control" id="pushScheduleDateTime" data-jdp
                                        data-jdp-time="true" data-jdp-min-date="today" placeholder="انتخاب تاریخ و ساعت"
                                        readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-12">
                        <button class="btn btn-upload-blue w-100" id="sendPushBtn">
                            <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                            <span id="sendPushBtnText">ارسال اعلان</span>
                        </button>
                    </div>
                    <div class="col-12">
                        <div id="pushResult" class="alert d-none" role="alert"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Update (moved here to bottom-most section) -->
        <div class="dashboard-card no-hover collapsible-card" id="databaseCard">
            <div class="card-header-collapsible" onclick="toggleCardCollapse(this)">
                <h4 class="mb-0">بانک اطلاعاتی آزمون‌ها</h4>
                <span class="collapse-icon">▼</span>
            </div>
            <div class="card-body-collapsible">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <button class="btn btn-upload w-100" id="uploadWrittenBtn">
                            آپلود آزمون‌های کتبی
                        </button>
                    </div>
                    <div class="col-12 col-md-4">
                        <button class="btn btn-upload w-100" id="uploadElectronicBtn">
                            آپلود آزمون‌های الکترونیکی
                        </button>
                    </div>
                    <div class="col-12 col-md-4">
                        <button class="btn btn-upload w-100" id="updateDBBtn">
                            به‌روزرسانی پایگاه داده
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Fixed Footer -->
    <footer class="fixed-footer" id="copyrightFooter" style="cursor: pointer; text-align: center;">
        <span class="footer-text" id="footerText"
            style="color: white; font-weight: 600; text-shadow: 0 0 3px rgba(0,0,0,0.8), 0 0 5px rgba(0,0,0,0.6);">نسار
            - دانشگاه پیام نور بیجار</span>
    </footer>

    <!-- Waves Background -->
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

    <script src="../assets/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="../assets/sweetalert2/sweetalert2.min.js"></script>
    <script src="../assets/app/swal-helper.js"></script>
    <script src="../assets/app/version.js"></script>
    <script src="../assets/app/changelog.js"></script>
    <!-- Local Chart.js (UMD) - load synchronously so window.Chart is available -->
    <script src="../assets/vendor/chartjs/chart.min.js"></script>
    <!-- JalaliDatePicker for scheduled push notifications -->
    <script src="../assets/vendor/jalalidatepicker/jalalidatepicker.min.js"></script>
    <!-- Device check and SweetAlert moved to dashboard.js -->
    <script>
        window.DASHBOARD_CONTEXT = { role: 'admin' };
    </script>
    <script src="dashboard.js"></script>
</body>

</html>