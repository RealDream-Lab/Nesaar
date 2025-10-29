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
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2196F3">
    <?php echo csrf_meta_tag(); ?>
    <title>پنل مدیریت - نسار</title>
    
    <!-- Favicons -->
    <link rel="icon" type="image/png" href="../assets/app/logo.png" />
    <link rel="shortcut icon" type="image/png" href="../assets/app/logo.png" />
    <link rel="apple-touch-icon" sizes="192x192" href="../pwa-icons/icon-192.png" />
    <link rel="apple-touch-icon" sizes="512x512" href="../pwa-icons/icon-512.png" />
    
    <link rel="stylesheet" href="../assets/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fonts/vazir/vazir.css">
    <link rel="stylesheet" href="../assets/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/app/style.css">
    <style>
        body {
            background: linear-gradient(180deg, rgba(18, 126, 189, 0.9), rgba(26, 111, 166, 0.8)),
                url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxkZWZzPjxwYXR0ZXJuIGlkPSJncmlkIiB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHBhdHRlcm5Vbml0cz0idXNlclNwYWNlT25Vc2UiPjxwYXRoIGQ9Ik0gNDAgMCBMIDAgMCAwIDQwIiBmaWxsPSJub25lIiBzdHJva2U9InJnYmEoMjU1LDI1NSwyNTUsMC4wNSkiIHN0cm9rZS13aWR0aD0iMSIvPjwvcGF0dGVybj48L2RlZnM+PHJlY3Qgd2lkdGg9IjEwMCUiIGhlaWdodD0iMTAwJSIgZmlsbD0idXJsKCNncmlkKSIvPjwvc3ZnPg==');
            min-height: 100vh;
            padding-bottom: 80px;
            position: relative;
            background-attachment: fixed;
            background-size: cover;
        }

        .dashboard-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
            position: relative;
            z-index: 1;
        }

        .dashboard-container {
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .dashboard-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.9) 100%);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .dashboard-card.no-hover:hover {
            transform: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .dashboard-card h4 {
            font-size: 1.5rem;
            color: #1a6fa6;
            font-weight: 700;
        }

        .btn-primary {
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .stat-box {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #1a6fa6 0%, #127ead 100%);
            border-radius: 15px;
            color: white;
            margin-bottom: 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .stat-box h3 {
            font-size: 3rem;
            font-weight: bold;
            margin: 0;
        }

        .stat-box p {
            margin: 0.5rem 0 0 0;
            opacity: 0.95;
            font-size: 1.1rem;
        }

        .btn-logout {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            transition: background 0.3s ease;
        }

        .btn-logout:hover {
            background: #c82333;
            color: white;
        }

        .btn-upload {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            transition: background 0.3s ease;
            font-size: 1rem;
        }

        .btn-upload:hover {
            background: #218838;
            color: white;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-info h5 {
            font-size: 1.3rem;
            color: #1a6fa6;
            font-weight: 700;
        }

        .admin-info small {
            font-size: 1rem;
        }

        .table td {
            font-size: 1.1rem;
            padding: 0.8rem 0.5rem;
        }

        .table strong {
            color: #1a6fa6;
        }

        .table {
            --bs-table-bg: transparent;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #1a6fa6;
        }

        .table-bordered th,
        .table-bordered td {
            border: 1px solid #dee2e6;
        }

        #reportContent {
            /* No scroll bar - card expands as needed */
            color: #6c757d; /* Gray color for text */
        }

        #reportContent td {
            color: #6c757d !important;
        }

        #reportContent h5 {
            background: white;
            padding: 10px 0;
        }

        /* Tweak SweetAlert input appearance: center and improve desktop look */
        .swal2-html-container {
            /* center inline HTML content inside the modal */
            text-align: center;
            overflow: visible !important;
        }

        .swal2-input {
            display: block;
            width: 14rem;
            max-width: 92%;
            margin: 0.6rem auto !important;
            padding: 0.6rem 0.85rem !important;
            border-radius: 10px !important;
            border: 1px solid #d6dbe0 !important;
            box-shadow: 0 4px 10px rgba(16, 24, 40, 0.06) inset !important;
            font-size: 1.05rem !important;
            text-align: center !important;
            direction: ltr !important;
            font-family: 'Vazir', sans-serif !important;
            background: #ffffff !important;
            color: #333 !important;
        }

        /* Slightly larger input on very wide screens */
        @media (min-width: 1400px) {
            .swal2-input { width: 18rem; }
        }

        .course-item.active {
            background-color: #e9ecef !important;
        }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">
        <div class="dashboard-container">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="admin-info">
                        <div>
                            <h5 class="mb-0">خوش آمدید</h5>
                            <small class="text-muted" id="adminUsername">مدیر سیستم</small>
                        </div>
                    </div>
                    <button class="btn btn-logout" id="logoutBtn">خروج</button>
                </div>
            </div> <!-- Stats -->
            <div class="row">
                <div class="col-md-4">
                    <div class="dashboard-card" style="cursor: pointer;" onclick="showStudentReport()">
                        <div class="stat-box">
                            <h3 id="totalStudents">-</h3>
                            <p>دانشجو</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card" style="cursor: pointer;" onclick="showCourseReport()">
                        <div class="stat-box">
                            <h3 id="totalCourses">-</h3>
                            <p>آزمون</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card" style="cursor: pointer;" onclick="showNextExamReport()">
                        <div class="stat-box">
                            <h3 id="nextExamStudents">-</h3>
                            <p id="nextExamDateTime">بارگذاری...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Update -->
            <div class="dashboard-card no-hover">
                <h4 class="mb-3">به‌روزرسانی دیتابیس</h4>
                <div class="row g-3">
                    <div class="col-md-6">
                        <button class="btn btn-upload w-100" id="uploadWrittenBtn">
                            آزمون‌های کتبی
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button class="btn btn-upload w-100" id="uploadElectronicBtn">
                            آزمون‌های الکترونیکی
                        </button>
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
    <script src="../assets/app/version.js"></script>
    <!-- Device check and SweetAlert moved to dashboard.js -->
    <script src="dashboard.js"></script>
</body>

</html>