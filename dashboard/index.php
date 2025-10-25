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
    <?php echo csrf_meta_tag(); ?>
    <title>پنل مدیریت - نسار</title>
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
        }

        #reportContent h5 {
            background: white;
            padding: 10px 0;
        }

        /* Remove scrollbar from SweetAlert inputs */
        .swal2-input {
            overflow: hidden !important;
            overflow-x: hidden !important;
            overflow-y: hidden !important;
            resize: none !important;
        }

        .swal2-html-container {
            overflow: visible !important;
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
                            <p>تعداد دانشجویان</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card" style="cursor: pointer;" onclick="showCourseReport()">
                        <div class="stat-box">
                            <h3 id="totalCourses">-</h3>
                            <p>تعداد آزمون‌ها</p>
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
    <script>
        // Check admin authentication
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }

        function showLicenseForbidden(message) {
            Swal.fire({
                icon: 'error',
                title: 'خطای لایسنس',
                html: `<div style="text-align:right;line-height:1.9">${message}</div>`,
                confirmButtonText: 'باشه',
                allowOutsideClick: false,
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
        }

        async function handleLicenseGuardResponse(response) {
            if (response.status !== 403) return;
            let message = 'دسترسی به داشبورد به علت مشکل لایسنس امکان‌پذیر نیست.';
            try {
                const payload = await response.clone().json();
                if (payload && payload.message) {
                    message = payload.message;
                }
            } catch (error) {
                // Ignore JSON parsing errors and use fallback text
            }
            showLicenseForbidden(message);
            const err = new Error('license_forbidden');
            err.isLicenseError = true;
            throw err;
        }

        async function guardedFetch(resource, options) {
            const response = await fetch(resource, options);
            await handleLicenseGuardResponse(response);
            return response;
        }

        function checkAuth() {
            const adminSession = getCookie('adminSession');
            if (!adminSession) {
                window.location.href = '../';
                return false;
            }
            try {
                const session = JSON.parse(decodeURIComponent(adminSession));
                if (session.type !== 'admin') {
                    window.location.href = '../';
                    return false;
                }
                document.getElementById('adminUsername').textContent = session.username || 'مدیر سیستم';
                return true;
            } catch (e) {
                window.location.href = '../';
                return false;
            }
        }

        // Logout
        document.getElementById('logoutBtn').addEventListener('click', () => {
            document.cookie = 'adminSession=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
            window.location.href = '../';
        });

        // Load dashboard data
        async function loadDashboardData() {
            try {
                // Get config
                const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
                const config = await configResponse.json();

                // Update admin nickname if available
                if (config.AdminNickName) {
                    document.getElementById('adminUsername').textContent = config.AdminNickName;
                }

                // Get statistics
                const statsResponse = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
                const stats = await statsResponse.json();

                if (!stats.error) {
                    document.getElementById('totalStudents').textContent = stats.totalStudents || 0;
                    document.getElementById('totalCourses').textContent = stats.totalCourses || 0;
                    document.getElementById('nextExamStudents').textContent = stats.nextExamStudents || 0;
                    document.getElementById('nextExamDateTime').textContent = stats.nextExamDateTime || 'آزمونی یافت نشد';
                }
            } catch (error) {
                console.error('Error loading dashboard data:', error);
                if (!error?.isLicenseError) {
                    Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: 'خطا در بارگذاری اطلاعات'
                    });
                }
            }
        }

        // Update footer university name
        async function updateFooterUniversity() {
            try {
                const response = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
                const config = await response.json();
                if (config.University) {
                    document.getElementById('footerText').textContent = `نسار - ${config.University}`;
                }
            } catch (error) {
                if (!error?.isLicenseError) {
                    console.error('Error updating footer:', error);
                }
            }
        }
        updateFooterUniversity();

        // Footer click event
        const copyrightFooter = document.getElementById('copyrightFooter');
        if (copyrightFooter) {
            copyrightFooter.addEventListener('click', async () => {
                const VERSION = '۲.۲.۳';
                function toPersianDigits(num) {
                    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
                    return String(num).replace(/\d/g, d => persianDigits[d]);
                }
                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                let countdownInterval;
                let university = 'دانشگاه پیام نور مرکز بیجار';
                try {
                    const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
                    const config = await configResponse.json();
                    if (config.University) {
                        university = config.University;
                    }
                } catch (error) {
                    if (error?.isLicenseError) {
                        return;
                    }
                    console.error('Error loading config for about modal:', error);
                }

                Swal.fire({
                    title: 'درباره اپلیکیشن',
                    html: `
            <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
              نِسار (نسخه ${VERSION}) یک وب‌اپلیکیشن پیشرفته و مدرن است که با بهره‌گیری از طراحی مبتنی بر تجربه کاربری نوین و سبک گلس‌مورفیسم، به دانشجویان دانشگاه پیام نور این امکان را می‌دهد تا برنامه امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را به‌صورت یکپارچه و متمرکز مشاهده کنند.
              <br>
              این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(university)}</span> و توسط <a href="https://t.me/RealDream" target="_blank" style="color: gold; font-weight: bold; text-decoration: none; border: none; outline: none;">مهدی حسنی</a> توسعه یافته است
            </div>
            <div class="swal2-countdown">
              <span class="swal2-countdown-value">${toPersianDigits(30)}</span>
            </div>
          `,
                    timer: 30000,
                    showConfirmButton: false,
                    allowOutsideClick: true,
                    allowEscapeKey: true,
                    customClass: {
                        popup: 'swal2-rtl swal2-glass'
                    },
                    didOpen: () => {
                        const valueEl = Swal.getHtmlContainer()?.querySelector('.swal2-countdown-value');
                        if (valueEl) {
                            let remaining = 30;
                            countdownInterval = setInterval(() => {
                                remaining--;
                                if (remaining < 0) {
                                    clearInterval(countdownInterval);
                                } else {
                                    valueEl.textContent = toPersianDigits(remaining);
                                }
                            }, 1000);
                        }
                    },
                    willClose: () => {
                        if (countdownInterval) clearInterval(countdownInterval);
                    }
                });
            });
        }

        // Initialize
        if (checkAuth()) {
            loadDashboardData();
        }

        // Get max upload size from server
        let MAX_UPLOAD_SIZE = 128 * 1024 * 1024; // Default 128MB
        let MAX_UPLOAD_SIZE_FORMATTED = '۱۲۸ مگابایت';
        
        async function loadUploadLimit() {
            try {
                const response = await guardedFetch('../API/getUploadLimit.php', { cache: 'no-store' });
                const data = await response.json();
                if (data.maxSize) {
                    MAX_UPLOAD_SIZE = data.maxSize;
                    MAX_UPLOAD_SIZE_FORMATTED = data.maxSizeFormatted;
                }
            } catch (error) {
                console.error('Error loading upload limit:', error);
            }
        }
        loadUploadLimit();

        // Database upload functionality
        async function showUploadModal(examType) {
            const examTypeName = examType === 'K' ? 'کتبی' : 'الکترونیکی';
            
            const { value: file } = await Swal.fire({
                title: `آپلود فایل آزمون‌های ${examTypeName}`,
                html: `
                    <style>
                        .upload-area {
                            border: 3px dashed #28a745;
                            border-radius: 15px;
                            padding: 3rem 2rem;
                            text-align: center;
                            background: linear-gradient(135deg, rgba(40, 167, 69, 0.05) 0%, rgba(40, 167, 69, 0.1) 100%);
                            cursor: pointer;
                            transition: all 0.3s ease;
                            margin-bottom: 1rem;
                        }
                        .upload-area:hover {
                            border-color: #218838;
                            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.15) 100%);
                            transform: translateY(-2px);
                        }
                        .upload-area.dragover {
                            border-color: #1e7e34;
                            background: linear-gradient(135deg, rgba(40, 167, 69, 0.15) 0%, rgba(40, 167, 69, 0.2) 100%);
                            transform: scale(1.02);
                        }
                        .upload-icon {
                            font-size: 4rem;
                            color: #28a745;
                            margin-bottom: 1rem;
                        }
                        .upload-text {
                            font-size: 1.2rem;
                            color: #28a745;
                            font-weight: 600;
                            margin-bottom: 0.5rem;
                        }
                        .upload-hint {
                            font-size: 0.95rem;
                            color: #6c757d;
                            margin-bottom: 1rem;
                        }
                        .file-name-display {
                            background: #e8f5e9;
                            padding: 0.75rem;
                            border-radius: 8px;
                            margin-top: 1rem;
                            color: #28a745;
                            font-weight: 600;
                            display: none;
                        }
                        .browse-btn {
                            background: #28a745;
                            color: white;
                            border: none;
                            padding: 0.75rem 2rem;
                            border-radius: 8px;
                            font-family: 'Vazir', sans-serif;
                            font-size: 1rem;
                            cursor: pointer;
                            transition: all 0.3s ease;
                            font-weight: 600;
                        }
                        .browse-btn:hover {
                            background: #218838;
                            transform: translateY(-2px);
                            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
                        }
                        #databaseFile {
                            display: none;
                        }
                    </style>
                    <div style="text-align: center;">
                        <div class="upload-area" id="uploadArea">
                            <div class="upload-icon">📄</div>
                            <div class="upload-text">فایل اکسل را اینجا بکشید</div>
                            <div class="upload-hint">یا روی دکمه زیر کلیک کنید</div>
                            <button type="button" class="browse-btn" id="browseBtn">انتخاب فایل</button>
                            <div class="file-name-display" id="fileNameDisplay"></div>
                        </div>
                        <p style="font-size: 0.9rem; color: #6c757d; margin: 0;">
                            فرمت‌های مجاز: XLS, XLSX | حداکثر حجم: ${MAX_UPLOAD_SIZE_FORMATTED}
                        </p>
                        <input type="file" id="databaseFile" accept=".xls,.xlsx">
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'آپلود فایل',
                cancelButtonText: 'انصراف',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary',
                    cancelButton: 'btn btn-secondary'
                },
                preConfirm: () => {
                    const fileInput = document.getElementById('databaseFile');
                    if (!fileInput.files || fileInput.files.length === 0) {
                        Swal.showValidationMessage('لطفاً یک فایل انتخاب کنید');
                        return false;
                    }
                    
                    const file = fileInput.files[0];
                    const fileName = file.name.toLowerCase();
                    
                    if (!fileName.endsWith('.xls') && !fileName.endsWith('.xlsx')) {
                        Swal.showValidationMessage('فقط فایل‌های با پسوند XLS و XLSX مجاز هستند');
                        return false;
                    }
                    
                    // Check file size (use server's max upload size)
                    if (file.size > MAX_UPLOAD_SIZE) {
                        Swal.showValidationMessage(`حجم فایل نباید بیشتر از ${MAX_UPLOAD_SIZE_FORMATTED} باشد`);
                        return false;
                    }
                    
                    return file;
                },
                didOpen: () => {
                    const fileInput = document.getElementById('databaseFile');
                    const uploadArea = document.getElementById('uploadArea');
                    const browseBtn = document.getElementById('browseBtn');
                    const fileNameDisplay = document.getElementById('fileNameDisplay');
                    
                    // Browse button click
                    if (browseBtn) {
                        browseBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            fileInput.click();
                        });
                    }
                    
                    // Upload area click
                    if (uploadArea) {
                        uploadArea.addEventListener('click', (e) => {
                            if (e.target !== browseBtn) {
                                fileInput.click();
                            }
                        });
                    }
                    
                    // File input change
                    if (fileInput) {
                        fileInput.addEventListener('change', (e) => {
                            if (e.target.files && e.target.files[0]) {
                                const file = e.target.files[0];
                                fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
                                fileNameDisplay.style.display = 'block';
                            }
                        });
                    }
                    
                    // Drag and drop events
                    if (uploadArea) {
                        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                            uploadArea.addEventListener(eventName, (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                            });
                        });
                        
                        ['dragenter', 'dragover'].forEach(eventName => {
                            uploadArea.addEventListener(eventName, () => {
                                uploadArea.classList.add('dragover');
                            });
                        });
                        
                        ['dragleave', 'drop'].forEach(eventName => {
                            uploadArea.addEventListener(eventName, () => {
                                uploadArea.classList.remove('dragover');
                            });
                        });
                        
                        uploadArea.addEventListener('drop', (e) => {
                            const files = e.dataTransfer.files;
                            if (files.length > 0) {
                                fileInput.files = files;
                                const file = files[0];
                                fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
                                fileNameDisplay.style.display = 'block';
                            }
                        });
                    }
                }
            });

            if (file) {
                await uploadDatabaseFile(file, examType, examTypeName);
            }
        }

        async function uploadDatabaseFile(file, examType, examTypeName) {
            // Show progress modal
            Swal.fire({
                title: 'در حال آپلود',
                html: `
                    <div style="text-align: center; padding: 1rem;">
                        <div style="background: #e0e0e0; border-radius: 10px; overflow: hidden; height: 35px; margin-bottom: 1rem;">
                            <div id="uploadProgressBar" style="background: linear-gradient(90deg, #1a6fa6, #127ead); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem;"></div>
                        </div>
                        <p id="uploadProgressText" style="color: #1a6fa6; font-size: 1.1rem;">در حال آپلود فایل...</p>
                    </div>
                `,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                customClass: {
                    popup: 'swal2-rtl swal2-glass'
                }
            });

            const formData = new FormData();
            formData.append('file', file);
            formData.append('examType', examType);

            try {
                const xhr = new XMLHttpRequest();
                
                // Track upload progress
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        const progressBar = document.getElementById('uploadProgressBar');
                        const progressText = document.getElementById('uploadProgressText');
                        
                        if (progressBar) {
                            progressBar.style.width = percentComplete + '%';
                            progressBar.textContent = percentComplete + '%';
                        }
                        
                        if (progressText) {
                            progressText.textContent = `در حال آپلود... ${percentComplete}%`;
                        }
                    }
                });

                // Handle completion
                xhr.addEventListener('load', async () => {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                await Swal.fire({
                                    icon: 'success',
                                    title: 'موفق',
                                    text: `فایل آزمون‌های ${examTypeName} با موفقیت آپلود شد`,
                                    confirmButtonText: 'باشه',
                                    customClass: {
                                        popup: 'swal2-rtl swal2-glass',
                                        confirmButton: 'btn btn-primary'
                                    }
                                });
                            } else {
                                throw new Error(response.error || 'خطای نامشخص');
                            }
                        } catch (parseError) {
                            throw new Error('خطا در پردازش پاسخ سرور');
                        }
                    } else if (xhr.status === 403) {
                        let errorMessage = 'دسترسی به این عملیات ممکن نیست.';
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse && errorResponse.message) {
                                errorMessage = errorResponse.message;
                            }
                        } catch (e) {
                            // Use default message
                        }
                        showLicenseForbidden(errorMessage);
                    } else {
                        let errorMessage = 'خطا در آپلود فایل';
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse && errorResponse.error) {
                                errorMessage = errorResponse.error;
                            }
                        } catch (e) {
                            // Use default message
                        }
                        
                        await Swal.fire({
                            icon: 'error',
                            title: 'خطا',
                            text: errorMessage,
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });
                    }
                });

                // Handle errors
                xhr.addEventListener('error', async () => {
                    await Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: 'خطا در برقراری ارتباط با سرور',
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass',
                            confirmButton: 'btn btn-primary'
                        }
                    });
                });

                // Get CSRF token
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                // Send request
                xhr.open('POST', '../API/uploadDatabase.php', true);
                if (csrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
                }
                xhr.send(formData);

            } catch (error) {
                console.error('Upload error:', error);
                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: error.message || 'خطا در آپلود فایل',
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
            }
        }

        // Add event listeners to upload buttons
        document.getElementById('uploadWrittenBtn').addEventListener('click', () => {
            showUploadModal('K');
        });

        document.getElementById('uploadElectronicBtn').addEventListener('click', () => {
            showUploadModal('E');
        });

        // Report functions
        function clearReport() {
            document.getElementById('reportCard').style.display = 'none';
            document.getElementById('reportContent').innerHTML = '';
        }

        async function showStudentReport() {
            const { value: studentId } = await Swal.fire({
                title: 'جستجوی دانشجو',
                html: '<input id="studentIdInput" class="swal2-input" placeholder="شماره دانشجویی را وارد کنید" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'جستجو',
                cancelButtonText: 'انصراف',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary',
                    cancelButton: 'btn btn-secondary'
                },
                preConfirm: () => {
                    const input = document.getElementById('studentIdInput');
                    if (!input.value) {
                        Swal.showValidationMessage('لطفاً شماره دانشجویی را وارد کنید');
                        return false;
                    }
                    return input.value;
                }
            });

            if (studentId) {
                try {
                    Swal.fire({
                        title: 'در حال بارگذاری...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const response = await guardedFetch(`../API/getStudentReport.php?student_id=${encodeURIComponent(studentId)}`, { cache: 'no-store' });
                    const data = await response.json();

                    Swal.close();

                    if (data.error) {
                        await Swal.fire({
                            icon: 'error',
                            title: 'خطا',
                            text: data.error,
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });
                        return;
                    }

                    // Display student info and exams
                    const student = data.student;
                    const exams = data.exams;

                    let html = `
                        <div class="mb-4">
                            <h5 class="text-primary mb-3">مشخصات دانشجو</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <tr>
                                        <th style="width: 30%;">شماره دانشجویی</th>
                                        <td>${student.student_id}</td>
                                    </tr>
                                    <tr>
                                        <th>نام و نام خانوادگی</th>
                                        <td>${student.first_name} ${student.last_name}</td>
                                    </tr>
                                    <tr>
                                        <th>کد ملی</th>
                                        <td>${student.national_id}</td>
                                    </tr>
                                    <tr>
                                        <th>مقطع تحصیلی</th>
                                        <td>${student.degree}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    `;

                    if (exams && exams.length > 0) {
                        html += `
                            <div>
                                <h5 class="text-primary mb-3">آزمون‌های دانشجو</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ردیف</th>
                                                <th>کد درس</th>
                                                <th>نام درس</th>
                                                <th>تاریخ</th>
                                                <th>ساعت</th>
                                                <th>شماره صندلی</th>
                                                <th>ساختمان</th>
                                                <th>کلاس</th>
                                                <th>نوع آزمون</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;

                        exams.forEach((exam, index) => {
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${exam.course_code}</td>
                                    <td>${exam.course_name}</td>
                                    <td>${exam.exam_date}</td>
                                    <td>${exam.exam_time}</td>
                                    <td><strong class="text-primary">${exam.seat_number}</strong></td>
                                    <td>${exam.building}</td>
                                    <td>${exam.class_name}</td>
                                    <td><span class="badge bg-${exam.exam_type === 'کتبی' ? 'success' : 'info'}">${exam.exam_type}</span></td>
                                </tr>
                            `;
                        });

                        html += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        html += '<p class="text-muted text-center mt-3">این دانشجو در هیچ آزمونی ثبت‌نام نکرده است.</p>';
                    }

                    document.getElementById('reportContent').innerHTML = html;
                    document.getElementById('reportCard').style.display = 'block';
                    document.getElementById('reportCard').scrollIntoView({ behavior: 'smooth' });

                } catch (error) {
                    console.error('Error:', error);
                    if (!error?.isLicenseError) {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطا',
                            text: 'خطا در دریافت اطلاعات',
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });
                    }
                }
            }
        }

        async function showCourseReport() {
            const { value: courseCode } = await Swal.fire({
                title: 'جستجوی درس',
                html: '<input id="courseCodeInput" class="swal2-input" placeholder="کد درس را وارد کنید" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'جستجو',
                cancelButtonText: 'انصراف',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary',
                    cancelButton: 'btn btn-secondary'
                },
                preConfirm: () => {
                    const input = document.getElementById('courseCodeInput');
                    if (!input.value) {
                        Swal.showValidationMessage('لطفاً کد درس را وارد کنید');
                        return false;
                    }
                    return input.value;
                }
            });

            if (courseCode) {
                try {
                    Swal.fire({
                        title: 'در حال بارگذاری...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    const response = await guardedFetch(`../API/getCourseReport.php?course_code=${encodeURIComponent(courseCode)}`, { cache: 'no-store' });
                    const data = await response.json();

                    Swal.close();

                    if (data.error) {
                        await Swal.fire({
                            icon: 'error',
                            title: 'خطا',
                            text: data.error,
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });
                        return;
                    }

                    // Display course info and students
                    const course = data.course;
                    const students = data.students;

                    let html = `
                        <div class="mb-4">
                            <h5 class="text-primary mb-3">مشخصات درس</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <tr>
                                        <th style="width: 30%;">کد درس</th>
                                        <td>${course.course_code}</td>
                                    </tr>
                                    <tr>
                                        <th>نام درس</th>
                                        <td>${course.course_name}</td>
                                    </tr>
                                    <tr>
                                        <th>تاریخ آزمون</th>
                                        <td>${course.exam_date}</td>
                                    </tr>
                                    <tr>
                                        <th>ساعت آزمون</th>
                                        <td>${course.exam_time}</td>
                                    </tr>
                                    <tr>
                                        <th>نوع آزمون</th>
                                        <td><span class="badge bg-${course.exam_type === 'کتبی' ? 'success' : 'info'}">${course.exam_type}</span></td>
                                    </tr>
                                    <tr>
                                        <th>تعداد دانشجویان</th>
                                        <td><strong class="text-primary">${students.length}</strong> نفر</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    `;

                    if (students && students.length > 0) {
                        html += `
                            <div>
                                <h5 class="text-primary mb-3">لیست دانشجویان</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ردیف</th>
                                                <th>شماره دانشجویی</th>
                                                <th>نام خانوادگی</th>
                                                <th>نام</th>
                                                <th>مقطع</th>
                                                <th>شماره صندلی</th>
                                                <th>ساختمان</th>
                                                <th>کلاس</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;

                        students.forEach((student, index) => {
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${student.student_id}</td>
                                    <td><strong>${student.last_name}</strong></td>
                                    <td>${student.first_name}</td>
                                    <td>${student.degree}</td>
                                    <td><strong class="text-primary">${student.seat_number}</strong></td>
                                    <td>${student.building}</td>
                                    <td>${student.class_name}</td>
                                </tr>
                            `;
                        });

                        html += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        html += '<p class="text-muted text-center mt-3">هیچ دانشجویی در این درس ثبت‌نام نکرده است.</p>';
                    }

                    document.getElementById('reportContent').innerHTML = html;
                    document.getElementById('reportCard').style.display = 'block';
                    document.getElementById('reportCard').scrollIntoView({ behavior: 'smooth' });

                } catch (error) {
                    console.error('Error:', error);
                    if (!error?.isLicenseError) {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطا',
                            text: 'خطا در دریافت اطلاعات',
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });
                    }
                }
            }
        }

        async function showNextExamReport() {
            try {
                // Get exam date and time from the label
                const nextExamDateTimeText = document.getElementById('nextExamDateTime').textContent;
                
                // Check if there's no exam
                if (nextExamDateTimeText === 'بارگذاری...' || nextExamDateTimeText === 'آزمونی یافت نشد') {
                    await Swal.fire({
                        icon: 'info',
                        title: 'اطلاعات',
                        text: 'آزمون بعدی یافت نشد',
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass',
                            confirmButton: 'btn btn-primary'
                        }
                    });
                    return;
                }
                
                // Parse the format: "HH:MM | YYYY/MM/DD"
                const parts = nextExamDateTimeText.split('|').map(s => s.trim());
                if (parts.length !== 2) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: 'فرمت تاریخ و ساعت نامعتبر است',
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass',
                            confirmButton: 'btn btn-primary'
                        }
                    });
                    return;
                }
                
                const examTime = parts[0];
                const examDate = parts[1];

                Swal.fire({
                    title: 'در حال بارگذاری...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
                const data = await response.json();

                Swal.close();

                if (data.error) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: data.error,
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass',
                            confirmButton: 'btn btn-primary'
                        }
                    });
                    return;
                }

                // Display exam info and students
                const courses = data.courses;
                const students = data.students;

                let html = `
                    <div class="mb-4">
                        <h5 class="text-primary mb-3">مشخصات آزمون بعدی</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width: 30%;">تاریخ آزمون</th>
                                    <td>${data.exam_date}</td>
                                </tr>
                                <tr>
                                    <th>ساعت آزمون</th>
                                    <td>${data.exam_time}</td>
                                </tr>
                                <tr>
                                    <th>تعداد دروس</th>
                                    <td><strong class="text-success">${courses.length}</strong> درس</td>
                                </tr>
                                <tr>
                                    <th>تعداد دانشجویان</th>
                                    <td><strong class="text-primary">${students.length}</strong> نفر</td>
                                </tr>
                            </table>
                        </div>
                `;

                // Show course list
                if (courses && courses.length > 0) {
                    html += `
                        <h6 class="text-secondary mb-2 mt-3">لیست دروس این جلسه آزمون:</h6>
                        <ul class="list-group mb-3">
                    `;
                    courses.forEach(course => {
                        html += `
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><strong>${course.course_code}</strong> - ${course.course_name}</span>
                                <div>
                                    <span class="badge bg-secondary me-2">${course.student_count}</span>
                                    <span class="badge bg-${course.exam_type === 'کتبی' ? 'success' : 'info'}">${course.exam_type}</span>
                                </div>
                            </li>
                        `;
                    });
                    html += `
                        </ul>
                    `;
                }

                html += `</div>`;

                if (students && students.length > 0) {
                    html += `
                        <div>
                            <h5 class="text-primary mb-3">لیست دانشجویان</h5>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ردیف</th>
                                            <th>شماره دانشجویی</th>
                                            <th>نام خانوادگی</th>
                                            <th>نام</th>
                                            <th>کد درس</th>
                                            <th>نام درس</th>
                                            <th>شماره صندلی</th>
                                            <th>ساختمان</th>
                                            <th>کلاس</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;

                    students.forEach((student, index) => {
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${student.student_id}</td>
                                <td><strong>${student.last_name}</strong></td>
                                <td>${student.first_name}</td>
                                <td>${student.course_code}</td>
                                <td>${student.course_name}</td>
                                <td><strong class="text-primary">${student.seat_number}</strong></td>
                                <td>${student.building}</td>
                                <td>${student.class_name}</td>
                            </tr>
                        `;
                    });

                    html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                } else {
                    html += '<p class="text-muted text-center mt-3">هیچ دانشجویی در این آزمون ثبت‌نام نکرده است.</p>';
                }

                document.getElementById('reportContent').innerHTML = html;
                document.getElementById('reportCard').style.display = 'block';
                document.getElementById('reportCard').scrollIntoView({ behavior: 'smooth' });

            } catch (error) {
                console.error('Error:', error);
                if (!error?.isLicenseError) {
                    Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: 'خطا در دریافت اطلاعات',
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass',
                            confirmButton: 'btn btn-primary'
                        }
                    });
                }
            }
        }
    </script>
</body>

</html>