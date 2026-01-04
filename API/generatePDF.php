<?php
// Production: errors are logged, not displayed
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    die('License Error: ' . ($licenseStatus['message'] ?? 'Invalid License'));
}

// Session check
$session = privileged_session_require($pdo);

$reportType = $_GET['report_type'] ?? '';
$examDate   = $_GET['exam_date'] ?? '';
$examTime   = $_GET['exam_time'] ?? '';

if (empty($reportType)) {
    die('Missing report type');
}

if ($reportType !== 'proctor_notice' && $reportType !== 'exam_booklet' && $reportType !== 'seat_labels' && (empty($examDate) || empty($examTime))) {
    die('Missing parameters');
}

// Helper for Persian Digits
function toPersianDigits($str)
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, (string)$str);
}

// Helper for English Digits (for logic)
function toEnglishDigits($str)
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian, $english, (string)$str);
}

// Fetch Config
$config = [];
try {
    $stmt = $pdo->query("SELECT ConfigName, ConfigValue FROM Config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }
} catch (Exception $e) {
}

// Setup mPDF
$fontDir       = __DIR__ . '/../assets/fonts/vazir/Farsi-Digits';
$defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$fontDirs      = $defaultConfig['fontDir'];

$defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
$fontData          = $defaultFontConfig['fontdata'];

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'orientation' => 'P',
    'tempDir' => __DIR__ . '/../temp',
    'fontDir' => array_merge($fontDirs, [
        $fontDir
    ]),
    'fontdata' => $fontData + [
        'vazir' => [
            'R' => 'Vazir-Regular-FD.ttf',
            'B' => 'Vazir-Bold-FD.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ]
    ],
    'default_font' => 'vazir'
]);

$mpdf->SetDirectionality('rtl');

try {
    if ($reportType === 'session') {
        generateSessionReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'seat') {
        generateSeatNumbersReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'secretary') {
        generateSecretaryReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'reproduction') {
        generateReproductionReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'location') {
        generateLocationReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'location_labels') {
        generateLocationLabels($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'descriptive') {
        generateDescriptiveLabels($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'test_labels') {
        generateTestLabels($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'daily_test_labels') {
        generateDailyTestLabels($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'proctor_notice') {
        // Parse optional proctor_ids parameter for filtered notice generation
        $filterProctorIds = null;
        if (!empty($_GET['proctor_ids'])) {
            $idsStr = trim($_GET['proctor_ids']);
            if ($idsStr !== '') {
                $filterProctorIds = array_map('intval', explode(',', $idsStr));
                $filterProctorIds = array_filter($filterProctorIds, function ($id) {
                    return $id > 0;
                });
                if (empty($filterProctorIds)) {
                    $filterProctorIds = null;
                }
            }
        }
        generateProctorNotices($pdo, $mpdf, $config, $filterProctorIds);
    } elseif ($reportType === 'attendance_sheet') {
        generateAttendanceSheet($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'session_summary') {
        generateSessionSummaryReport($pdo, $mpdf, $examDate, $examTime, $config);
    } elseif ($reportType === 'exam_booklet') {
        $bookletFilter = $_GET['filter'] ?? 'all';
        generateExamBookletReport($pdo, $mpdf, $config, $bookletFilter);
    } elseif ($reportType === 'seat_labels') {
        generateSeatLabelsReport($pdo, $mpdf, $config);
    } else {
        die('Unknown report type');
    }
} catch (Throwable $e) {
    $logFile = __DIR__ . '/../temp/generatePDF_error.log';
    $msg     = date('c') . " - Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n";
    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "Internal Server Error: " . $e->getMessage();
    exit;
}

$filename = $reportType . '_' . date('Y-m-d_H-i-s') . '.pdf';
if (!empty($examDate)) {
    $filename = $reportType . '_' . str_replace(['/', '\\'], '-', $examDate);
    if (!empty($examTime)) {
        $filename .= '_' . str_replace(':', '-', $examTime);
    }
    $filename .= '.pdf';
}

$outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
$mpdf->Output($filename, $outputMode);

function generateSessionReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Check if location-based report mode is enabled
    $locationMode = isset($config['ReproductionReportMode']) && strtolower($config['ReproductionReportMode']) === 'location';

    if ($locationMode) {
        // Generate location-based session reports
        generateSessionReportByLocation($pdo, $mpdf, $examDate, $examTime, $config);
        return;
    }

    // Default: course-based report (original logic)
    // Fetch Courses
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            MAX(es.exam_type) AS exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        die('No courses found for this session.');
    }

    // Sort logic (Electronic first)
    usort($courses, function ($a, $b) {
        $typeA = $a['exam_type'] ?? '';
        $typeB = $b['exam_type'] ?? '';
        if ($typeA === $typeB) {
            return (int)$a['course_code'] - (int)$b['course_code'];
        }
        if ($typeA === 'الکترونیکی')
            return -1;
        if ($typeB === 'الکترونیکی')
            return 1;
        return strcmp($typeA, $typeB);
    });

    // Calculate answer sheet counts for summary table
    $testCount               = 0;
    $descriptiveCount        = 0;
    $testDescriptiveCount    = 0;
    $electronicTestCount     = 0;
    $electronicTestDescCount = 0;

    foreach ($courses as $course) {
        $ct           = $course['course_type'] ?? '';
        $count        = (int)($course['student_count'] ?? 0);
        $isElectronic = ($course['exam_type'] ?? '') === 'الکترونیکی';

        if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
            $testDescriptiveCount += $count;
            if ($isElectronic) {
                $electronicTestDescCount += $count;
            }
        } elseif (stripos($ct, 'تستی') !== false) {
            $testCount += $count;
            if ($isElectronic) {
                $electronicTestCount += $count;
            }
        } elseif (stripos($ct, 'تشریحی') !== false) {
            $descriptiveCount += $count;
        }
    }

    // Total answer sheets (subtract electronic exams from test sheets - they don't have physical answer sheets)
    $totalTestSheets        = $testCount + $testDescriptiveCount - $electronicTestCount - $electronicTestDescCount;
    $totalDescriptiveSheets = $descriptiveCount + $testDescriptiveCount;

    // Calculate Semester/Year
    $semesterLabel = "نامشخص";
    $partsDate     = explode('/', toEnglishDigits($examDate));
    $year          = isset($partsDate[0]) ? (int)$partsDate[0] : 0;
    $month         = isset($partsDate[1]) ? (int)$partsDate[1] : 0;

    if (in_array($month, [9, 10]))
        $semesterLabel = "نیمسال اول";
    elseif (in_array($month, [2, 3]))
        $semesterLabel = "نیمسال دوم";
    elseif (in_array($month, [5, 6]))
        $semesterLabel = "دوره تابستان";
    else {
        if ($month >= 7 && $month <= 12)
            $semesterLabel = "نیمسال اول";
        elseif ($month >= 1 && $month <= 4)
            $semesterLabel = "نیمسال دوم";
    }

    if ($semesterLabel === "نیمسال اول") {
        $acadStart = $year;
        $acadEnd   = $year + 1;
    } else {
        $acadStart = $year - 1;
        $acadEnd   = $year;
    }
    // Swapped order as per user request (Big - Small)
    $acadYearStr = toPersianDigits($acadEnd) . '-' . toPersianDigits($acadStart);

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));
    $bossName   = $config['BossNickName'] ?? '________________';
    $headName   = $config['HeadOfEDU'] ?? '________________';
    $chairName  = $config['Chairman'] ?? '________________';

    // Pagination - ensure last page has at least some courses, not just footer
    $perPage      = 15;
    $totalCourses = count($courses);

    // Calculate if last page would be empty or have very few courses
    // Footer (summary + signatures) needs about 8 rows worth of space
    // We want at least 2 courses on the last page
    $lastPageItems = $totalCourses % $perPage;
    if ($lastPageItems === 0 && $totalCourses > $perPage) {
        // All pages are full, last page would have no courses
        $perPage = 15; // Reduce to push some courses to last page
    } elseif ($lastPageItems > 0 && $lastPageItems < 3 && $totalCourses > $perPage) {
        // Last page has very few items, redistribute
        $perPage = 15;
    }

    $chunks     = array_chunk($courses, $perPage);
    $totalPages = count($chunks);

    $html = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { width: 100%; border-bottom: 1px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header-table { width: 100%; }
        .logo { width: 80px; }
        .title { font-size: 16pt; font-weight: bold; padding-bottom: 20px; margin-bottom: 20px; }
        .meta { text-align: right; margin-bottom: 10px; font-size: 10pt; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; table-layout: fixed; }
        .courses-table th { background-color: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; white-space: nowrap; overflow: hidden; }
        .courses-table td { border: 1px solid #ccc; padding: 8px 5px; text-align: center; white-space: nowrap; overflow: hidden; }
        .courses-table td.name { text-align: right; }
        .courses-table tr.electronic { background-color: #e8f5e9; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 15px; margin-bottom: 15px; }
        .summary-table th, .summary-table td { border: 1px solid #ccc; padding: 6px; text-align: center; }
        .summary-table th { background-color: #efefef; font-weight: bold; }
        .footer-signs { position: fixed; bottom: 8mm; left: 0; right: 0; width: 100%; }
        .sign-row { width: 100%; margin-bottom: 8px; }
        .sign-label { text-align: right; float: right; width: 80%; }
        .sign-place { text-align: left; float: left; width: 20%; }
        .page-footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9pt; background-color: #444; color: #fff; padding: 5px; }
    </style>
    ';

    foreach ($chunks as $index => $chunk) {
        if ($index > 0)
            $mpdf->AddPage();

        $pageHtml = $html;

        // Header
        $pageHtml .= '
        <div class="header">
            <table class="header-table">
                <tr>
                    <td style="width: 20%; text-align: center; border: none;">
                        <img src="../assets/app/Pnulogo.png" class="logo"><br>
                        <span style="font-size: 9pt; font-weight: bold;">مرکز سنجش و آزمون</span>
                    </td>
                    <td style="width: 60%; text-align: center; border: none;">
                        <div class="title">صورتجلسه آزمون</div>
                        <br>
                        <div style="font-size: 12pt;">' . $university . '</div>
                    </td>
                    <td style="width: 20%; border: none;"></td>
                </tr>
            </table>
        </div>';

        $pageHtml .= '<div class="meta">آزمون دروس زیر در ' . $semesterLabel . ' سالتحصیلی ' . $acadYearStr . ' با حضور امضاء کنندگان زیر در ساعت ' . toPersianDigits($examTime) . ' مورخ ' . toPersianDigits($examDate) . ' شروع گردید. (نمونه سوال ضمیمه می باشد)</div>';

        // Table
        $pageHtml .= '<table class="courses-table">
            <thead>
                <tr>
                    <th style="width: 5%;">ردیف</th>
                    <th style="width: 15%;">نوع درس</th>
                    <th style="width: 10%;">کد درس</th>
                    <th style="width: 45%;">نام درس</th>
                    <th style="width: 10%;">تعداد</th>
                    <th style="width: 15%;">حاضر / غایب</th>
                </tr>
            </thead>
            <tbody>';

        $startRow   = ($index * $perPage) + 1;
        $isLastPage = ($index === $totalPages - 1);

        foreach ($chunk as $i => $course) {
            $rowNum        = $startRow + $i;
            $count         = $course['student_count'] ?? 0;
            $isElectronic  = ($course['exam_type'] ?? '') === 'الکترونیکی';
            $rowClass      = $isElectronic ? ' class="electronic"' : '';
            $pageHtml     .= '<tr' . $rowClass . '>
                <td>' . toPersianDigits($rowNum) . '</td>
                <td>' . ($course['course_type'] ?? '') . '</td>
                <td>' . toPersianDigits($course['course_code']) . '</td>
                <td class="name">' . ($course['course_name']) . '</td>
                <td>' . toPersianDigits($count) . '</td>
                <td> ___ / ___ </td>
            </tr>';
        }
        $pageHtml .= '</tbody></table>';

        // Summary table and signatures only on last page
        if ($isLastPage) {
            // Answer sheet summary table
            $pageHtml .= '
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 40%;"></th>
                        <th style="width: 20%;">تعداد کل</th>
                        <th style="width: 40%;">مجموع حاضرین / مجموع غایبین</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>پاسخنامه‌های تستی</td>
                        <td>' . toPersianDigits($totalTestSheets) . '</td>
                        <td>___________ / ___________</td>
                    </tr>
                    <tr>
                        <td>پاسخنامه‌های تشریحی</td>
                        <td>' . toPersianDigits($totalDescriptiveSheets) . '</td>
                        <td>___________ / ___________</td>
                    </tr>
                </tbody>
            </table>';
        }

        // Footer Signatures
        $pageHtml .= '<div class="footer-signs">
            <div style="border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 8px; text-align: center; font-size: 9pt;">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>
            
            <table style="width: 100%; border: none;">
                <tr>
                    <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ' . $bossName . '</td>
                    <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس اداره آموزش: ' . $headName . '</td>
                    <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی مسئول جلسه: ' . $chairName . '</td>
                    <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی ناظران/مراقبان جلسه:</td>
                    <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</td>
                    <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                </tr>
            </table>
        </div>';

        // Page Number
        $mpdf->SetHTMLFooter('<div style="background-color: #444; color: #fff; text-align: center; padding: 5px; font-size: 9pt;">صفحه ' . toPersianDigits($index + 1) . ' از ' . toPersianDigits($totalPages) . '</div>');

        $mpdf->WriteHTML($pageHtml);
    }
}

/**
 * Generate session report grouped by building
 * Each building gets its own separate session report with the building name shown under university
 */
function generateSessionReportByLocation($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch all exam seats with course info for this session
    $stmt = $pdo->prepare("
        SELECT 
            es.course_code,
            es.building,
            es.class_name,
            es.student_id,
            c.course_name,
            c.course_type,
            es.exam_type
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
    ");
    $stmt->execute([$examDate, $examTime]);
    $allSeats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allSeats)) {
        die('No exam seats found for this session.');
    }

    // Group by building only (not by class)
    $buildings = [];
    foreach ($allSeats as $seat) {
        $building = trim($seat['building'] ?? '') ?: 'بدون ساختمان';

        if (!isset($buildings[$building])) {
            $buildings[$building] = [
                'building' => $building,
                'courses' => []
            ];
        }

        $courseCode = $seat['course_code'];
        if (!isset($buildings[$building]['courses'][$courseCode])) {
            $buildings[$building]['courses'][$courseCode] = [
                'course_code' => $courseCode,
                'course_name' => $seat['course_name'],
                'course_type' => $seat['course_type'],
                'exam_type' => $seat['exam_type'],
                'student_count' => 0
            ];
        }
        $buildings[$building]['courses'][$courseCode]['student_count']++;
    }

    // Sort buildings by name
    uksort($buildings, 'strcmp');

    // Calculate Semester/Year
    $semesterLabel = "نامشخص";
    $partsDate     = explode('/', toEnglishDigits($examDate));
    $year          = isset($partsDate[0]) ? (int)$partsDate[0] : 0;
    $month         = isset($partsDate[1]) ? (int)$partsDate[1] : 0;

    if (in_array($month, [9, 10]))
        $semesterLabel = "نیمسال اول";
    elseif (in_array($month, [2, 3]))
        $semesterLabel = "نیمسال دوم";
    elseif (in_array($month, [5, 6]))
        $semesterLabel = "دوره تابستان";
    else {
        if ($month >= 7 && $month <= 12)
            $semesterLabel = "نیمسال اول";
        elseif ($month >= 1 && $month <= 4)
            $semesterLabel = "نیمسال دوم";
    }

    if ($semesterLabel === "نیمسال اول") {
        $acadStart = $year;
        $acadEnd   = $year + 1;
    } else {
        $acadStart = $year - 1;
        $acadEnd   = $year;
    }
    $acadYearStr = toPersianDigits($acadEnd) . '-' . toPersianDigits($acadStart);

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));
    $bossName   = $config['BossNickName'] ?? '________________';
    $headName   = $config['HeadOfEDU'] ?? '________________';
    $chairName  = $config['Chairman'] ?? '________________';

    $baseStyle = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { width: 100%; border-bottom: 1px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header-table { width: 100%; }
        .logo { width: 80px; }
        .title { font-size: 16pt; font-weight: bold; margin-bottom: 8px; }
        .university-name { font-size: 12pt; margin-bottom: 6px; }
        .location-name { font-size: 10pt; font-weight: bold; color: #333; margin-top: 4px; }
        .meta { text-align: right; margin-bottom: 10px; font-size: 10pt; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; table-layout: fixed; }
        .courses-table th { background-color: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; white-space: nowrap; overflow: hidden; }
        .courses-table td { border: 1px solid #ccc; padding: 8px 5px; text-align: center; white-space: nowrap; overflow: hidden; }
        .courses-table td.name { text-align: right; }
        .courses-table tr.electronic { background-color: #e8f5e9; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 15px; margin-bottom: 15px; }
        .summary-table th, .summary-table td { border: 1px solid #ccc; padding: 6px; text-align: center; }
        .summary-table th { background-color: #efefef; font-weight: bold; }
        .footer-signs { position: fixed; bottom: 8mm; left: 0; right: 0; width: 100%; }
        .page-footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9pt; background-color: #444; color: #fff; padding: 5px; }
    </style>
    ';

    $locationIndex  = 0;
    $totalLocations = count($buildings);

    foreach ($buildings as $buildingName => $buildingData) {
        $locationIndex++;
        $locationLabel = $buildingData['building'];

        // Convert courses array to indexed array and sort
        $courses = array_values($buildingData['courses']);

        // Sort logic (Electronic first)
        usort($courses, function ($a, $b) {
            $typeA = $a['exam_type'] ?? '';
            $typeB = $b['exam_type'] ?? '';
            if ($typeA === $typeB) {
                return (int)$a['course_code'] - (int)$b['course_code'];
            }
            if ($typeA === 'الکترونیکی')
                return -1;
            if ($typeB === 'الکترونیکی')
                return 1;
            return strcmp($typeA, $typeB);
        });

        // Calculate answer sheet counts for this location
        $testCount               = 0;
        $descriptiveCount        = 0;
        $testDescriptiveCount    = 0;
        $electronicTestCount     = 0;
        $electronicTestDescCount = 0;

        foreach ($courses as $course) {
            $ct           = $course['course_type'] ?? '';
            $count        = (int)($course['student_count'] ?? 0);
            $isElectronic = ($course['exam_type'] ?? '') === 'الکترونیکی';

            if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
                $testDescriptiveCount += $count;
                if ($isElectronic) {
                    $electronicTestDescCount += $count;
                }
            } elseif (stripos($ct, 'تستی') !== false) {
                $testCount += $count;
                if ($isElectronic) {
                    $electronicTestCount += $count;
                }
            } elseif (stripos($ct, 'تشریحی') !== false) {
                $descriptiveCount += $count;
            }
        }

        // Total answer sheets for this location (subtract electronic exams - they don't have physical answer sheets)
        $totalTestSheets        = $testCount + $testDescriptiveCount - $electronicTestCount - $electronicTestDescCount;
        $totalDescriptiveSheets = $descriptiveCount + $testDescriptiveCount;

        // Pagination for this location - ensure last page has courses
        $perPage      = 15;
        $totalCourses = count($courses);

        $lastPageItems = $totalCourses % $perPage;
        if ($lastPageItems === 0 && $totalCourses > $perPage) {
            $perPage = 15;
        } elseif ($lastPageItems > 0 && $lastPageItems < 3 && $totalCourses > $perPage) {
            $perPage = 15;
        }

        $chunks     = array_chunk($courses, $perPage);
        $totalPages = count($chunks);

        foreach ($chunks as $pageIndex => $chunk) {
            if ($locationIndex > 1 || $pageIndex > 0)
                $mpdf->AddPage();

            $pageHtml = $baseStyle;

            // Header with location name
            $pageHtml .= '
            <div class="header">
                <table class="header-table">
                    <tr>
                        <td style="width: 20%; text-align: center; border: none;">
                            <img src="../assets/app/Pnulogo.png" class="logo"><br>
                            <span style="font-size: 9pt; font-weight: bold;">مرکز سنجش و آزمون</span>
                        </td>
                        <td style="width: 60%; text-align: center; border: none;">
                            <div class="title">صورتجلسه آزمون</div>
                            <div class="university-name">' . $university . '</div>
                            <div class="location-name">' . $locationLabel . '</div>
                        </td>
                        <td style="width: 20%; border: none;"></td>
                    </tr>
                </table>
            </div>';

            $pageHtml .= '<div class="meta">آزمون دروس زیر در ' . $semesterLabel . ' سالتحصیلی ' . $acadYearStr . ' با حضور امضاء کنندگان زیر در ساعت ' . toPersianDigits($examTime) . ' مورخ ' . toPersianDigits($examDate) . ' شروع گردید. (نمونه سوال ضمیمه می باشد)</div>';

            // Table
            $pageHtml .= '<table class="courses-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">ردیف</th>
                        <th style="width: 15%;">نوع درس</th>
                        <th style="width: 10%;">کد درس</th>
                        <th style="width: 45%;">نام درس</th>
                        <th style="width: 10%;">تعداد</th>
                        <th style="width: 15%;">حاضر / غایب</th>
                    </tr>
                </thead>
                <tbody>';

            $startRow   = ($pageIndex * $perPage) + 1;
            $isLastPage = ($pageIndex === $totalPages - 1);

            foreach ($chunk as $i => $course) {
                $rowNum        = $startRow + $i;
                $count         = $course['student_count'] ?? 0;
                $isElectronic  = ($course['exam_type'] ?? '') === 'الکترونیکی';
                $rowClass      = $isElectronic ? ' class="electronic"' : '';
                $pageHtml     .= '<tr' . $rowClass . '>
                    <td>' . toPersianDigits($rowNum) . '</td>
                    <td>' . ($course['course_type'] ?? '') . '</td>
                    <td>' . toPersianDigits($course['course_code']) . '</td>
                    <td class="name">' . ($course['course_name']) . '</td>
                    <td>' . toPersianDigits($count) . '</td>
                    <td> ___ / ___ </td>
                </tr>';
            }
            $pageHtml .= '</tbody></table>';

            // Summary table and signatures only on last page of this location
            if ($isLastPage) {
                // Answer sheet summary table
                $pageHtml .= '
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;"></th>
                            <th style="width: 20%;">تعداد کل</th>
                            <th style="width: 40%;">مجموع حاضرین / مجموع غایبین</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>پاسخنامه‌های تستی</td>
                            <td>' . toPersianDigits($totalTestSheets) . '</td>
                            <td>___________ / ___________</td>
                        </tr>
                        <tr>
                            <td>پاسخنامه‌های تشریحی</td>
                            <td>' . toPersianDigits($totalDescriptiveSheets) . '</td>
                            <td>___________ / ___________</td>
                        </tr>
                    </tbody>
                </table>';
            }

            // Footer Signatures
            $pageHtml .= '<div class="footer-signs">
                <div style="border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 8px; text-align: center; font-size: 9pt;">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>
                
                <table style="width: 100%; border: none;">
                    <tr>
                        <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ' . $bossName . '</td>
                        <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                    </tr>
                    <tr>
                        <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس اداره آموزش: ' . $headName . '</td>
                        <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                    </tr>
                    <tr>
                        <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی مسئول جلسه: ' . $chairName . '</td>
                        <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                    </tr>
                    <tr>
                        <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی ناظران/مراقبان جلسه:</td>
                        <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                    </tr>
                    <tr>
                        <td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</td>
                        <td style="border: none; text-align: left; padding: 6px;">امضاء</td>
                    </tr>
                </table>
            </div>';

            // Page Number with location info
            $footerText = 'مکان ' . toPersianDigits($locationIndex) . ' از ' . toPersianDigits($totalLocations);
            if ($totalPages > 1) {
                $footerText .= ' | صفحه ' . toPersianDigits($pageIndex + 1) . ' از ' . toPersianDigits($totalPages);
            }
            $mpdf->SetHTMLFooter('<div style="background-color: #444; color: #fff; text-align: center; padding: 5px; font-size: 9pt;">' . $footerText . '</div>');

            $mpdf->WriteHTML($pageHtml);
        }
    }
}

function generateSeatNumbersReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Check MultiExamMode config
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';

    // Get new sorting and grouping settings
    $seatReportSortBy           = isset($config['SeatReportSortBy']) ? strtolower(trim($config['SeatReportSortBy'])) : 'last_name';
    $seatReportSeparateBuilding = isset($config['SeatReportSeparateBuilding']) && strtoupper($config['SeatReportSeparateBuilding']) === 'YES';
    $seatReportGroupByCourse    = isset($config['SeatReportGroupByCourse']) && strtoupper($config['SeatReportGroupByCourse']) === 'YES';

    // Legacy fallback: if old GroupByCourse is set and new settings are not, use old setting
    if (!isset($config['SeatReportGroupByCourse']) && isset($config['GroupByCourse']) && strtoupper($config['GroupByCourse']) === 'YES') {
        $seatReportGroupByCourse = true;
    }

    // Validate sort option
    $validSortOptions = ['seat_number', 'student_id', 'last_name'];
    if (!in_array($seatReportSortBy, $validSortOptions)) {
        $seatReportSortBy = 'last_name';
    }

    // Fetch Students with Seat Info
    $stmt = $pdo->prepare("SELECT course_code FROM courses WHERE exam_date = ? AND exam_time = ?");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($courses)) {
        die('No courses found.');
    }

    $placeholders = str_repeat('?,', count($courses) - 1) . '?';

    // Build ORDER BY clause based on settings
    // Priority: 1. Building (if separate), 2. Course (if group by course), 3. Sort field
    $orderParts = [];
    if ($seatReportSeparateBuilding) {
        $orderParts[] = 'es.building';
    }
    if ($seatReportGroupByCourse) {
        $orderParts[] = 'c.course_name';
    }
    // Add the main sort field
    switch ($seatReportSortBy) {
        case 'seat_number':
            $orderParts[] = 'CAST(es.seat_number AS UNSIGNED)';
            break;
        case 'student_id':
            $orderParts[] = 's.student_id';
            break;
        case 'last_name':
        default:
            $orderParts[] = 's.last_name';
            $orderParts[] = 's.first_name';
            break;
    }
    $orderBy = implode(', ', $orderParts);

    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            es.seat_number,
            es.building,
            es.class_name,
            c.course_name
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
        ORDER BY $orderBy
    ");
    $stmt->execute($courses);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        die('No students found.');
    }

    // If MultiExamMode is enabled, find multi-exam students and their primary seat
    $primarySeats = [];
    if ($multiExamModeEnabled) {
        $studentExams = [];
        foreach ($students as $s) {
            $sid = $s['student_id'];
            if (!isset($studentExams[$sid])) {
                $studentExams[$sid] = [];
            }
            $raw = $s['seat_number'];
            if (preg_match('/(\d+)/', $raw, $m)) {
                $studentExams[$sid][] = (int)$m[1];
            }
        }
        foreach ($studentExams as $sid => $seats) {
            if (count($seats) > 1) {
                $primarySeats[$sid] = min($seats);
            }
        }
    }

    // Helper function to get display seat number
    $getDisplaySeat = function ($student) use ($primarySeats, $multiExamModeEnabled) {
        $sid = $student['student_id'];
        if ($multiExamModeEnabled && isset($primarySeats[$sid])) {
            return $primarySeats[$sid];
        }
        return $student['seat_number'];
    };

    // Create new mPDF instance for A4 Landscape
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 18,
        'tempDir' => __DIR__ . '/../temp',
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    $htmlStyle = '
    <style>
        body { font-family: vazir; font-size: 9pt; }
        .page-header { 
            text-align: center; 
            font-size: 14pt; 
            font-weight: bold; 
            margin-bottom: 5px; 
            padding-bottom: 5px;
            border-bottom: 2px solid #000;
        }
        .session-info {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 8px;
            color: #333;
        }
        .building-header {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 6px;
            color: #000;
            background: #f0f0f0;
            padding: 4px;
            border-radius: 4px;
        }
        .main-container { width: 100%; }
        .two-col-table { 
            width: 100%; 
            border-collapse: collapse; 
            table-layout: fixed;
        }
        .two-col-table > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .two-col-table > tbody > tr > td:first-child {
            padding-left: 8px;
            border-left: 3px solid #333;
        }
        .two-col-table > tbody > tr > td:last-child {
            padding-right: 8px;
        }
        .col-table { 
            width: 100%; 
            border-collapse: collapse; 
            table-layout: fixed; 
            font-size: 9pt; 
        }
        .col-table th { 
            background-color: #333; 
            color: #fff;
            border: 1px solid #333; 
            padding: 6px 4px; 
            font-weight: bold; 
            text-align: center; 
            font-size: 9pt; 
        }
        .col-table td { 
            border: 1px solid #666; 
            padding: 5px 4px; 
            text-align: right; 
            font-size: 9pt; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            vertical-align: middle;
        }
        .col-table tr:nth-child(even) td {
            background-color: #f5f5f5;
        }
        .col-table td.seat-col { 
            text-align: center; 
            width: 18%; 
            font-weight: bold;
            font-size: 11pt;
            background-color: #e8f4e8 !important;
        }
        .id-col { width: 12%; text-align: center; vertical-align: middle; }
        .col-table th.id-col, .col-table td.id-col { text-align: center; vertical-align: middle; }
        .name-col { width: 33%; }
        .course-col { width: 37%; }
    </style>';

    // If separate by building, group students and render each building separately
    if ($seatReportSeparateBuilding) {
        // Group students by building
        $buildingGroups = [];
        foreach ($students as $s) {
            $building = trim($s['building'] ?? '') ?: 'بدون ساختمان';
            if (!isset($buildingGroups[$building])) {
                $buildingGroups[$building] = [];
            }
            $buildingGroups[$building][] = $s;
        }

        $perPage          = 44;
        $isFirstPage      = true;
        $globalPageNum    = 0;
        $totalGlobalPages = 0;

        // Calculate total pages across all buildings
        foreach ($buildingGroups as $buildingStudents) {
            $totalGlobalPages += count(array_chunk($buildingStudents, $perPage));
        }
        // Add 1 for Kroki page
        $totalGlobalPages += 1;

        foreach ($buildingGroups as $building => $buildingStudents) {
            $chunks                = array_chunk($buildingStudents, $perPage);
            $buildingTotalPages    = count($chunks);
            $buildingTotalStudents = count($buildingStudents);

            foreach ($chunks as $pageIndex => $chunk) {
                $globalPageNum++;

                if (!$isFirstPage) {
                    $mpdf->AddPage('L');
                }
                $isFirstPage = false;

                // Set footer for this page (building-specific)
                $startNum = ($pageIndex * $perPage) + 1;
                $endNum   = min(($pageIndex + 1) * $perPage, $buildingTotalStudents);
                // Collect unique class names present on this page
                $pageClasses = [];
                foreach ($chunk as $stu) {
                    $cn = trim($stu['class_name'] ?? '');
                    if ($cn !== '') {
                        $pageClasses[$cn] = true;
                    }
                }
                $classList = array_keys($pageClasses);
                $classStr  = '';
                if (!empty($classList)) {
                    $classStr = implode('، ', array_map('htmlspecialchars', $classList));
                }

                $footerHtml = '<div style="text-align:center;font-size:9pt;color:#333;border-top:1px solid #999;padding-top:3px;">';
                // Building first, then class list (no prefix)
                $footerHtml .= '<strong>' . htmlspecialchars($building) . '</strong>';
                if ($classStr !== '') {
                    $footerHtml .= ' | ' . $classStr;
                }
                $footerHtml .= ' | ';
                $footerHtml .= 'ردیف ' . toPersianDigits($startNum) . ' تا ' . toPersianDigits($endNum);
                $footerHtml .= ' از ' . toPersianDigits($buildingTotalStudents) . ' نفر';
                $footerHtml .= ' | صفحه ' . toPersianDigits($pageIndex + 1) . ' از ' . toPersianDigits($buildingTotalPages);
                $footerHtml .= ' (کل: ' . toPersianDigits($globalPageNum) . ' از ' . toPersianDigits($totalGlobalPages) . ')';
                $footerHtml .= '</div>';
                $mpdf->SetHTMLFooter($footerHtml);

                $half = ceil(count($chunk) / 2);
                $col1 = array_slice($chunk, 0, $half);
                $col2 = array_slice($chunk, $half);

                $html  = $htmlStyle;
                $html .= '<div class="page-header">فهرست شماره صندلی دانشجویان</div>';
                $html .= '<div class="session-info">تاریخ: ' . toPersianDigits($examDate) . ' | ساعت: ' . toPersianDigits($examTime) . '</div>';
                $html .= '<div class="building-header">ساختمان: ' . $building . '</div>';

                $html .= '<div class="main-container">';
                $html .= '<table class="two-col-table"><tr>';

                // Column 1
                $html .= '<td style="vertical-align: top;">';
                $html .= '<table class="col-table">';
                $html .= '<thead><tr><th class="id-col">شماره دانشجویی</th><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($col1 as $s) {
                    $displaySeat  = $getDisplaySeat($s);
                    $html        .= '<tr>';
                    $html        .= '<td class="id-col" style="white-space: nowrap;">' . toPersianDigits($s['student_id']) . '</td>';
                    $html        .= '<td class="name-col" style="white-space: nowrap;">' . htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) . '</td>';
                    $html        .= '<td class="course-col">' . htmlspecialchars($s['course_name']) . '</td>';
                    $html        .= '<td class="seat-col">' . toPersianDigits($displaySeat) . '</td>';
                    $html        .= '</tr>';
                }
                $html .= '</tbody></table></td>';

                // Column 2
                $html .= '<td style="vertical-align: top;">';
                $html .= '<table class="col-table">';
                $html .= '<thead><tr><th class="id-col">شماره دانشجویی</th><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($col2 as $s) {
                    $displaySeat  = $getDisplaySeat($s);
                    $html        .= '<tr>';
                    $html        .= '<td class="id-col" style="white-space: nowrap;">' . toPersianDigits($s['student_id']) . '</td>';
                    $html        .= '<td class="name-col" style="white-space: nowrap;">' . htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) . '</td>';
                    $html        .= '<td class="course-col">' . htmlspecialchars($s['course_name']) . '</td>';
                    $html        .= '<td class="seat-col">' . toPersianDigits($displaySeat) . '</td>';
                    $html        .= '</tr>';
                }
                $html .= '</tbody></table></td>';

                $html .= '</tr></table></div>';
                $mpdf->WriteHTML($html);
            }
        }
    } else {
        // Original behavior - all students together
        $perPage       = 44;
        $chunks        = array_chunk($students, $perPage);
        $totalPages    = count($chunks);
        $totalStudents = count($students);

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                $mpdf->AddPage('L');
            }

            $startNum = ($index * $perPage) + 1;
            $endNum   = min(($index + 1) * $perPage, $totalStudents);
            // Collect unique class names present on this page
            $pageClasses = [];
            foreach ($chunk as $stu) {
                $cn = trim($stu['class_name'] ?? '');
                if ($cn !== '') {
                    $pageClasses[$cn] = true;
                }
            }
            $classList = array_keys($pageClasses);
            $classStr  = '';
            if (!empty($classList)) {
                $classStr = implode('، ', array_map('htmlspecialchars', $classList));
            }

            $footerHtml = '<div style="text-align:center;font-size:9pt;color:#333;border-top:1px solid #999;padding-top:3px;">';
            // Building name followed by class list (only include parts that are non-empty)
            $parts = [];
            if (!empty($building)) {
                $parts[] = htmlspecialchars($building);
            }
            if ($classStr !== '') {
                $parts[] = $classStr;
            }
            if (!empty($parts)) {
                $footerHtml .= implode(' | ', $parts) . ' | ';
            }
            $footerHtml .= 'ردیف ' . toPersianDigits($startNum) . ' تا ' . toPersianDigits($endNum);
            $footerHtml .= ' از مجموع ' . toPersianDigits($totalStudents) . ' نفر';
            $footerHtml .= ' | صفحه ' . toPersianDigits($index + 1) . ' از ' . toPersianDigits($totalPages);
            $footerHtml .= '</div>';
            $mpdf->SetHTMLFooter($footerHtml);

            $half = ceil(count($chunk) / 2);
            $col1 = array_slice($chunk, 0, $half);
            $col2 = array_slice($chunk, $half);

            $html  = $htmlStyle;
            $html .= '<div class="page-header">فهرست شماره صندلی دانشجویان</div>';
            $html .= '<div class="session-info">تاریخ: ' . toPersianDigits($examDate) . ' | ساعت: ' . toPersianDigits($examTime) . '</div>';

            $html .= '<div class="main-container">';
            $html .= '<table class="two-col-table"><tr>';

            // Column 1
            $html .= '<td style="vertical-align: top;">';
            $html .= '<table class="col-table">';
            $html .= '<thead><tr><th class="id-col">شماره دانشجویی</th><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead>';
            $html .= '<tbody>';
            foreach ($col1 as $s) {
                $displaySeat  = $getDisplaySeat($s);
                $html        .= '<tr>';
                $html        .= '<td class="id-col">' . toPersianDigits($s['student_id']) . '</td>';
                $html        .= '<td class="name-col">' . htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) . '</td>';
                $html        .= '<td class="course-col">' . htmlspecialchars($s['course_name']) . '</td>';
                $html        .= '<td class="seat-col">' . toPersianDigits($displaySeat) . '</td>';
                $html        .= '</tr>';
            }
            $html .= '</tbody></table></td>';

            // Column 2
            $html .= '<td style="vertical-align: top;">';
            $html .= '<table class="col-table">';
            $html .= '<thead><tr><th class="id-col">شماره دانشجویی</th><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead>';
            $html .= '<tbody>';
            foreach ($col2 as $s) {
                $displaySeat  = $getDisplaySeat($s);
                $html        .= '<tr>';
                $html        .= '<td class="id-col">' . toPersianDigits($s['student_id']) . '</td>';
                $html        .= '<td class="name-col">' . htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) . '</td>';
                $html        .= '<td class="course-col">' . htmlspecialchars($s['course_name']) . '</td>';
                $html        .= '<td class="seat-col">' . toPersianDigits($displaySeat) . '</td>';
                $html        .= '</tr>';
            }
            $html .= '</tbody></table></td>';

            $html .= '</tr></table></div>';
            $mpdf->WriteHTML($html);
        }
    }

    // Kroki Page (Seat Map) - Add new page first, then clear footer
    $mpdf->AddPage('P');
    $mpdf->SetHTMLFooter('');
    generateKrokiPage($mpdf, $students, $examDate, $examTime);

    // Output the PDF
    $filename   = 'SeatNumbers_' . str_replace(['/', '\\'], '-', $examDate) . '_' . str_replace(':', '-', $examTime) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

function generateKrokiPage($mpdf, $students, $examDate, $examTime)
{
    // Group by Building + Class
    $groups = [];
    foreach ($students as $s) {
        $b   = trim($s['building'] ?? '') ?: 'بدون ساختمان';
        $c   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
        $key = $b . '||' . $c;

        if (!isset($groups[$key])) {
            $groups[$key] = ['building' => $b, 'class_name' => $c, 'nums' => []];
        }

        // Parse seat number (simple integer parsing for now, assuming single numbers)
        // The JS has complex parsing for ranges "1-10", "1 تا 10".
        // We should try to replicate basic parsing.
        $raw = $s['seat_number'];
        if (preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $groups[$key]['nums'][] = $i;
        } elseif (preg_match('/(\d+)\s*(?:تا|تا‌)\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $groups[$key]['nums'][] = $i;
        } else {
            preg_match_all('/\d+/', $raw, $matches);
            foreach ($matches[0] as $n)
                $groups[$key]['nums'][] = (int)$n;
        }
    }

    if (empty($groups))
        return;

    $rows = [];
    foreach ($groups as $g) {
        $uniq = array_unique($g['nums']);
        sort($uniq, SORT_NUMERIC);
        if (empty($uniq))
            continue;

        $rows[] = [
            'building' => $g['building'],
            'class_name' => $g['class_name'],
            'start' => $uniq[0],
            'end' => end($uniq),
            'count' => count($uniq)
        ];
    }

    // Sort rows
    usort($rows, function ($a, $b) {
        if ($a['start'] !== $b['start'])
            return $a['start'] - $b['start'];
        return strcmp($a['building'], $b['building']);
    });

    // Page already added by caller
    $html = '
    <style>
        .kroki-title { font-size: 24pt; font-weight: bold; text-align: center; margin-bottom: 20px; }
        .kroki-meta { font-size: 14pt; text-align: center; margin-bottom: 30px; font-weight: bold; }
        .kroki-table { width: 100%; border-collapse: collapse; font-size: 12pt; }
        .kroki-table th { background-color: #efefef; border: 1px solid #ccc; padding: 10px; text-align: center; font-weight: bold; }
        .kroki-table td { border: 1px solid #ccc; padding: 10px; text-align: center; }
    </style>
    <div class="kroki-title">کروکی محل استقرار صندلی‌ها</div>
    <div class="kroki-meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div>
    <table class="kroki-table">
        <thead>
            <tr>
                <th>از شماره</th>
                <th>تا شماره</th>
                <th>تعداد</th>
                <th>ساختمان</th>
                <th>کلاس / اتاق</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($rows as $r) {
        $html .= '<tr>
            <td>' . toPersianDigits($r['start']) . '</td>
            <td>' . toPersianDigits($r['end']) . '</td>
            <td>' . toPersianDigits($r['count']) . '</td>
            <td>' . $r['building'] . '</td>
            <td>' . $r['class_name'] . '</td>
        </tr>';
    }
    $html .= '</tbody></table>';

    $mpdf->WriteHTML($html);
}

function generateSecretaryReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch Proctors
    $proctors = [];
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT proctor_name 
            FROM ExamAssignments 
            WHERE exam_date = ? AND exam_time = ? AND proctor_name IS NOT NULL AND proctor_name != ''
            ORDER BY proctor_name
        ");
        $stmt->execute([$examDate, $examTime]);
        $proctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }

    // Fetch Exam Data (Courses & Students)
    // Reuse logic: fetch courses first
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            MAX(es.exam_type) AS exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses))
        die('No courses found.');

    $courseCodes  = array_column($courses, 'course_code');
    $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';

    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            es.seat_number,
            es.building,
            es.class_name,
            c.course_code,
            es.exam_type
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
    ");
    $stmt->execute($courseCodes);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find multi-exam students (students with more than one course in this session)
    $multiExamStudents = [];
    $studentExamCount  = [];
    foreach ($allStudents as $s) {
        $sid = $s['student_id'];
        if (!isset($studentExamCount[$sid])) {
            $studentExamCount[$sid] = [];
        }
        $studentExamCount[$sid][] = $s;
    }
    foreach ($studentExamCount as $sid => $exams) {
        if (count($exams) > 1) {
            $multiExamStudents[$sid] = $exams;
        }
    }

    // Check MultiExamMode config
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';

    // Start PDF
    $mpdf->AddPage('P');

    $html = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 10px; }
        .title { font-size: 16pt; font-weight: bold; }
        .meta { font-size: 11pt; font-weight: bold; margin-top: 5px; }
        .proctor-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9pt; }
        .proctor-table th, .proctor-table td { border: 1px solid #ddd; padding: 5px; text-align: right; }
        .proctor-table th { background-color: #f1f1f1; text-align: center; }
        .course-box { margin-bottom: 15px; page-break-inside: avoid; }
        .course-header { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 5px; }
        
   .course-index-cell {
    width: 40px;
    vertical-align: middle;
    padding: 0;                 /* جلوگیری از سفیدی اطراف */
    text-align: center;
    background-color: #000;     /* کل باکس مشکی */
    border-radius: 5px;         /* گوشه‌های گرد روی کل باکس */
}

.course-index-box { 
    color: #fff;
    font-weight: bold;
    text-align: center;
    padding: 8px 0;
    display: block;
    width: 100%;
    background: transparent;    /* پس‌زمینه حذف شد */
}
        .course-info { font-size: 11pt; font-weight: bold; vertical-align: middle; padding-right: 10px; }
        .nested-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
        .nested-table th, .nested-table td { border: 1px solid #ddd; padding: 4px; text-align: center; }
        .nested-table th { background-color: #f1f1f1; font-size: 9pt; }
        .type-header { background: #000; color: #fff; font-size: 12pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
        .multi-exam-section { margin-bottom: 20px; page-break-inside: avoid; }
        .multi-exam-header { background: #dc3545; color: #fff; font-size: 11pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
        .multi-exam-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        .multi-exam-table th, .multi-exam-table td { border: 1px solid #ddd; padding: 5px; text-align: center; }
        .multi-exam-table th { background-color: #f8d7da; font-size: 9pt; }
        .primary-seat { font-weight: bold; color: #dc3545; }
    </style>
    ';

    // Secretary List Header
    $html .= '<div class="header"><div class="title">لیست منشی جلسه</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>';

    // Multi-Exam Students Section will be added after main content
    // Build multi-exam HTML separately for later insertion
    $multiExamHtml = '';
    if (!empty($multiExamStudents)) {
        // Fetch student names
        $multiStudentIds      = array_keys($multiExamStudents);
        $placeholdersStudents = str_repeat('?,', count($multiStudentIds) - 1) . '?';
        $stmtNames            = $pdo->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id IN ($placeholdersStudents)");
        $stmtNames->execute($multiStudentIds);
        $studentNames = [];
        while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
            $studentNames[$row['student_id']] = $row['first_name'] . ' ' . $row['last_name'];
        }

        // Build course_code to course_name map
        $courseMap = [];
        foreach ($courses as $c) {
            $courseMap[$c['course_code']] = $c['course_name'];
        }

        $multiExamHtml .= '<div class="multi-exam-section">';
        $multiExamHtml .= '<div class="multi-exam-header">دانشجویان چند آزمونی (' . toPersianDigits(count($multiExamStudents)) . ' نفر)</div>';
        $multiExamHtml .= '<table class="multi-exam-table"><thead><tr>';
        $multiExamHtml .= '<th style="width: 5%;">#</th>';
        $multiExamHtml .= '<th style="width: 12%;">شماره دانشجویی</th>';
        $multiExamHtml .= '<th style="width: 15%;">نام و نام خانوادگی</th>';
        $multiExamHtml .= '<th style="width: 26%;">دروس</th>';
        $multiExamHtml .= '<th style="width: 12%;">شماره صندلی‌ها</th>';
        $multiExamHtml .= '<th style="width: 30%;">صندلی اصلی و محل استقرار</th>';
        $multiExamHtml .= '</tr></thead><tbody>';

        $rowIndex = 0;
        foreach ($multiExamStudents as $sid => $exams) {
            $rowIndex++;
            $studentName = $studentNames[$sid] ?? 'نامشخص';

            // Collect courses, seat numbers, and location info
            $coursesList   = [];
            $seatNumbers   = [];
            $seatLocations = []; // seat_number => ['building' => ..., 'class_name' => ...]
            foreach ($exams as $exam) {
                $courseName    = $courseMap[$exam['course_code']] ?? $exam['course_code'];
                $coursesList[] = toPersianDigits($exam['course_code']) . ' - ' . $courseName;
                // Parse seat number (get first number)
                $raw = $exam['seat_number'];
                if (preg_match('/(\d+)/', $raw, $m)) {
                    $seatNum                           = (int)$m[1];
                    $seatNumbers[$exam['course_code']] = $seatNum;
                    $seatLocations[$seatNum]           = [
                        'building' => $exam['building'] ?? '',
                        'class_name' => $exam['class_name'] ?? ''
                    ];
                } else {
                    $seatNumbers[$exam['course_code']] = 0;
                }
            }

            // Find primary seat (minimum)
            $primarySeat = min($seatNumbers);

            // Get location info for primary seat
            $primaryBuilding = isset($seatLocations[$primarySeat]) ? trim($seatLocations[$primarySeat]['building']) : '';
            $primaryClass    = isset($seatLocations[$primarySeat]) ? trim($seatLocations[$primarySeat]['class_name']) : '';
            $primaryLocation = '';
            if (!empty($primaryBuilding) || !empty($primaryClass)) {
                $primaryLocation = ' (' . $primaryBuilding . ($primaryBuilding && $primaryClass ? ' - ' : '') . $primaryClass . ')';
            }

            // Format seat display (without star)
            $seatsDisplay = [];
            foreach ($exams as $exam) {
                $seatNum        = $seatNumbers[$exam['course_code']];
                $seatsDisplay[] = toPersianDigits($seatNum);
            }

            $multiExamHtml .= '<tr>';
            $multiExamHtml .= '<td>' . toPersianDigits($rowIndex) . '</td>';
            $multiExamHtml .= '<td>' . toPersianDigits($sid) . '</td>';
            $multiExamHtml .= '<td style="text-align: right;">' . $studentName . '</td>';
            $multiExamHtml .= '<td style="text-align: right; font-size: 8pt;">' . implode('<br>', $coursesList) . '</td>';
            $multiExamHtml .= '<td>' . implode(' - ', $seatsDisplay) . '</td>';
            $multiExamHtml .= '<td style="font-weight: bold; text-align: right;">' . toPersianDigits($primarySeat) . $primaryLocation . '</td>';
            $multiExamHtml .= '</tr>';
        }

        $multiExamHtml .= '</tbody></table>';
        $multiExamHtml .= '</div>';
    }

    // Group Courses by Type
    $examTypes   = ['الکترونیکی', 'کتبی'];
    $usedCourses = [];
    $courseIndex = 0;

    foreach ($examTypes as $type) {
        // Filter courses that have students of this type
        $typeCourses = [];
        foreach ($courses as $c) {
            // Check if any student in this course has this exam_type
            // Optimization: we fetched allStudents, let's filter in memory
            $hasType = false;
            foreach ($allStudents as $s) {
                if ($s['course_code'] === $c['course_code'] && ($s['exam_type'] ?? '') === $type) {
                    $hasType = true;
                    break;
                }
            }
            if ($hasType) {
                $typeCourses[] = $c;
            }
        }

        if (empty($typeCourses))
            continue;

        $html .= '<div class="type-header">' . $type . '</div>';

        foreach ($typeCourses as $course) {
            $usedCourses[] = $course['course_code'];
            $courseIndex++;

            // Filter students for this course AND type
            $cStudents = array_filter($allStudents, function ($s) use ($course, $type) {
                return $s['course_code'] === $course['course_code'] && ($s['exam_type'] ?? '') === $type;
            });

            if (empty($cStudents))
                continue;

            // Group by Building/Class
            $groups = [];
            foreach ($cStudents as $s) {
                $b   = trim($s['building'] ?? '') ?: 'بدون ساختمان';
                $c   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
                $key = $b . '||' . $c;
                if (!isset($groups[$key]))
                    $groups[$key] = ['building' => $b, 'class_name' => $c, 'nums' => []];

                // Parse seat numbers
                $raw = $s['seat_number'];
                if (preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $raw, $m)) {
                    $start = min((int)$m[1], (int)$m[2]);
                    $end   = max((int)$m[1], (int)$m[2]);
                    for ($i = $start; $i <= $end; $i++)
                        $groups[$key]['nums'][] = $i;
                } elseif (preg_match('/(\d+)\s*(?:تا|تا‌)\s*(\d+)/u', $raw, $m)) {
                    $start = min((int)$m[1], (int)$m[2]);
                    $end   = max((int)$m[1], (int)$m[2]);
                    for ($i = $start; $i <= $end; $i++)
                        $groups[$key]['nums'][] = $i;
                } else {
                    preg_match_all('/\d+/', $raw, $matches);
                    foreach ($matches[0] as $n)
                        $groups[$key]['nums'][] = (int)$n;
                }
            }

            $html .= '<div class="course-box">';
            $html .= '<table class="course-header"><tr>
                <td class="course-index-cell"><div class="course-index-box">' . toPersianDigits($courseIndex) . '</div></td>
                <td class="course-info">' . toPersianDigits($course['course_code']) . ' | ' . $course['course_name'] . '</td>
                <td style="text-align: left; font-weight: bold; font-size: 10pt;">' . toPersianDigits(count($cStudents)) . ' نفر</td>
            </tr></table>';

            $html .= '<table class="nested-table"><thead><tr>
                <th style="width: 15%;">از شماره</th>
                <th style="width: 15%;">تا شماره</th>
                <th style="width: 10%;">تعداد</th>
                <th style="width: 30%;">ساختمان</th>
                <th style="width: 30%;">کلاس / اتاق</th>
            </tr></thead><tbody>';

            if (empty($groups)) {
                $html .= '<tr><td colspan="5">بدون اطلاعات کروکی</td></tr>';
            } else {
                // Prepare data with min seat for sorting
                $sortedGroups = [];
                foreach ($groups as $key => $g) {
                    $uniq = array_unique($g['nums']);
                    sort($uniq, SORT_NUMERIC);
                    if (empty($uniq))
                        continue;
                    $g['min_seat']      = $uniq[0];
                    $g['max_seat']      = end($uniq);
                    $g['count']         = count($uniq);
                    $sortedGroups[$key] = $g;
                }
                // Sort by minimum seat number
                uasort($sortedGroups, function ($a, $b) {
                    return $a['min_seat'] - $b['min_seat'];
                });
                foreach ($sortedGroups as $g) {
                    $html .= '<tr>
                        <td>' . toPersianDigits($g['min_seat']) . '</td>
                        <td>' . toPersianDigits($g['max_seat']) . '</td>
                        <td>' . toPersianDigits($g['count']) . '</td>
                        <td style="text-align: right;">' . $g['building'] . '</td>
                        <td style="text-align: right;">' . $g['class_name'] . '</td>
                    </tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
    }

    // Other Courses (not in Electronic or Written, or leftovers)
    // ... logic for leftovers if needed ...

    $mpdf->WriteHTML($html);

    // Multi-Exam Students Section (added after main content)
    if (!empty($multiExamHtml)) {
        $paperSavingMulti = isset($config['PaperSaving']) && strtoupper($config['PaperSaving']) === 'YES';

        if (!$paperSavingMulti) {
            // Add page break before multi-exam section when paper saving is disabled
            $mpdf->AddPage('P');
            // Add header for the new page
            $multiExamHtml = '<div class="header"><div class="title">لیست منشی جلسه</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>' . $multiExamHtml;
        }

        $mpdf->WriteHTML($multiExamHtml);
    }

    // Proctor Section (Moved to end)
    if (!empty($proctors)) {
        $paperSaving = isset($config['PaperSaving']) && strtoupper($config['PaperSaving']) === 'YES';

        if (!$paperSaving) {
            $mpdf->AddPage();
        } else {
            $mpdf->WriteHTML('<div style="margin-top: 20px; margin-bottom: 20px; border-top: 2px dashed #000;"></div>');
        }

        $html = '<div class="header"><div class="title">لیست عوامل اجرائی جلسه</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>';

        // Gather unique locations for this session (building + class_name) grouped by exam_type
        $sessionLocations    = [];
        $electronicLocations = [];
        $writtenLocations    = [];

        foreach ($allStudents as $s) {
            $building    = trim($s['building'] ?? '') ?: 'بدون ساختمان';
            $className   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
            $locationKey = $building . ' - ' . $className;
            $examType    = $s['exam_type'] ?? '';

            if (!isset($sessionLocations[$locationKey])) {
                $sessionLocations[$locationKey] = [
                    'building' => $building,
                    'class_name' => $className,
                    'display' => $locationKey,
                    'exam_type' => $examType
                ];

                // Separate by exam type for balanced assignment
                if ($examType === 'الکترونیکی') {
                    $electronicLocations[] = $locationKey;
                } else {
                    $writtenLocations[] = $locationKey;
                }
            }
        }

        // Create a deterministic seed based on date and time for consistent assignment
        $seedStr = toEnglishDigits($examDate) . '_' . toEnglishDigits($examTime);
        $seed    = crc32($seedStr);
        mt_srand($seed);

        // Shuffle locations to randomize assignment while keeping consistency
        shuffle($electronicLocations);
        shuffle($writtenLocations);

        // Assign locations to proctors with balance between electronic and written
        $proctorCount     = count($proctors);
        $proctorLocations = [];

        // Interleave electronic and written locations for balanced distribution
        $allLocationsShuffled = [];
        $maxLen               = max(count($electronicLocations), count($writtenLocations));
        for ($i = 0; $i < $maxLen; $i++) {
            if (isset($electronicLocations[$i])) {
                $allLocationsShuffled[] = $electronicLocations[$i];
            }
            if (isset($writtenLocations[$i])) {
                $allLocationsShuffled[] = $writtenLocations[$i];
            }
        }

        // Assign locations to proctors in round-robin fashion
        $locIndex = 0;
        $locCount = count($allLocationsShuffled);
        foreach ($proctors as $idx => $p) {
            if ($locCount > 0) {
                $proctorLocations[$idx] = $allLocationsShuffled[$locIndex % $locCount];
                $locIndex++;
            } else {
                $proctorLocations[$idx] = '-';
            }
        }

        // Reset random seed
        mt_srand();

        // Columns logic - now single table with location column
        $html .= '<table class="proctor-table" style="width: 100%;"><thead><tr>';
        $html .= '<th style="width: 40px;">ردیف</th>';
        $html .= '<th style="width: 40%;">نام عامل اجرائی</th>';
        $html .= '<th style="width: 50%;">محل استقرار پیشنهادی</th>';
        $html .= '</tr></thead><tbody>';

        $globalIndex = 1;
        foreach ($proctors as $idx => $p) {
            $location  = $proctorLocations[$idx] ?? '-';
            $html     .= '<tr>';
            $html     .= '<td style="text-align: center;">' . toPersianDigits($globalIndex++) . '</td>';
            $html     .= '<td>' . $p['proctor_name'] . '</td>';
            $html     .= '<td style="text-align: right;">' . $location . '</td>';
            $html     .= '</tr>';
        }
        $html .= '</tbody></table>';
        $mpdf->WriteHTML($html);
    }
}

function generateReproductionReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch Data (Same as Secretary)
    // We need courses and students
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            MAX(es.exam_type) AS exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses))
        die('No courses found.');

    $courseCodes  = array_column($courses, 'course_code');
    $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';

    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            es.seat_number,
            es.building,
            es.class_name,
            c.course_code,
            es.exam_type
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
    ");
    $stmt->execute($courseCodes);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mpdf->AddPage('P');
    $html = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 10px; }
        .title { font-size: 16pt; font-weight: bold; }
        .meta { font-size: 11pt; font-weight: bold; margin-top: 5px; }
        .type-header { background: #000; color: #fff; font-size: 12pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
        .simple-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        .simple-table th, .simple-table td { border: 1px solid #ddd; padding: 5px; text-align: center; }
        .simple-table th { background-color: #f1f1f1; font-size: 9pt; }
        .course-box { margin-bottom: 15px; page-break-inside: avoid; }
        .course-header { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 5px; }
        
.course-index-cell {
    width: 40px;
    vertical-align: middle;
    padding: 0;                 /* جلوگیری از سفیدی اطراف */
    text-align: center;
    background-color: #000;     /* کل باکس مشکی */
    border-radius: 5px;         /* گوشه‌های گرد روی کل باکس */
}

.course-index-box { 
    color: #fff;
    font-weight: bold;
    text-align: center;
    padding: 8px 0;
    display: block;
    width: 100%;
    background: transparent;    /* پس‌زمینه حذف شد */
}

        .course-info { font-size: 11pt; font-weight: bold; vertical-align: middle; padding-right: 10px; }
        .nested-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
        .nested-table th, .nested-table td { border: 1px solid #ddd; padding: 4px; text-align: center; }
        .nested-table th { background-color: #f1f1f1; font-size: 9pt; }
    </style>
    <div class="header"><div class="title">ملزومات اتاق تکثیر</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>
    ';

    // 1. Electronic (Summary)
    $elecCourses = [];
    foreach ($courses as $c) {
        // Check if has electronic students
        $count = 0;
        foreach ($allStudents as $s) {
            if ($s['course_code'] === $c['course_code'] && ($s['exam_type'] ?? '') === 'الکترونیکی') {
                $count++;
            }
        }
        if ($count > 0) {
            $c['elec_count'] = $count;
            $elecCourses[]   = $c;
        }
    }

    if (!empty($elecCourses)) {
        $html .= '<div class="type-header">الکترونیکی</div>';
        $html .= '<table class="simple-table"><thead><tr><th>#</th><th>کد درس</th><th>نام درس</th><th>تعداد</th></tr></thead><tbody>';
        foreach ($elecCourses as $i => $c) {
            $html .= '<tr>
                <td>' . toPersianDigits($i + 1) . '</td>
                <td>' . toPersianDigits($c['course_code']) . '</td>
                <td style="text-align: right;">' . $c['course_name'] . '</td>
                <td>' . toPersianDigits($c['elec_count']) . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    // 2. Written (Detailed)
    $writtenCourses = [];
    foreach ($courses as $c) {
        $count = 0;
        foreach ($allStudents as $s) {
            if ($s['course_code'] === $c['course_code'] && ($s['exam_type'] ?? '') === 'کتبی') {
                $count++;
            }
        }
        if ($count > 0) {
            $c['written_count'] = $count;
            $writtenCourses[]   = $c;
        }
    }

    if (!empty($writtenCourses)) {
        $html        .= '<div class="type-header">کتبی</div>';
        $courseIndex  = 0;
        foreach ($writtenCourses as $course) {
            $courseIndex++;
            $cStudents = array_filter($allStudents, function ($s) use ($course) {
                return $s['course_code'] === $course['course_code'] && ($s['exam_type'] ?? '') === 'کتبی';
            });

            // Group logic (same as Secretary)
            $groups = [];
            foreach ($cStudents as $s) {
                $b   = trim($s['building'] ?? '') ?: 'بدون ساختمان';
                $c   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
                $key = $b . '||' . $c;
                if (!isset($groups[$key]))
                    $groups[$key] = ['building' => $b, 'class_name' => $c, 'nums' => []];

                $raw = $s['seat_number'];
                if (preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $raw, $m)) {
                    $start = min((int)$m[1], (int)$m[2]);
                    $end   = max((int)$m[1], (int)$m[2]);
                    for ($i = $start; $i <= $end; $i++)
                        $groups[$key]['nums'][] = $i;
                } elseif (preg_match('/(\d+)\s*(?:تا|تا‌)\s*(\d+)/u', $raw, $m)) {
                    $start = min((int)$m[1], (int)$m[2]);
                    $end   = max((int)$m[1], (int)$m[2]);
                    for ($i = $start; $i <= $end; $i++)
                        $groups[$key]['nums'][] = $i;
                } else {
                    preg_match_all('/\d+/', $raw, $matches);
                    foreach ($matches[0] as $n)
                        $groups[$key]['nums'][] = (int)$n;
                }
            }

            $html .= '<div class="course-box">';
            $html .= '<table class="course-header"><tr>
                <td class="course-index-cell"><div class="course-index-box">' . toPersianDigits($courseIndex) . '</div></td>
                <td class="course-info">' . toPersianDigits($course['course_code']) . ' | ' . $course['course_name'] . '</td>
                <td style="text-align: left; font-weight: bold; font-size: 10pt;">' . toPersianDigits(count($cStudents)) . ' نفر</td>
            </tr></table>';

            $html .= '<table class="nested-table"><thead><tr>
                <th style="width: 15%;">از شماره</th>
                <th style="width: 15%;">تا شماره</th>
                <th style="width: 10%;">تعداد</th>
                <th style="width: 30%;">ساختمان</th>
                <th style="width: 30%;">کلاس / اتاق</th>
            </tr></thead><tbody>';

            if (empty($groups)) {
                $html .= '<tr><td colspan="5">بدون اطلاعات کروکی</td></tr>';
            } else {
                // Prepare data with min seat for sorting
                $sortedGroups = [];
                foreach ($groups as $key => $g) {
                    $uniq = array_unique($g['nums']);
                    sort($uniq, SORT_NUMERIC);
                    if (empty($uniq))
                        continue;
                    $g['min_seat']      = $uniq[0];
                    $g['max_seat']      = end($uniq);
                    $g['count']         = count($uniq);
                    $sortedGroups[$key] = $g;
                }
                // Sort by minimum seat number
                uasort($sortedGroups, function ($a, $b) {
                    return $a['min_seat'] - $b['min_seat'];
                });
                foreach ($sortedGroups as $g) {
                    $start = $g['min_seat'];
                    $end   = $g['max_seat'];
                    $count = $g['count'];

                    $html .= '<tr>
                        <td>' . toPersianDigits($start) . '</td>
                        <td>' . toPersianDigits($end) . '</td>
                        <td>' . toPersianDigits($count) . '</td>
                        <td style="text-align: right;">' . $g['building'] . '</td>
                        <td style="text-align: right;">' . $g['class_name'] . '</td>
                    </tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
    }

    $mpdf->WriteHTML($html);

    // Check MultiExamMode config and add multi-exam students section
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';

    if ($multiExamModeEnabled) {
        // Find multi-exam students (students with more than one course in this session)
        $studentExamCount = [];
        foreach ($allStudents as $s) {
            $sid = $s['student_id'];
            if (!isset($studentExamCount[$sid])) {
                $studentExamCount[$sid] = [];
            }
            $studentExamCount[$sid][] = $s;
        }

        $multiExamStudents = [];
        foreach ($studentExamCount as $sid => $exams) {
            if (count($exams) > 1) {
                $multiExamStudents[$sid] = $exams;
            }
        }

        if (!empty($multiExamStudents)) {
            // Fetch student names
            $multiStudentIds      = array_keys($multiExamStudents);
            $placeholdersStudents = str_repeat('?,', count($multiStudentIds) - 1) . '?';
            $stmtNames            = $pdo->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id IN ($placeholdersStudents)");
            $stmtNames->execute($multiStudentIds);
            $studentNames = [];
            while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
                $studentNames[$row['student_id']] = $row['first_name'] . ' ' . $row['last_name'];
            }

            // Build course_code to course_name map
            $courseMap = [];
            foreach ($courses as $c) {
                $courseMap[$c['course_code']] = $c['course_name'];
            }

            // Group multi-exam students by their primary seat location (building || class_name)
            $locationMultiExam = [];
            foreach ($multiExamStudents as $sid => $exams) {
                // Find primary seat (minimum seat number)
                $seatNumbers = [];
                $primaryExam = null;
                foreach ($exams as $exam) {
                    $raw = $exam['seat_number'];
                    if (preg_match('/(\d+)/', $raw, $m)) {
                        $seatNum                           = (int)$m[1];
                        $seatNumbers[$exam['course_code']] = $seatNum;
                        if ($primaryExam === null || $seatNum < $seatNumbers[$primaryExam['course_code']]) {
                            $primaryExam = $exam;
                        }
                    }
                }

                if ($primaryExam) {
                    $b   = trim($primaryExam['building'] ?? '') ?: 'بدون ساختمان';
                    $c   = trim($primaryExam['class_name'] ?? '') ?: 'بدون کلاس';
                    $key = $b . '||' . $c;

                    if (!isset($locationMultiExam[$key])) {
                        $locationMultiExam[$key] = [
                            'building' => $b,
                            'class_name' => $c,
                            'students' => []
                        ];
                    }

                    $primarySeat     = min($seatNumbers);
                    $primaryLocation = $b . ' | ' . $c;

                    // Build exam details with location info for each exam
                    $examDetails = [];
                    foreach ($exams as $exam) {
                        $examB        = trim($exam['building'] ?? '') ?: 'بدون ساختمان';
                        $examC        = trim($exam['class_name'] ?? '') ?: 'بدون کلاس';
                        $examLocation = $examB . ' | ' . $examC;
                        $examSeat     = $seatNumbers[$exam['course_code']] ?? 0;
                        $isPrimary    = ($examSeat == $primarySeat);

                        $examDetails[] = [
                            'course_code' => $exam['course_code'],
                            'course_name' => $courseMap[$exam['course_code']] ?? $exam['course_code'],
                            'seat_number' => $examSeat,
                            'location' => $examLocation,
                            'is_primary' => $isPrimary,
                            'send_to' => $primaryLocation
                        ];
                    }

                    // Sort exam details by seat number
                    usort($examDetails, function ($a, $b) {
                        return $a['seat_number'] - $b['seat_number'];
                    });

                    $locationMultiExam[$key]['students'][] = [
                        'student_id' => $sid,
                        'student_name' => $studentNames[$sid] ?? 'نامشخص',
                        'primary_seat' => $primarySeat,
                        'primary_location' => $primaryLocation,
                        'exams' => $exams,
                        'exam_details' => $examDetails,
                        'seat_numbers' => $seatNumbers,
                        'course_map' => $courseMap
                    ];
                }
            }

            // Sort locations
            uksort($locationMultiExam, function ($a, $b) {
                return strcmp($a, $b);
            });

            // Add page break only when paper saving is disabled
            $paperSavingReproMulti = isset($config['PaperSaving']) && strtoupper($config['PaperSaving']) === 'YES';
            if (!$paperSavingReproMulti) {
                $mpdf->AddPage('P');
            } else {
                // Add a separator line when paper saving is enabled
                $mpdf->WriteHTML('<div style="margin-top: 20px; margin-bottom: 20px; border-top: 2px dashed #dc3545;"></div>');
            }
            $multiHtml = '
            <style>
                body { font-family: vazir; font-size: 10pt; }
                .header { text-align: center; margin-bottom: 10px; }
                .title { font-size: 16pt; font-weight: bold; }
                .meta { font-size: 11pt; font-weight: bold; margin-top: 5px; }
                .multi-exam-header { background: #dc3545; color: #fff; font-size: 12pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
                .location-multi-box { margin-bottom: 15px; page-break-inside: avoid; }
                .location-multi-header { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 5px; }
                .location-index-cell { width: 40px; vertical-align: middle; padding: 0; text-align: center; background-color: #dc3545; border-radius: 5px; }
                .location-index-box { color: #fff; font-weight: bold; text-align: center; padding: 8px 0; display: block; width: 100%; background: transparent; }
                .location-info { font-size: 11pt; font-weight: bold; vertical-align: middle; padding-right: 10px; }
                .multi-exam-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8pt; }
                .multi-exam-table th, .multi-exam-table td { border: 1px solid #ddd; padding: 4px; text-align: center; }
                .multi-exam-table th { background-color: #f8d7da; font-size: 8pt; }
                .row-odd { background-color: #f9f9f9; }
                .row-even { background-color: #ffffff; }
            </style>
            <div class="header"><div class="title">دانشجویان چند آزمونی - ملزومات اتاق تکثیر</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>
            <div class="multi-exam-header">لیست دانشجویان چند آزمونی بر اساس مکان استقرار (' . toPersianDigits(count($multiExamStudents)) . ' نفر)</div>
            ';

            $locationIndex = 0;
            foreach ($locationMultiExam as $key => $loc) {
                $locationIndex++;

                // Sort students by primary seat
                usort($loc['students'], function ($a, $b) {
                    return $a['primary_seat'] - $b['primary_seat'];
                });

                $multiHtml .= '<div class="location-multi-box">';
                $multiHtml .= '<table class="location-multi-header"><tr>
                    <td class="location-index-cell"><div class="location-index-box">' . toPersianDigits($locationIndex) . '</div></td>
                    <td class="location-info">' . $loc['building'] . ' | ' . $loc['class_name'] . '</td>
                    <td style="text-align: left; font-weight: bold; font-size: 10pt;">' . toPersianDigits(count($loc['students'])) . ' نفر</td>
                </tr></table>';

                $multiHtml .= '<table class="multi-exam-table"><thead><tr>
                    <th style="width: 5%;">ردیف</th>
                    <th style="width: 10%;">شماره دانشجویی</th>
                    <th style="width: 14%;">نام دانشجو</th>
                    <th style="width: 20%;">نام درس</th>
                    <th style="width: 8%;">صندلی</th>
                    <th style="width: 18%;">مکان اصلی صندلی</th>
                    <th style="width: 25%;">ارسال سوالات به</th>
                </tr></thead><tbody>';

                $rowIndex     = 0;
                $studentIndex = 0;
                foreach ($loc['students'] as $student) {
                    $studentIndex++;
                    $isFirstRow = true;
                    $examCount  = count($student['exam_details']);
                    $rowClass   = ($studentIndex % 2 == 1) ? 'row-odd' : 'row-even';

                    foreach ($student['exam_details'] as $examDetail) {
                        $rowIndex++;

                        $multiHtml .= '<tr class="' . $rowClass . '">';

                        if ($isFirstRow) {
                            $multiHtml  .= '<td rowspan="' . $examCount . '">' . toPersianDigits($studentIndex) . '</td>';
                            $multiHtml  .= '<td rowspan="' . $examCount . '">' . toPersianDigits($student['student_id']) . '</td>';
                            $multiHtml  .= '<td rowspan="' . $examCount . '" style="text-align: right;">' . $student['student_name'] . '</td>';
                            $isFirstRow  = false;
                        }

                        $multiHtml .= '<td style="text-align: right;">' . toPersianDigits($examDetail['course_code']) . ' - ' . $examDetail['course_name'] . '</td>';
                        $multiHtml .= '<td>' . toPersianDigits($examDetail['seat_number']) . '</td>';
                        $multiHtml .= '<td style="text-align: right; font-size: 7pt;">' . $examDetail['location'] . '</td>';

                        if ($examDetail['is_primary']) {
                            $multiHtml .= '<td style="text-align: center;">همین مکان</td>';
                        } else {
                            $multiHtml .= '<td style="text-align: right; font-size: 7pt;">' . $examDetail['send_to'] . '</td>';
                        }

                        $multiHtml .= '</tr>';
                    }
                }

                $multiHtml .= '</tbody></table></div>';
            }

            $mpdf->WriteHTML($multiHtml);
        }
    }
}

function generateLocationReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch courses for electronic section (same as reproduction report)
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            MAX(es.exam_type) AS exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all students for this session with their locations and courses
    $stmt = $pdo->prepare("
        SELECT 
            es.student_id,
            es.seat_number,
            es.building,
            es.class_name,
            es.course_code,
            es.exam_type,
            c.course_name,
            c.course_type
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        ORDER BY es.building, es.class_name, es.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allStudents)) {
        die('No students found for this session.');
    }

    $mpdf->AddPage('P');
    $html = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 10px; }
        .title { font-size: 16pt; font-weight: bold; }
        .meta { font-size: 11pt; font-weight: bold; margin-top: 5px; }
        .type-header { background: #000; color: #fff; font-size: 12pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
        .simple-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
        .simple-table th, .simple-table td { border: 1px solid #ddd; padding: 5px; text-align: center; }
        .simple-table th { background-color: #f1f1f1; font-size: 9pt; }
        .location-box { margin-bottom: 15px; page-break-inside: avoid; }
        .location-header { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 5px; }
        
.location-index-cell {
    width: 40px;
    vertical-align: middle;
    padding: 0;
    text-align: center;
    background-color: #000;
    border-radius: 5px;
}

.location-index-box { 
    color: #fff;
    font-weight: bold;
    text-align: center;
    padding: 8px 0;
    display: block;
    width: 100%;
    background: transparent;
}

        .location-info { font-size: 11pt; font-weight: bold; vertical-align: middle; padding-right: 10px; }
        .nested-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
        .nested-table th, .nested-table td { border: 1px solid #ddd; padding: 4px; text-align: center; }
        .nested-table th { background-color: #f1f1f1; font-size: 9pt; }
    </style>
    <div class="header"><div class="title">ملزومات اتاق تکثیر</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>
    ';

    // 1. Electronic (Summary by Course - same as reproduction report)
    $elecCourses = [];
    foreach ($courses as $c) {
        // Check if has electronic students
        $count = 0;
        foreach ($allStudents as $s) {
            if ($s['course_code'] === $c['course_code'] && ($s['exam_type'] ?? '') === 'الکترونیکی') {
                $count++;
            }
        }
        if ($count > 0) {
            $c['elec_count'] = $count;
            $elecCourses[]   = $c;
        }
    }

    if (!empty($elecCourses)) {
        $html .= '<div class="type-header">الکترونیکی</div>';
        $html .= '<table class="simple-table"><thead><tr><th>#</th><th>کد درس</th><th>نام درس</th><th>تعداد</th></tr></thead><tbody>';
        foreach ($elecCourses as $i => $c) {
            $html .= '<tr>
                <td>' . toPersianDigits($i + 1) . '</td>
                <td>' . toPersianDigits($c['course_code']) . '</td>
                <td style="text-align: right;">' . $c['course_name'] . '</td>
                <td>' . toPersianDigits($c['elec_count']) . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    // 2. Written (Detailed by Location)
    // Group students by location (building || class_name), then by course
    $locationGroups = [];
    foreach ($allStudents as $s) {
        // Only process written exams for location-based report
        if (($s['exam_type'] ?? '') !== 'کتبی')
            continue;

        $b   = trim($s['building'] ?? '') ?: 'بدون ساختمان';
        $c   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
        $key = $b . '||' . $c;

        if (!isset($locationGroups[$key])) {
            $locationGroups[$key] = [
                'building' => $b,
                'class_name' => $c,
                'students' => [],
                'courses' => []
            ];
        }

        $locationGroups[$key]['students'][] = $s;

        // Group by course within this location
        $courseCode = $s['course_code'];
        if (!isset($locationGroups[$key]['courses'][$courseCode])) {
            $locationGroups[$key]['courses'][$courseCode] = [
                'course_code' => $courseCode,
                'course_name' => $s['course_name'],
                'course_type' => $s['course_type'],
                'nums' => []
            ];
        }

        // Parse seat numbers
        $raw = $s['seat_number'];
        if (preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = $i;
        } elseif (preg_match('/(\d+)\s*(?:تا|تا‌)\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = $i;
        } else {
            preg_match_all('/\d+/', $raw, $matches);
            foreach ($matches[0] as $n)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = (int)$n;
        }
    }

    // Sort locations by building then class
    uksort($locationGroups, function ($a, $b) {
        return strcmp($a, $b);
    });

    // Render Written Locations (Detailed)
    if (!empty($locationGroups)) {
        $html          .= '<div class="type-header">کتبی</div>';
        $locationIndex  = 0;

        foreach ($locationGroups as $key => $loc) {
            $locationIndex++;
            $totalStudents = count($loc['students']);

            $html .= '<div class="location-box">';
            $html .= '<table class="location-header"><tr>
                <td class="location-index-cell"><div class="location-index-box">' . toPersianDigits($locationIndex) . '</div></td>
                <td class="location-info">' . $loc['building'] . ' | ' . $loc['class_name'] . '</td>
                <td style="text-align: left; font-weight: bold; font-size: 10pt;">' . toPersianDigits($totalStudents) . ' نفر</td>
            </tr></table>';

            $html .= '<table class="nested-table"><thead><tr>
                <th style="width: 15%;">از شماره</th>
                <th style="width: 15%;">تا شماره</th>
                <th style="width: 10%;">تعداد</th>
                <th style="width: 15%;">کد درس</th>
                <th style="width: 45%;">نام درس</th>
            </tr></thead><tbody>';

            if (empty($loc['courses'])) {
                $html .= '<tr><td colspan="5">بدون اطلاعات</td></tr>';
            } else {
                // Sort courses by minimum seat number
                $sortedCourses = [];
                foreach ($loc['courses'] as $courseCode => $courseData) {
                    $uniq = array_unique($courseData['nums']);
                    sort($uniq, SORT_NUMERIC);
                    if (empty($uniq))
                        continue;
                    $courseData['min_seat']     = $uniq[0];
                    $courseData['max_seat']     = end($uniq);
                    $courseData['count']        = count($uniq);
                    $sortedCourses[$courseCode] = $courseData;
                }

                uasort($sortedCourses, function ($a, $b) {
                    return $a['min_seat'] - $b['min_seat'];
                });

                foreach ($sortedCourses as $courseData) {
                    $start = $courseData['min_seat'];
                    $end   = $courseData['max_seat'];
                    $count = $courseData['count'];

                    $html .= '<tr>
                        <td>' . toPersianDigits($start) . '</td>
                        <td>' . toPersianDigits($end) . '</td>
                        <td>' . toPersianDigits($count) . '</td>
                        <td>' . toPersianDigits($courseData['course_code']) . '</td>
                        <td style="text-align: right;">' . $courseData['course_name'] . '</td>
                    </tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
    }

    $mpdf->WriteHTML($html);

    // Check MultiExamMode config and add multi-exam students section
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';

    if ($multiExamModeEnabled) {
        // Find multi-exam students (students with more than one course in this session)
        $studentExamCount = [];
        foreach ($allStudents as $s) {
            $sid = $s['student_id'];
            if (!isset($studentExamCount[$sid])) {
                $studentExamCount[$sid] = [];
            }
            $studentExamCount[$sid][] = $s;
        }

        $multiExamStudents = [];
        foreach ($studentExamCount as $sid => $exams) {
            if (count($exams) > 1) {
                $multiExamStudents[$sid] = $exams;
            }
        }

        if (!empty($multiExamStudents)) {
            // Fetch student names
            $multiStudentIds      = array_keys($multiExamStudents);
            $placeholdersStudents = str_repeat('?,', count($multiStudentIds) - 1) . '?';
            $stmtNames            = $pdo->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id IN ($placeholdersStudents)");
            $stmtNames->execute($multiStudentIds);
            $studentNames = [];
            while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
                $studentNames[$row['student_id']] = $row['first_name'] . ' ' . $row['last_name'];
            }

            // Build course_code to course_name map
            $courseMap = [];
            foreach ($courses as $c) {
                $courseMap[$c['course_code']] = $c['course_name'];
            }

            // Group multi-exam students by their primary seat location (building || class_name)
            $locationMultiExam = [];
            foreach ($multiExamStudents as $sid => $exams) {
                // Find primary seat (minimum seat number)
                $seatNumbers = [];
                $primaryExam = null;
                foreach ($exams as $exam) {
                    $raw = $exam['seat_number'];
                    if (preg_match('/(\d+)/', $raw, $m)) {
                        $seatNum                           = (int)$m[1];
                        $seatNumbers[$exam['course_code']] = $seatNum;
                        if ($primaryExam === null || $seatNum < $seatNumbers[$primaryExam['course_code']]) {
                            $primaryExam = $exam;
                        }
                    }
                }

                if ($primaryExam) {
                    $b   = trim($primaryExam['building'] ?? '') ?: 'بدون ساختمان';
                    $c   = trim($primaryExam['class_name'] ?? '') ?: 'بدون کلاس';
                    $key = $b . '||' . $c;

                    if (!isset($locationMultiExam[$key])) {
                        $locationMultiExam[$key] = [
                            'building' => $b,
                            'class_name' => $c,
                            'students' => []
                        ];
                    }

                    $primarySeat     = min($seatNumbers);
                    $primaryLocation = $b . ' | ' . $c;

                    // Build exam details with location info for each exam
                    $examDetails = [];
                    foreach ($exams as $exam) {
                        $examB        = trim($exam['building'] ?? '') ?: 'بدون ساختمان';
                        $examC        = trim($exam['class_name'] ?? '') ?: 'بدون کلاس';
                        $examLocation = $examB . ' | ' . $examC;
                        $examSeat     = $seatNumbers[$exam['course_code']] ?? 0;
                        $isPrimary    = ($examSeat == $primarySeat);

                        $examDetails[] = [
                            'course_code' => $exam['course_code'],
                            'course_name' => $courseMap[$exam['course_code']] ?? $exam['course_code'],
                            'seat_number' => $examSeat,
                            'location' => $examLocation,
                            'is_primary' => $isPrimary,
                            'send_to' => $primaryLocation
                        ];
                    }

                    // Sort exam details by seat number
                    usort($examDetails, function ($a, $b) {
                        return $a['seat_number'] - $b['seat_number'];
                    });

                    $locationMultiExam[$key]['students'][] = [
                        'student_id' => $sid,
                        'student_name' => $studentNames[$sid] ?? 'نامشخص',
                        'primary_seat' => $primarySeat,
                        'primary_location' => $primaryLocation,
                        'exams' => $exams,
                        'exam_details' => $examDetails,
                        'seat_numbers' => $seatNumbers,
                        'course_map' => $courseMap
                    ];
                }
            }

            // Sort locations
            uksort($locationMultiExam, function ($a, $b) {
                return strcmp($a, $b);
            });

            // Add page break only when paper saving is disabled
            $paperSavingLocMulti = isset($config['PaperSaving']) && strtoupper($config['PaperSaving']) === 'YES';
            if (!$paperSavingLocMulti) {
                $mpdf->AddPage('P');
            } else {
                // Add a separator line when paper saving is enabled
                $mpdf->WriteHTML('<div style="margin-top: 20px; margin-bottom: 20px; border-top: 2px dashed #dc3545;"></div>');
            }
            $multiHtml = '
            <style>
                body { font-family: vazir; font-size: 10pt; }
                .header { text-align: center; margin-bottom: 10px; }
                .title { font-size: 16pt; font-weight: bold; }
                .meta { font-size: 11pt; font-weight: bold; margin-top: 5px; }
                .multi-exam-header { background: #dc3545; color: #fff; font-size: 12pt; font-weight: bold; text-align: center; padding: 6px; border-radius: 8px; margin: 10px 0; }
                .location-multi-box { margin-bottom: 15px; page-break-inside: avoid; }
                .location-multi-header { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 5px; }
                .location-index-cell { width: 40px; vertical-align: middle; padding: 0; text-align: center; background-color: #dc3545; border-radius: 5px; }
                .location-index-box { color: #fff; font-weight: bold; text-align: center; padding: 8px 0; display: block; width: 100%; background: transparent; }
                .location-info { font-size: 11pt; font-weight: bold; vertical-align: middle; padding-right: 10px; }
                .multi-exam-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8pt; }
                .multi-exam-table th, .multi-exam-table td { border: 1px solid #ddd; padding: 4px; text-align: center; }
                .multi-exam-table th { background-color: #f8d7da; font-size: 8pt; }
                .row-odd { background-color: #f9f9f9; }
                .row-even { background-color: #ffffff; }
            </style>
            <div class="header"><div class="title">دانشجویان چند آزمونی - ملزومات اتاق تکثیر</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>
            <div class="multi-exam-header">لیست دانشجویان چند آزمونی بر اساس مکان استقرار (' . toPersianDigits(count($multiExamStudents)) . ' نفر)</div>
            ';

            $locationIndex = 0;
            foreach ($locationMultiExam as $key => $loc) {
                $locationIndex++;

                // Sort students by primary seat
                usort($loc['students'], function ($a, $b) {
                    return $a['primary_seat'] - $b['primary_seat'];
                });

                $multiHtml .= '<div class="location-multi-box">';
                $multiHtml .= '<table class="location-multi-header"><tr>
                    <td class="location-index-cell"><div class="location-index-box">' . toPersianDigits($locationIndex) . '</div></td>
                    <td class="location-info">' . $loc['building'] . ' | ' . $loc['class_name'] . '</td>
                    <td style="text-align: left; font-weight: bold; font-size: 10pt;">' . toPersianDigits(count($loc['students'])) . ' نفر</td>
                </tr></table>';

                $multiHtml .= '<table class="multi-exam-table"><thead><tr>
                    <th style="width: 5%;">ردیف</th>
                    <th style="width: 10%;">شماره دانشجویی</th>
                    <th style="width: 14%;">نام دانشجو</th>
                    <th style="width: 20%;">نام درس</th>
                    <th style="width: 8%;">صندلی</th>
                    <th style="width: 18%;">مکان اصلی صندلی</th>
                    <th style="width: 25%;">ارسال سوالات به</th>
                </tr></thead><tbody>';

                $rowIndex     = 0;
                $studentIndex = 0;
                foreach ($loc['students'] as $student) {
                    $studentIndex++;
                    $isFirstRow = true;
                    $examCount  = count($student['exam_details']);
                    $rowClass   = ($studentIndex % 2 == 1) ? 'row-odd' : 'row-even';

                    foreach ($student['exam_details'] as $examDetail) {
                        $rowIndex++;

                        $multiHtml .= '<tr class="' . $rowClass . '">';

                        if ($isFirstRow) {
                            $multiHtml  .= '<td rowspan="' . $examCount . '">' . toPersianDigits($studentIndex) . '</td>';
                            $multiHtml  .= '<td rowspan="' . $examCount . '">' . toPersianDigits($student['student_id']) . '</td>';
                            $multiHtml  .= '<td rowspan="' . $examCount . '" style="text-align: right;">' . $student['student_name'] . '</td>';
                            $isFirstRow  = false;
                        }

                        $multiHtml .= '<td style="text-align: right;">' . toPersianDigits($examDetail['course_code']) . ' - ' . $examDetail['course_name'] . '</td>';
                        $multiHtml .= '<td>' . toPersianDigits($examDetail['seat_number']) . '</td>';
                        $multiHtml .= '<td style="text-align: right; font-size: 7pt;">' . $examDetail['location'] . '</td>';

                        if ($examDetail['is_primary']) {
                            $multiHtml .= '<td style="text-align: center;">همین مکان</td>';
                        } else {
                            $multiHtml .= '<td style="text-align: right; font-size: 7pt;">' . $examDetail['send_to'] . '</td>';
                        }

                        $multiHtml .= '</tr>';
                    }
                }

                $multiHtml .= '</tbody></table></div>';
            }

            $mpdf->WriteHTML($multiHtml);
        }
    }
}

function generateLocationLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch all students for this session with their locations and courses (written exams only)
    $stmt = $pdo->prepare("
        SELECT 
            es.student_id,
            s.first_name,
            s.last_name,
            es.seat_number,
            es.building,
            es.class_name,
            es.course_code,
            es.exam_type,
            c.course_name,
            c.course_type
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE c.exam_date = ? AND c.exam_time = ? AND es.exam_type = 'کتبی'
        ORDER BY es.building, es.class_name, es.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allStudents)) {
        die('No written exam students found for this session.');
    }

    // Check MultiExamMode config
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';
    $multiExamMap         = [];

    if ($multiExamModeEnabled) {
        // Identify Multi-Exam Students
        $studentExams = [];
        foreach ($allStudents as $s) {
            $sid = $s['student_id'];
            if (!isset($studentExams[$sid])) {
                $studentExams[$sid] = [];
            }
            $studentExams[$sid][] = $s;
        }

        foreach ($studentExams as $sid => $exams) {
            if (count($exams) > 1) {
                // Find primary seat
                $minSeat    = null;
                $primaryLoc = '';
                foreach ($exams as $e) {
                    $seat = 0;
                    if (preg_match('/(\d+)/', $e['seat_number'], $m)) {
                        $seat = (int)$m[1];
                    }
                    if ($minSeat === null || $seat < $minSeat) {
                        $minSeat    = $seat;
                        $b          = trim($e['building'] ?? '') ?: 'بدون ساختمان';
                        $c          = trim($e['class_name'] ?? '') ?: 'بدون کلاس';
                        $primaryLoc = $b . '||' . $c;
                    }
                }
                $multiExamMap[$sid] = [
                    'primary_seat' => $minSeat,
                    'primary_loc_key' => $primaryLoc,
                    'exams' => $exams
                ];
            }
        }
    }

    // Group students by location (building || class_name), then by course
    $locationGroups = [];
    foreach ($allStudents as $s) {
        $b   = trim($s['building'] ?? '') ?: 'بدون ساختمان';
        $c   = trim($s['class_name'] ?? '') ?: 'بدون کلاس';
        $key = $b . '||' . $c;

        if (!isset($locationGroups[$key])) {
            $locationGroups[$key] = [
                'building' => $b,
                'class_name' => $c,
                'students' => [],
                'courses' => []
            ];
        }

        $locationGroups[$key]['students'][] = $s;

        // Group by course within this location
        $courseCode = $s['course_code'];
        if (!isset($locationGroups[$key]['courses'][$courseCode])) {
            $locationGroups[$key]['courses'][$courseCode] = [
                'course_code' => $courseCode,
                'course_name' => $s['course_name'],
                'course_type' => $s['course_type'],
                'nums' => []
            ];
        }

        // Parse seat numbers
        $raw = $s['seat_number'];
        if (preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = $i;
        } elseif (preg_match('/(\d+)\s*(?:تا|تا‌)\s*(\d+)/u', $raw, $m)) {
            $start = min((int)$m[1], (int)$m[2]);
            $end   = max((int)$m[1], (int)$m[2]);
            for ($i = $start; $i <= $end; $i++)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = $i;
        } else {
            preg_match_all('/\d+/', $raw, $matches);
            foreach ($matches[0] as $n)
                $locationGroups[$key]['courses'][$courseCode]['nums'][] = (int)$n;
        }
    }

    // Sort locations by building then class
    uksort($locationGroups, function ($a, $b) {
        return strcmp($a, $b);
    });

    if (empty($locationGroups)) {
        die('No location data found.');
    }

    // Create new mPDF instance for A5 Landscape
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'orientation' => 'L',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 8,
        'tempDir' => __DIR__ . '/../temp',
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    $css = '
        body { font-family: vazir; font-size: 10pt; direction: rtl; }
        .label-page { width: 100%; height: 100%; }
        .location-title { 
            text-align: center; 
            font-size: 14pt; 
            font-weight: bold; 
            background: #000; 
            color: #fff; 
            padding: 8px 15px; 
            border-radius: 8px; 
            margin-bottom: 10px;
        }
        .meta-info {
            text-align: center;
            font-size: 10pt;
            color: #333;
            margin-bottom: 8px;
        }
        .courses-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 9pt; 
        }
        .courses-table th, .courses-table td { 
            border: 1px solid #999; 
            padding: 5px 8px; 
            text-align: center; 
        }
        .courses-table th { 
            background-color: #e0e0e0; 
            font-weight: bold; 
            font-size: 9pt;
        }
        .courses-table td.course-name {
            text-align: right;
        }
        .total-row {
            font-weight: bold;
            background-color: #f5f5f5;
        }
        .footer-signature {
            text-align: center;
            font-size: 9pt;
            margin-top: 10mm;
            padding-top: 5mm;
            border-top: 1px dashed #999;
        }
    ';

    // Set up footer with page numbering and signature
    $mpdf->SetHTMLFooter('
        <div style="text-align: center; font-family: vazir; font-size: 8pt; color: #666;">
            <div style="margin-bottom: 3mm;">محل امضا مراقب: ................................</div>
        </div>
    ');

    // Maximum rows per page for A5 Landscape (considering header and footer space)
    $maxRowsPerPage = 10;

    $pageIndex = 0;
    foreach ($locationGroups as $key => $loc) {
        $totalStudents = count($loc['students']);

        // Sort courses by minimum seat number
        $sortedCourses = [];
        foreach ($loc['courses'] as $courseCode => $courseData) {
            $uniq = array_unique($courseData['nums']);
            sort($uniq, SORT_NUMERIC);
            if (empty($uniq))
                continue;
            $courseData['min_seat']     = $uniq[0];
            $courseData['max_seat']     = end($uniq);
            $courseData['count']        = count($uniq);
            $sortedCourses[$courseCode] = $courseData;
        }

        uasort($sortedCourses, function ($a, $b) {
            return $a['min_seat'] - $b['min_seat'];
        });

        // Build all rows for this location (regular courses + multi-exam rows)
        $allRows = [];

        // Add regular course rows
        $rowIndex = 0;
        foreach ($sortedCourses as $courseData) {
            $rowIndex++;
            $courseTypeLabel = '';
            $ct              = $courseData['course_type'] ?? '';
            if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
                $courseTypeLabel = 'تستی و تشریحی';
            } elseif (stripos($ct, 'تستی') !== false) {
                $courseTypeLabel = 'تستی';
            } elseif (stripos($ct, 'تشریحی') !== false) {
                $courseTypeLabel = 'تشریحی';
            } else {
                $courseTypeLabel = $ct ?: '-';
            }

            $allRows[] = [
                'type' => 'regular',
                'rowNum' => $rowIndex,
                'min_seat' => $courseData['min_seat'],
                'max_seat' => $courseData['max_seat'],
                'count' => $courseData['count'],
                'course_code' => $courseData['course_code'],
                'course_name' => $courseData['course_name'],
                'course_type_label' => $courseTypeLabel
            ];
        }

        // Add multi-exam rows if enabled
        if ($multiExamModeEnabled) {
            $residentMulti = [];
            $movedMulti    = [];

            $seenStudents = [];
            foreach ($loc['students'] as $s) {
                $sid = $s['student_id'];
                if (isset($seenStudents[$sid]))
                    continue;
                $seenStudents[$sid] = true;

                if (isset($multiExamMap[$sid])) {
                    $mData = $multiExamMap[$sid];
                    if ($mData['primary_loc_key'] === $key) {
                        $residentMulti[] = [
                            'student' => $s,
                            'data' => $mData
                        ];
                    } else {
                        $movedMulti[] = [
                            'student' => $s,
                            'data' => $mData
                        ];
                    }
                }
            }

            // Add green rows (courses that came to this location)
            foreach ($residentMulti as $item) {
                foreach ($item['data']['exams'] as $e) {
                    $eSeat = 0;
                    if (preg_match('/(\d+)/', $e['seat_number'], $m)) {
                        $eSeat = (int)$m[1];
                    }
                    if ($eSeat == $item['data']['primary_seat'])
                        continue;

                    $courseTypeLabel = '';
                    $ct              = $e['course_type'] ?? '';
                    if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
                        $courseTypeLabel = 'تستی و تشریحی';
                    } elseif (stripos($ct, 'تستی') !== false) {
                        $courseTypeLabel = 'تستی';
                    } elseif (stripos($ct, 'تشریحی') !== false) {
                        $courseTypeLabel = 'تشریحی';
                    } else {
                        $courseTypeLabel = $ct ?: '-';
                    }

                    $allRows[] = [
                        'type' => 'green',
                        'primary_seat' => $item['data']['primary_seat'],
                        'course_code' => $e['course_code'],
                        'course_name' => $e['course_name'],
                        'course_type_label' => $courseTypeLabel
                    ];
                }
            }

            // Add red rows (courses moved to primary seat)
            foreach ($movedMulti as $item) {
                $pSeat     = $item['data']['primary_seat'];
                $pLocParts = explode('||', $item['data']['primary_loc_key']);
                $pLocStr   = $pLocParts[0] . ' - ' . $pLocParts[1];

                foreach ($item['data']['exams'] as $e) {
                    $eB      = trim($e['building'] ?? '') ?: 'بدون ساختمان';
                    $eC      = trim($e['class_name'] ?? '') ?: 'بدون کلاس';
                    $eLocKey = $eB . '||' . $eC;

                    if ($eLocKey === $key) {
                        $currentSeat = 0;
                        if (preg_match('/(\d+)/', $e['seat_number'], $m)) {
                            $currentSeat = (int)$m[1];
                        }

                        $courseTypeLabel = '';
                        $ct              = $e['course_type'] ?? '';
                        if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
                            $courseTypeLabel = 'تستی و تشریحی';
                        } elseif (stripos($ct, 'تستی') !== false) {
                            $courseTypeLabel = 'تستی';
                        } elseif (stripos($ct, 'تشریحی') !== false) {
                            $courseTypeLabel = 'تشریحی';
                        } else {
                            $courseTypeLabel = $ct ?: '-';
                        }

                        $allRows[] = [
                            'type' => 'red',
                            'current_seat' => $currentSeat,
                            'primary_seat' => $pSeat,
                            'primary_loc_str' => $pLocStr,
                            'course_code' => $e['course_code'],
                            'course_name' => $e['course_name'],
                            'course_type_label' => $courseTypeLabel
                        ];
                    }
                }
            }
        }

        // Check if we have any red rows to add the footer note
        $hasRedRows = false;
        foreach ($allRows as $row) {
            if ($row['type'] === 'red') {
                $hasRedRows = true;
                break;
            }
        }

        // Paginate rows
        $totalRows = count($allRows);
        if ($totalRows === 0) {
            $allRows[] = ['type' => 'empty'];
        }

        $rowChunks          = array_chunk($allRows, $maxRowsPerPage);
        $totalLocationPages = count($rowChunks);

        foreach ($rowChunks as $chunkIndex => $rowChunk) {
            if ($pageIndex > 0) {
                $mpdf->AddPage('L');
            }
            $pageIndex++;

            $isLastPageOfLocation = ($chunkIndex === $totalLocationPages - 1);

            $html  = '<style>' . $css . '</style>';
            $html .= '<div class="label-page">';

            // Location title (building | class)
            $locationTitle = $loc['building'] . ' — ' . $loc['class_name'];
            if ($totalLocationPages > 1) {
                $locationTitle .= ' (صفحه ' . toPersianDigits($chunkIndex + 1) . ' از ' . toPersianDigits($totalLocationPages) . ')';
            }
            $html .= '<div class="location-title">' . $locationTitle . '</div>';

            // Meta info (date, time, total)
            $html .= '<div class="meta-info">' . toPersianDigits($examDate) . ' | ساعت ' . toPersianDigits($examTime) . ' | مجموع: ' . toPersianDigits($totalStudents) . ' نفر</div>';

            // Courses table
            $html .= '<table class="courses-table">';
            $html .= '<thead><tr>
                <th style="width: 5%;">#</th>
                <th style="width: 8%;">از شماره</th>
                <th style="width: 8%;">تا شماره</th>
                <th style="width: 6%;">تعداد</th>
                <th style="width: 9%;">کد درس</th>
                <th style="width: 31%;">نام درس</th>
                <th style="width: 13%;">نوع درس</th>
                <th style="width: 20%;">حاضرین / غایبین</th>
            </tr></thead><tbody>';

            foreach ($rowChunk as $row) {
                if ($row['type'] === 'empty') {
                    $html .= '<tr><td colspan="8">بدون اطلاعات</td></tr>';
                } elseif ($row['type'] === 'regular') {
                    $html .= '<tr>
                        <td>' . toPersianDigits($row['rowNum']) . '</td>
                        <td>' . toPersianDigits($row['min_seat']) . '</td>
                        <td>' . toPersianDigits($row['max_seat']) . '</td>
                        <td>' . toPersianDigits($row['count']) . '</td>
                        <td>' . toPersianDigits($row['course_code']) . '</td>
                        <td class="course-name">' . $row['course_name'] . '</td>
                        <td>' . $row['course_type_label'] . '</td>
                        <td>...... / ......</td>
                    </tr>';
                } elseif ($row['type'] === 'green') {
                    $html .= '<tr style="background-color: #d4edda;">
                        <td style="font-weight: bold; font-size: 12pt;">+</td>
                        <td colspan="3">صندلی ' . toPersianDigits($row['primary_seat']) . '</td>
                        <td>' . toPersianDigits($row['course_code']) . '</td>
                        <td class="course-name">' . $row['course_name'] . '</td>
                        <td>' . $row['course_type_label'] . '</td>
                        <td>...... / ......</td>
                    </tr>';
                } elseif ($row['type'] === 'red') {
                    $html .= '<tr style="background-color: #f8d7da;">
                        <td style="font-weight: bold; font-size: 12pt;">−</td>
                        <td colspan="3">از ' . toPersianDigits($row['current_seat']) . ' به ' . toPersianDigits($row['primary_seat']) . '</td>
                        <td>' . toPersianDigits($row['course_code']) . '</td>
                        <td class="course-name">' . $row['course_name'] . '</td>
                        <td>' . $row['course_type_label'] . '</td>
                        <td>' . $row['primary_loc_str'] . '</td>
                    </tr>';
                }
            }

            // Add footer note on last page of location if there are red rows
            if ($isLastPageOfLocation && $hasRedRows) {
                $html .= '<tr style="background-color: #f8d7da;">
                    <td colspan="8" style="text-align: right; font-size: 8pt; font-weight: bold;">
                        توجه: سطرهای با علامت (−) سوالاتشان به صندلی اصلی ارسال شده است. لطفاً پاسخنامه‌های این دانشجویان را به رابط یا مسئول جلسه تحویل دهید تا به صندلی اصلی منتقل شود.
                    </td>
                </tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';

            $mpdf->WriteHTML($html);
        }
    }

    $filename   = 'LocationLabels_' . str_replace(['/', '\\'], '-', $examDate) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

function generateDescriptiveLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch Courses (Descriptive and Test-Descriptive) with student count
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(es.student_id) as student_count 
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ? AND c.course_type LIKE '%تشریحی%'
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A5 Landscape
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'orientation' => 'L',
        'tempDir' => __DIR__ . '/../temp',
        // ... font config ...
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    $htmlStyle = '
    <style>
        body { font-family: vazir; font-size: 13pt; line-height: 1.5; }
        .page { border: 1px solid #fff; padding: 5px; box-sizing: border-box; }
        .strong { font-weight: bold; }
        .blank { display: inline-block; width: 50px; border-bottom: 1px solid #000; }
        .footer-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .footer-table td { border: 1px solid #000; padding: 10px; vertical-align: top; height: 60px; width: 50%; }
        .count-line { text-align: center; margin-top: 10px; font-size: 10pt; }
    </style>';

    foreach ($courses as $i => $c) {
        if ($i > 0)
            $mpdf->AddPage('L');

        // Get buildings for this course
        $buildingsStmt = $pdo->prepare("
            SELECT DISTINCT building 
            FROM exam_seats 
            WHERE course_code = ? 
            ORDER BY building
        ");
        $buildingsStmt->execute([$c['course_code']]);
        $buildings = $buildingsStmt->fetchAll(PDO::FETCH_COLUMN);

        $buildingText = '';
        if (!empty($buildings)) {
            $buildings = array_filter($buildings); // Remove empty values
            // Remove the word "ساختمان" from building names
            $buildings = array_map(function ($b) {
                return trim(preg_replace('/ساختمان\s*/u', '', $b));
            }, $buildings);
            $buildings = array_filter($buildings); // Remove empty after replacement

            if (count($buildings) === 1) {
                $buildingText = ' و در ساختمان <span class="strong">' . $buildings[0] . '</span>';
            } elseif (count($buildings) > 1) {
                $lastBuilding = array_pop($buildings);
                $buildingText = ' و در ساختمان‌های <span class="strong">' . implode('، ', $buildings) . ' و ' . $lastBuilding . '</span>';
            }
        }

        $html = $htmlStyle . '
        <div class="page">
            <div style="font-weight: bold; margin-bottom: 20px;">استاد ارجمند؛</div>
            <div style="text-align: justify;">
                بدین وسیله تعداد <span class="blank"></span> برگه تشریحی مربوط به درس <span class="strong">' . $c['course_name'] . '</span> 
                با کد <span class="strong">' . toPersianDigits($c['course_code']) . '</span>
                که آزمون آن در تاریخ <span class="strong">' . toPersianDigits($examDate) . '</span> 
                ساعت <span class="strong">' . toPersianDigits($examTime) . '</span>
                به صورت <span class="strong">' . ($c['course_type'] ?? 'کتبی') . '</span>' . $buildingText . ' برگزار گردید، تحویل حضور استاد محترم می‌گردد.
            </div>
            <div class="count-line">
                تعداد کل: <span class="strong">' . toPersianDigits($c['student_count'] ?? 0) . '</span> &nbsp;&nbsp;&nbsp;&nbsp;
                حاضرین: _________ &nbsp;&nbsp;&nbsp;&nbsp;
                غایبین: _________ &nbsp;&nbsp;&nbsp;&nbsp;
                تعداد اوراق تحویلی: _________
            </div>
            <div style="margin-top: 10px; font-size: 13pt; text-align: justify;">
                <span class="strong">تأکید می‌شود:</span><br>
                بر اساس ضوابط آموزشی، استاد محترم موظف است مطابق با نمونه سوالات ضمیمه و کلید سؤالات موجود در سامانه گلستان، حداکثر ظرف ۵ روز پس از تاریخ تحویل، نسبت به تصحیح کامل اوراق و ثبت نمرات نهایی در سامانه گلستان اقدام نموده و پاکت حاوی پاسخنامه‌ها را شمارش شده به دانشگاه بازگرداند.
            </div>
            
            <table class="footer-table">
                <tr>
                    <td>
                        <div style="text-align: center; font-weight: bold; margin-bottom: 20px;">تحویل‌دهنده</div>
                        <div style="text-align: right; margin-bottom: 10px;"><br><br> <span style="display:inline-block; width: 150px; border-bottom: 1px solid #000;"></span></div>
                        <div style="border-bottom: 1px dashed #000; margin-bottom: 10px;"></div>
                        <div style="text-align: center; font-weight: bold;">امضاء</div>
                    </td>
                    <td>
                        <div style="text-align: center; font-weight: bold; margin-bottom: 20px;">تحویل‌گیرنده (استاد)</div>
                        <div style="text-align: right; margin-bottom: 10px;"> <br><br><span style="display:inline-block; width: 150px; border-bottom: 1px solid #000;"></span></div>
                        <div style="border-bottom: 1px dashed #000; margin-bottom: 10px;"></div>
                        <div style="text-align: center; font-weight: bold;">امضاء</div>
                    </td>
                </tr>
            </table>
        </div>';

        $mpdf->WriteHTML($html);
    }

    $filename   = 'Labels_' . str_replace(['/', '\\'], '-', $examDate) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit; // Exit because we created a new mPDF instance here
}

function generateTestLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch Courses with Test type (تستی or تستی و تشریحی) with exam_seats count (not distinct students)
    // Only count students with written exams (کتبی), not electronic (الکترونیکی)
    // Use COUNT without DISTINCT to match table rows (one row per course per student)
    $stmt = $pdo->prepare("
        SELECT c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type,
               COUNT(CASE WHEN es.exam_type = 'کتبی' THEN 1 END) as seat_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ? AND c.course_type LIKE '%تستی%'
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        HAVING seat_count > 0
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        die('هیچ درس تستی برای این جلسه یافت نشد.');
    }

    // Calculate total seats (exam_seats rows) for test exams - matches sum of table
    $totalTestSeats = 0;
    foreach ($courses as $c) {
        $totalTestSeats += (int)($c['seat_count'] ?? 0);
    }

    // Get building statistics for this session (test courses only)
    // Count exam_seats rows (not distinct students) to match table totals
    $courseCodes  = array_column($courses, 'course_code');
    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $stmtBuilding = $pdo->prepare("
        SELECT es.building, COUNT(*) as seat_count
        FROM exam_seats es
        WHERE es.course_code IN ($placeholders) AND es.exam_type = 'کتبی'
        GROUP BY es.building
        ORDER BY es.building
    ");
    $stmtBuilding->execute($courseCodes);
    $buildingStats = $stmtBuilding->fetchAll(PDO::FETCH_ASSOC);

    // Format building stats string
    $buildingParts = [];
    foreach ($buildingStats as $bs) {
        $bName           = trim($bs['building'] ?? '') ?: 'نامشخص';
        $buildingParts[] = $bName . ': ' . toPersianDigits($bs['seat_count']);
    }
    $buildingStatsStr = implode(' | ', $buildingParts);

    // A5 Landscape
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'orientation' => 'L',
        'tempDir' => __DIR__ . '/../temp',
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));

    $htmlStyle = '
    <style>
        body { font-family: vazir; font-size: 10pt; line-height: 1.6; }
        .page { padding: 5px; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 8px; }
        .subtitle { font-size: 11pt; text-align: center; margin-bottom: 10px; }
        .info { font-size: 10pt; text-align: center; margin-bottom: 8px; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .courses-table th { background-color: #efefef; border: 1px solid #666; padding: 5px; font-weight: bold; }
        .courses-table td { border: 1px solid #666; padding: 4px; text-align: center; }
        .courses-table td.name { text-align: right; }
        .footer { text-align: center; font-size: 8pt; margin-top: 5px; }
    </style>';

    // Pagination
    $perPage    = 12;
    $chunks     = array_chunk($courses, $perPage);
    $totalPages = count($chunks);

    foreach ($chunks as $pageIndex => $chunk) {
        if ($pageIndex > 0)
            $mpdf->AddPage('L');

        $html = $htmlStyle . '
        <div class="page">
            <div class="title">برچسب پاکت اوراق تستی اسکن شده</div>
            <div class="subtitle">' . $university . '</div>
            <div class="info">
                تاریخ: <strong>' . toPersianDigits($examDate) . '</strong> &nbsp;&nbsp;&nbsp;
                ساعت: <strong>' . toPersianDigits($examTime) . '</strong> &nbsp;&nbsp;&nbsp;
                کل پاسخنامه‌های تستی این جلسه: <strong>' . toPersianDigits($totalTestSeats) . '</strong>
            </div>
            <div class="info" style="font-size: 9pt;">آمار به تفکیک ساختمان‌ها: ' . $buildingStatsStr . '</div>

            <table class="courses-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">ردیف</th>
                        <th style="width: 15%;">کد درس</th>
                        <th style="width: 42%;">نام درس</th>
                        <th style="width: 12%;">تعداد</th>
                        <th style="width: 23%;">حاضرین / غایبین</th>
                    </tr>
                </thead>
                <tbody>';

        $startRow = ($pageIndex * $perPage) + 1;
        foreach ($chunk as $i => $c) {
            $rowNum  = $startRow + $i;
            $count   = $c['seat_count'] ?? 0;
            $html   .= '<tr>
                <td>' . toPersianDigits($rowNum) . '</td>
                <td>' . toPersianDigits($c['course_code']) . '</td>
                <td class="name">' . ($c['course_name']) . '</td>
                <td>' . toPersianDigits($count) . '</td>
                <td>...... / ......</td>
            </tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        // Set page footer for page numbers (sticks to bottom)
        if ($totalPages > 1) {
            $mpdf->SetHTMLFooter('<div style="text-align: center; font-size: 8pt; font-family: vazir;">صفحه ' . toPersianDigits($pageIndex + 1) . ' از ' . toPersianDigits($totalPages) . '</div>');
        }

        $mpdf->WriteHTML($html);
    }

    $filename   = 'TestLabels_' . str_replace(['/', '\\'], '-', $examDate) . '_' . str_replace(':', '-', $examTime) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit; // Exit because we created a new mPDF instance here
}

/**
 * Generate Daily Test Labels Report (پاکت روزانه پاسخنامه‌های اسکن شده تستی)
 * Similar to generateTestLabels but includes ALL sessions for the given date
 * Uses A4 Portrait format with page numbers
 */
function generateDailyTestLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch all sessions for this date
    $stmtSessions = $pdo->prepare("
        SELECT DISTINCT exam_date, exam_time 
        FROM courses 
        WHERE exam_date = ? 
        ORDER BY exam_time
    ");
    $stmtSessions->execute([$examDate]);
    $sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sessions)) {
        die('هیچ جلسه‌ای برای این تاریخ یافت نشد.');
    }

    // Fetch all test courses for the entire day (excluding electronic exams)
    $stmt = $pdo->prepare("
        SELECT c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type,
               COUNT(CASE WHEN es.exam_type = 'کتبی' THEN es.student_id END) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.course_type LIKE '%تستی%'
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        HAVING student_count > 0
        ORDER BY c.exam_time, c.course_code
    ");
    $stmt->execute([$examDate]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        die('هیچ درس تستی کتبی برای این تاریخ یافت نشد.');
    }

    // Calculate total students for test exams
    $totalTestStudents = 0;
    foreach ($courses as $c) {
        $totalTestStudents += (int)($c['student_count'] ?? 0);
    }

    // Fetch building statistics for test exams (written only)
    $courseCodes   = array_column($courses, 'course_code');
    $buildingStats = [];
    if (!empty($courseCodes)) {
        $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';
        $stmtBuilding = $pdo->prepare("
            SELECT es.building, COUNT(es.student_id) as student_count
            FROM exam_seats es
            JOIN courses c ON es.course_code = c.course_code
            WHERE es.course_code IN ($placeholders) 
              AND es.exam_type = 'کتبی'
              AND c.course_type LIKE '%تستی%'
            GROUP BY es.building
            ORDER BY es.building
        ");
        $stmtBuilding->execute($courseCodes);
        while ($row = $stmtBuilding->fetch(PDO::FETCH_ASSOC)) {
            $buildingName                 = trim($row['building']) ?: 'بدون ساختمان';
            $buildingStats[$buildingName] = (int)$row['student_count'];
        }
    }

    // Build building stats display string
    $buildingStatsStr = '';
    if (!empty($buildingStats)) {
        $buildingParts = [];
        foreach ($buildingStats as $building => $count) {
            $buildingParts[] = $building . ': ' . toPersianDigits($count) . ' نفر';
        }
        $buildingStatsStr = implode(' | ', $buildingParts);
    }

    // A4 Portrait
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'P',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 10,
        'margin_bottom' => 15,
        'tempDir' => __DIR__ . '/../temp',
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));

    // Build list of session times for display
    $sessionTimes    = array_map(function ($s) {
        return $s['exam_time'];
    }, $sessions);
    $sessionTimesStr = implode(' - ', array_map('toPersianDigits', $sessionTimes));

    $htmlStyle = '
    <style>
        body { font-family: vazir; font-size: 10pt; line-height: 1.6; }
        .page { padding: 5px; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 8px; }
        .subtitle { font-size: 11pt; text-align: center; margin-bottom: 10px; }
        .info { font-size: 10pt; text-align: center; margin-bottom: 6px; }
        .building-stats { font-size: 9pt; text-align: center; margin-bottom: 10px; color: #333; background: #f5f5f5; padding: 4px 8px; border-radius: 4px; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .courses-table th { background-color: #efefef; border: 1px solid #666; padding: 5px; font-weight: bold; }
        .courses-table td { border: 1px solid #666; padding: 4px; text-align: center; }
        .courses-table td.name { text-align: right; }
        .footer { text-align: center; font-size: 8pt; margin-top: 5px; }
    </style>';

    // Pagination - more rows per page for A4
    $perPage    = 28; // Slightly less to accommodate building stats
    $chunks     = array_chunk($courses, $perPage);
    $totalPages = count($chunks);

    foreach ($chunks as $pageIndex => $chunk) {
        if ($pageIndex > 0)
            $mpdf->AddPage('P');

        $html = $htmlStyle . '
        <div class="page">
            <div class="title">پاکت روزانه پاسخنامه‌های اسکن شده تستی</div>
            <div class="subtitle">' . $university . '</div>
            <div class="info">
                تاریخ: <strong>' . toPersianDigits($examDate) . '</strong> &nbsp;&nbsp;&nbsp;
                ساعات: <strong>' . $sessionTimesStr . '</strong> &nbsp;&nbsp;&nbsp;
                کل دانشجویان تستی کتبی: <strong>' . toPersianDigits($totalTestStudents) . '</strong>
            </div>';

        // Add building statistics if available
        if (!empty($buildingStatsStr)) {
            $html .= '<div class="building-stats">آمار به تفکیک ساختمان‌ها : ' . $buildingStatsStr . '</div>';
        }

        $html .= '
            <table class="courses-table">
                <thead>
                    <tr>
                        <th style="width: 6%;">ردیف</th>
                        <th style="width: 10%;">ساعت</th>
                        <th style="width: 12%;">کد درس</th>
                        <th style="width: 42%;">نام درس</th>
                        <th style="width: 10%;">تعداد</th>
                        <th style="width: 20%;">حاضرین / غایبین</th>
                    </tr>
                </thead>
                <tbody>';

        $startRow = ($pageIndex * $perPage) + 1;
        foreach ($chunk as $i => $c) {
            $rowNum  = $startRow + $i;
            $count   = $c['student_count'] ?? 0;
            $html   .= '<tr>
                <td>' . toPersianDigits($rowNum) . '</td>
                <td>' . toPersianDigits($c['exam_time']) . '</td>
                <td>' . toPersianDigits($c['course_code']) . '</td>
                <td class="name">' . ($c['course_name']) . '</td>
                <td>' . toPersianDigits($count) . '</td>
                <td>...... / ......</td>
            </tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        // Set page footer for page numbers (sticks to bottom)
        $mpdf->SetHTMLFooter('<div style="text-align: center; font-size: 8pt; font-family: vazir;">صفحه ' . toPersianDigits($pageIndex + 1) . ' از ' . toPersianDigits($totalPages) . '</div>');

        $mpdf->WriteHTML($html);
    }

    $filename   = 'DailyTestLabels_' . str_replace(['/', '\\'], '-', $examDate) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

function generateProctorNotices($pdo, $mpdf, $config, $filterProctorIds = null)
{
    // Helper function to get Persian weekday name from Jalali date
    $getWeekday = function ($jalaliDate) {
        $asciiDate = toEnglishDigits($jalaliDate);
        $parts     = preg_split('/[\/\-]/u', $asciiDate);
        if (count($parts) < 3)
            return '';

        $jy = (int)($parts[0] ?? 0);
        $jm = (int)($parts[1] ?? 0);
        $jd = (int)($parts[2] ?? 0);
        if ($jy === 0 || $jm === 0 || $jd === 0)
            return '';

        try {
            $greg = jalali_to_gregorian($jy, $jm, $jd);
            if (!is_array($greg) || count($greg) < 3)
                return '';

            $ts = mktime(12, 0, 0, (int)$greg[1], (int)$greg[2], (int)$greg[0]);
            return jdate('l', $ts); // 'l' returns full weekday name in Persian
        } catch (Throwable $e) {
            return '';
        }
    };

    // Fetch Gender Map
    $genderMap = [];
    try {
        $stmt = $pdo->query("SELECT id, gender FROM Proctors");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $genderMap[$row['id']] = trim($row['gender']);
        }
    } catch (Exception $e) {
    }

    // Fetch Assignments - optionally filter by proctor IDs
    $sql = "SELECT proctor_id, proctor_name, exam_date, exam_time FROM ExamAssignments WHERE TRIM(IFNULL(proctor_name, '')) != ''";
    if ($filterProctorIds !== null && is_array($filterProctorIds) && count($filterProctorIds) > 0) {
        $placeholders  = implode(',', array_fill(0, count($filterProctorIds), '?'));
        $sql          .= " AND proctor_id IN ($placeholders)";
        $stmt          = $pdo->prepare($sql);
        $stmt->execute(array_map('intval', $filterProctorIds));
    } else {
        $stmt = $pdo->query($sql);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $proctors    = [];
    $globalTimes = [];
    $globalDates = [];

    foreach ($rows as $row) {
        $pid  = (int)$row['proctor_id'];
        $name = trim($row['proctor_name']);
        $date = trim($row['exam_date']);
        $time = trim($row['exam_time']);

        if ($name === '' || $date === '' || $time === '')
            continue;

        $globalTimes[$time] = $time;
        $globalDates[$date] = $date;

        $key = $pid > 0 ? 'id:' . $pid : 'name:' . md5($name);
        if (!isset($proctors[$key])) {
            $gender         = ($pid > 0 && isset($genderMap[$pid])) ? $genderMap[$pid] : '';
            $proctors[$key] = [
                'name' => $name,
                'gender' => $gender,
                'sessions' => [],
                'dates' => []
            ];
        }
        $proctors[$key]['sessions'][$date . '|' . $time] = true;
        $proctors[$key]['dates'][$date]                  = $date;
    }

    // Sort Global Times
    usort($globalTimes, function ($a, $b) {
        return strcmp($a, $b);
    });

    // Sort Proctors
    uasort($proctors, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    // Calculate Semester Info
    $semesterLabel = "نامشخص";
    $acadYearStr   = "";
    if (!empty($globalDates)) {
        ksort($globalDates);
        $firstDate = reset($globalDates);
        $parts     = explode('/', toEnglishDigits($firstDate));
        if (count($parts) >= 2) {
            $y = (int)$parts[0];
            $m = (int)$parts[1];

            if (in_array($m, [9, 10]))
                $semesterLabel = "نیمسال اول";
            elseif (in_array($m, [2, 3]))
                $semesterLabel = "نیمسال دوم";
            elseif (in_array($m, [5, 6]))
                $semesterLabel = "دوره تابستان";
            elseif ($m >= 7 && $m <= 12)
                $semesterLabel = "نیمسال اول";
            elseif ($m >= 1 && $m <= 4)
                $semesterLabel = "نیمسال دوم";

            if ($semesterLabel === "نیمسال اول") {
                $as = $y;
                $ae = $y + 1;
            } else {
                $as = $y - 1;
                $ae = $y;
            }
            $acadYearStr = toPersianDigits($ae) . '-' . toPersianDigits($as);
        }
    }
    $termPhrase = $semesterLabel . ($acadYearStr ? " سال‌تحصیلی " . $acadYearStr : "");

    // Config
    $chairName      = $config['Chairman'] ?? '________________';
    $complianceText = "لطفاً در کلیه جلسات امتحانی از همراه داشتن موبایل خودداری کنید. همراه داشتن جزوه یا کتاب جهت مطالعه در سر جلسه ممنوع است. ضمن حفظ سکوت، از صحبت با سایر عوامل و دانشجویان حاضر در جلسه پرهیز نمایید. حضور حداقل یک ربع پیش از شروع جلسه با اتیکت عکس‌دار نصب‌شده الزامی است.";

    // Create new mPDF instance for A5 Portrait
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'orientation' => 'P',
        'margin_left' => 6.2,
        'margin_right' => 6.2,
        'margin_top' => 5.5,
        'margin_bottom' => 5.5,
        'tempDir' => __DIR__ . '/../temp',
        'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'], [
            __DIR__ . '/../assets/fonts/vazir/Farsi-Digits'
        ]),
        'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + [
            'vazir' => [
                'R' => 'Vazir-Regular-FD.ttf',
                'B' => 'Vazir-Bold-FD.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazir'
    ]);
    $mpdf->SetDirectionality('rtl');

    $css = '
        body { font-family: vazir; font-size: 10pt; line-height: 1.6; }
        .greeting { font-size: 11pt; font-weight: bold; margin-bottom: 10px; text-align: right; }
        .term-line { font-size: 9pt; margin-bottom: 10px; text-align: justify; }
        .schedule-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 10px; }
        .schedule-table th { background-color: #f2f2f2; border: 1px solid #ccc; padding: 4px; text-align: center; font-weight: bold; }
        .schedule-table td { border: 1px solid #ccc; padding: 4px; text-align: center; }
        .footer-wrapper { position: fixed; bottom: 0; left: 0; right: 0; width: 100%; }
        .compliance-note { font-size: 8.5pt; text-align: justify; background: #f8f8f8; border: 1px dashed #ccc; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .signature-block { text-align: center; font-size: 10pt; font-weight: bold; }
        .signature-block .name { margin-top: 10px; font-size: 11pt; }
        .check-icon { width: 12px; height: 12px; }
    ';

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    $checkImg = '<img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiMwMGFhNzciIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48cG9seWxpbmUgcG9pbnRzPSIyMCA2IDkgMTcgNCAxMiI+PC9wb2x5bGluZT48L3N2Zz4=" class="check-icon" />';

    foreach ($proctors as $p) {
        $mpdf->AddPage();

        $prefix = "همکار ارجمند";
        if ($p['gender'] === 'مرد')
            $prefix .= " جناب آقای";
        elseif ($p['gender'] === 'زن')
            $prefix .= " سرکار خانم";

        $dates = $p['dates'] ?? [];
        ksort($dates);

        $sessionCount = count($p['sessions']);
        $dayCount     = count($dates);
        $countsText   = "($sessionCount جلسه در $dayCount روز)";

        $html  = '<div class="greeting">' . $prefix . ' ' . $p['name'] . '</div>';
        $html .= '<div class="term-line">بدینوسیله برنامه حضور شما در جلسات امتحانی (' . $termPhrase . ') به شرح ذیل اعلام می‌گردد ' . toPersianDigits($countsText) . ':</div>';

        // Build Matrix Table - added weekday column, reduced time column widths
        $timeColCount = count($globalTimes);
        $timeColWidth = $timeColCount > 0 ? floor(50 / $timeColCount) : 15; // Distribute 50% among time columns

        $html .= '<table class="schedule-table"><thead><tr><th style="width:8%">#</th><th style="width:20%">تاریخ</th><th style="width:22%">روز</th>';
        foreach ($globalTimes as $t) {
            $html .= '<th style="width:' . $timeColWidth . '%">' . toPersianDigits($t) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $idx = 1;
        foreach ($dates as $d) {
            $weekday  = $getWeekday($d);
            $html    .= '<tr>';
            $html    .= '<td>' . toPersianDigits($idx++) . '</td>';
            $html    .= '<td>' . toPersianDigits($d) . '</td>';
            $html    .= '<td>' . $weekday . '</td>';
            foreach ($globalTimes as $t) {
                $key   = $d . '|' . $t;
                $mark  = isset($p['sessions'][$key]) ? $checkImg : '';
                $html .= '<td>' . $mark . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="footer-wrapper">';
        $html .= '<div class="compliance-note">' . $complianceText . '</div>';

        $html .= '<div class="signature-block">
            <div>مسئول امتحانات مرکز</div>
            <div class="name">' . $chairName . '</div>
        </div>';
        $html .= '</div>';

        $mpdf->WriteHTML($html);
    }

    $filename   = 'ProctorNotices_' . date('Y-m-d_H-i-s') . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

/**
 * Generate Attendance Sheet Report (فهرست حضور و غیاب)
 * Two-column layout with student photos, names, exam details
 * Based on official PNU attendance sheet format
 */
function generateAttendanceSheet($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch SaadCode from config
    $saadCode = isset($config['SaadCode']) ? trim($config['SaadCode']) : '';
    if (empty($saadCode)) {
        die('SaadCode not configured');
    }

    // Base picture directory
    $picBaseDir = __DIR__ . '/../pic/' . $saadCode;

    // Ensure picture directory exists
    if (!is_dir($picBaseDir)) {
        if (!@mkdir($picBaseDir, 0755, true)) {
            error_log("Failed to create picture directory: {$picBaseDir}");
        }
    }

    // Fetch university and center info from config
    $university   = isset($config['University']) ? trim($config['University']) : 'دانشگاه پیام نور';
    $centerName   = isset($config['CenterName']) ? trim($config['CenterName']) : '';
    $provinceName = isset($config['ProvinceName']) ? trim($config['ProvinceName']) : '';

    // Fetch names for signatures from config
    // BossNickName = رئیس مرکز/دانشگاه, HeadOfEDU = رئیس آموزش, Chairman = مسئول امتحانات
    $bossName          = isset($config['BossNickName']) ? trim($config['BossNickName']) : '';
    $educationHeadName = isset($config['HeadOfEDU']) ? trim($config['HeadOfEDU']) : '';
    $examHeadName      = isset($config['Chairman']) ? trim($config['Chairman']) : '';
    $sessionHeadName   = ''; // مسئول جلسه - بدون اسم از پیش تعیین شده

    // Convert exam date to Persian date
    $dateParts = explode('/', toEnglishDigits($examDate));
    if (count($dateParts) === 3) {
        list($jy, $jm, $jd) = $dateParts;
        $persianDateStr     = toPersianDigits($jy . '/' . $jm . '/' . $jd);
    } else {
        $persianDateStr = toPersianDigits($examDate);
    }

    // Logo path - use PNU logo
    $logoPath   = __DIR__ . '/../assets/app/Pnulogo.png';
    $logoExists = file_exists($logoPath);

    // Check if grouping by course is enabled
    $groupByCourseEnabled = isset($config['GroupAttendanceByCourse']) && strtoupper($config['GroupAttendanceByCourse']) === 'YES';

    // First, count total pages across all locations for global page numbering
    $totalGlobalPages = 0;
    $locationData     = [];

    // Fetch all distinct locations (building + class_name combinations)
    $stmt = $pdo->prepare("
        SELECT DISTINCT es.building, es.class_name
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        ORDER BY es.building, es.class_name
    ");
    $stmt->execute([$examDate, $examTime]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($locations)) {
        die('No exam data found for the specified date and time');
    }

    // If grouping by course is enabled, we need to restructure the grouping
    // Build groups: if groupByCourse -> course_code + building + class_name, else building + class_name
    $groupedData = [];

    if ($groupByCourseEnabled) {
        // Fetch all students grouped by course first, then by location
        // Sort by building, class_name first so all courses from same class appear together
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.course_code, c.course_name, es.building, es.class_name
            FROM exam_seats es
            JOIN courses c ON es.course_code = c.course_code
            WHERE c.exam_date = ? AND c.exam_time = ?
            ORDER BY es.building, es.class_name, c.course_code
        ");
        $stmt->execute([$examDate, $examTime]);
        $courseLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courseLocations as $cl) {
            $groupKey      = $cl['course_code'] . '||' . $cl['building'] . '||' . $cl['class_name'];
            $groupedData[] = [
                'course_code' => $cl['course_code'],
                'course_name' => $cl['course_name'],
                'building' => $cl['building'],
                'class_name' => $cl['class_name'],
                'group_key' => $groupKey
            ];
        }
    } else {
        // Original behavior - group by location only
        foreach ($locations as $loc) {
            $groupedData[] = [
                'course_code' => null,
                'course_name' => null,
                'building' => $loc['building'],
                'class_name' => $loc['class_name'],
                'group_key' => $loc['building'] . '||' . $loc['class_name']
            ];
        }
    }

    // Check MultiExamMode config and build primary seat map
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';
    $primarySeatsMap      = []; // student_id => primary_seat_number

    if ($multiExamModeEnabled) {
        // Fetch all students with their seat numbers for this session
        $stmt = $pdo->prepare("
            SELECT es.student_id, es.seat_number
            FROM exam_seats es
            JOIN courses c ON es.course_code = c.course_code
            WHERE c.exam_date = ? AND c.exam_time = ?
        ");
        $stmt->execute([$examDate, $examTime]);
        $allSeats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by student_id
        $studentExams = [];
        foreach ($allSeats as $row) {
            $sid = $row['student_id'];
            if (!isset($studentExams[$sid])) {
                $studentExams[$sid] = [];
            }
            // Parse seat number
            $raw = $row['seat_number'];
            if (preg_match('/(\d+)/', $raw, $m)) {
                $studentExams[$sid][] = (int)$m[1];
            }
        }

        // For multi-exam students, find primary seat (minimum)
        foreach ($studentExams as $sid => $seats) {
            if (count($seats) > 1) {
                $primarySeatsMap[$sid] = min($seats);
            }
        }
    }

    // Pre-calculate total pages for all groups
    foreach ($groupedData as $idx => $group) {
        if ($groupByCourseEnabled && $group['course_code'] !== null) {
            // Count students for this course + location
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt
                FROM exam_seats es
                JOIN courses c ON es.course_code = c.course_code
                WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ? AND c.course_code = ?
            ");
            $stmt->execute([$examDate, $examTime, $group['building'], $group['class_name'], $group['course_code']]);
        } else {
            // Original query
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt
                FROM exam_seats es
                JOIN courses c ON es.course_code = c.course_code
                WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ?
            ");
            $stmt->execute([$examDate, $examTime, $group['building'], $group['class_name']]);
        }
        $count                       = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $pagesForGroup               = max(1, ceil($count / 18)); // 18 students per page
        $totalGlobalPages           += $pagesForGroup;
        $groupedData[$idx]['pages']  = $pagesForGroup;
    }

    $globalPageNumber = 0;

    // Process each group
    foreach ($groupedData as $group) {
        $building   = $group['building'];
        $className  = $group['class_name'];
        $courseCode = $group['course_code'];
        $courseName = $group['course_name'];

        // Fetch all students for this specific group
        if ($groupByCourseEnabled && $courseCode !== null) {
            $stmt = $pdo->prepare("
                SELECT 
                    es.student_id, 
                    s.first_name, 
                    s.last_name,
                    s.national_id,
                    s.destination_center,
                    s.source_center,
                    src.Center AS source_center_name,
                    es.seat_number,
                    es.class_name,
                    es.exam_type,
                    c.course_code,
                    c.course_name
                FROM exam_seats es
                JOIN students s ON es.student_id = s.student_id
                JOIN courses c ON es.course_code = c.course_code
                LEFT JOIN Centers src ON s.source_center = src.CenterID
                WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ? AND c.course_code = ?
                ORDER BY es.seat_number ASC
            ");
            $stmt->execute([$examDate, $examTime, $building, $className, $courseCode]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    es.student_id, 
                    s.first_name, 
                    s.last_name,
                    s.national_id,
                    s.destination_center,
                    s.source_center,
                    src.Center AS source_center_name,
                    es.seat_number,
                    es.class_name,
                    es.exam_type,
                    c.course_code,
                    c.course_name
                FROM exam_seats es
                JOIN students s ON es.student_id = s.student_id
                JOIN courses c ON es.course_code = c.course_code
                LEFT JOIN Centers src ON s.source_center = src.CenterID
                WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ?
                ORDER BY es.seat_number ASC
            ");
            $stmt->execute([$examDate, $examTime, $building, $className]);
        }
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            continue;
        }

        // Total students count for this location
        $totalStudentsInLocation = count($students);

        // Get exam types for this location
        $examTypes = [];
        foreach ($students as $stu) {
            $type = $stu['exam_type'] ?? 'کتبی';
            if (!in_array($type, $examTypes)) {
                $examTypes[] = $type;
            }
        }
        $examTypesStr = implode(' و ', $examTypes);

        // 18 students per page (2 columns × 9 rows)
        $pageSize              = 18;
        $pages                 = array_chunk($students, $pageSize);
        $totalPagesForLocation = count($pages);

        foreach ($pages as $pageIndex => $pageStudents) {
            $globalPageNumber++;

            // Check if this is the last page of entire report
            $isLastGlobalPage = ($globalPageNumber == $totalGlobalPages) ? true : false;

            // For pages after the first, add new page
            if ($globalPageNumber > 1) {
                $mpdf->AddPage();
            }

            // Build footer for THIS page
            $footerHtml = buildAttendanceFooter($globalPageNumber, $totalGlobalPages, $isLastGlobalPage, $bossName, $educationHeadName, $examHeadName);

            $html = '
            <style>
                @page { 
                    margin: 5mm 5mm 5mm 5mm;
                }
                body { font-family: vazir; font-size: 8pt; direction: rtl; margin: 0; padding: 0; }
                
                .page-wrapper { position: relative; min-height: 277mm; }
                
                .header-section { text-align: center; margin-bottom: 2mm; position: relative; }
                .logo-float { position: absolute; left: 0; top: 0; width: 12mm; }
                .university-name { font-weight: bold; font-size: 12pt; margin-bottom: 1mm; }
                .exam-info { font-size: 9pt; }
                .dynamic-value { font-weight: bold; }
                
                .students-table { width: 100%; border-collapse: collapse; border: 2px solid #000; }
                .students-table > tbody > tr > td { border: 0; border-left: 2px solid #000; padding: 0; vertical-align: top; width: 50%; }
                .students-table > tbody > tr > td:first-child { border-left: none; }
                
                .column-table { width: 100%; border-collapse: collapse; }
                .column-table td { border: 1px solid #000; padding: 1mm; vertical-align: middle; font-size: 7.5pt; line-height: 1.3; height: 25mm; }
                
                .student-row { height: 25mm; }
                .seat-col { width: 18%; text-align: center; vertical-align: middle; padding: 1mm !important; }
                .seat-number { font-weight: bold; font-size: 14pt; margin-bottom: 1mm; }
                
                .info-col { width: 62%; font-size: 7.5pt; line-height: 1.4; vertical-align: middle; padding: 1.5mm !important; }
                .exam-info-line { margin-bottom: 0.5mm; }
                .source-center-line { text-align: center; font-size: 8pt; font-weight: bold; margin-top: 1mm; }
                
                .photo-col { width: 20%; text-align: center; vertical-align: middle; padding: 1mm !important; }
                .photo-box { border: 1px solid #000; width: 16mm; height: 20mm; margin: 0 auto; overflow: hidden; background: #fff; }
                .student-photo { width: 16mm; height: 20mm; }
                .no-photo { width: 100%; height: 100%; background: #f9f9f9; line-height: 20mm; font-size: 6pt; color: #666; text-align: center; }
                
                .empty-row td { height: 25mm; border: none; }
                
                .footer-section { position: fixed; bottom: 0; left: 0; right: 0; }
            </style>';

            // Header section - logo floats, doesn't affect table width
            $html .= '<div class="header-section">';
            if ($logoExists) {
                $html .= '<img class="logo-float" src="' . $logoPath . '" />';
            }
            $html .= '<div class="university-name">' . $university . '</div>';
            // Build header info based on grouping mode
            $headerInfo = 'فهرست حضور و غیاب آزمون‌های <span class="dynamic-value">' . toPersianDigits($examTime) . '</span> | <span class="dynamic-value">' . $persianDateStr . '</span> مستقر در <span class="dynamic-value">' . $building . '</span> | <span class="dynamic-value">' . $className . '</span>';
            if ($groupByCourseEnabled && $courseName !== null) {
                $headerInfo .= ' | <span style="font-weight:bold;">درس: ' . $courseName . ' (' . toPersianDigits($courseCode) . ')</span>';
            }
            $html .= '<div class="exam-info">' . $headerInfo . '</div>';
            $html .= '</div>';

            // Split students into 2 columns: first 9 go to right column, rest to left
            $column1 = array_slice($pageStudents, 0, min(9, count($pageStudents)));
            $column2 = array_slice($pageStudents, 9);

            // Do NOT add empty rows - leave space empty at end

            // Two-column table with thick border between columns
            $html .= '<table class="students-table">';
            $html .= '<tr>';

            // Right column (first half of students)
            $html .= '<td style="width: 50%; vertical-align: top;">';
            $html .= '<table class="column-table">';
            foreach ($column1 as $student) {
                $html .= renderAttendanceStudentRow($student, $picBaseDir, $saadCode, $primarySeatsMap);
            }
            $html .= '</table>';
            $html .= '</td>';

            // Left column (second half of students)
            $html .= '<td style="width: 50%; vertical-align: top;">';
            $html .= '<table class="column-table">';
            foreach ($column2 as $student) {
                $html .= renderAttendanceStudentRow($student, $picBaseDir, $saadCode, $primarySeatsMap);
            }
            $html .= '</table>';
            $html .= '</td>';

            $html .= '</tr>';
            $html .= '</table>';

            // Footer embedded in HTML with position fixed
            $html .= '<div class="footer-section">' . $footerHtml . '</div>';

            $mpdf->WriteHTML($html);
        }
    }

    $filename   = 'AttendanceSheet_' . date('Y-m-d_H-i-s') . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

/**
 * Build footer HTML for attendance sheet (without wrapper div for fixed positioning)
 */
function buildAttendanceFooter($pageNum, $totalPages, $isLastPage, $bossName, $educationHeadName, $examHeadName)
{
    $footerHtml = '<div style="font-family: vazir; direction: rtl; font-size: 8pt; width: 100%; border: 2px solid #000; padding: 2mm; background: #fff;">';

    // Attendance counts - table layout
    $footerHtml .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 2mm;">';
    $footerHtml .= '<tr>';
    $footerHtml .= '<td style="width: 50%; text-align: right; font-size: 8pt;">تعداد حاضرین ........ و غایبین ........ نفر</td>';
    if ($isLastPage === true) {
        $footerHtml .= '<td style="width: 50%; text-align: left; font-size: 8pt;">تعداد حاضرین کل ........ نفر و غایبین کل ........ نفر</td>';
    } else {
        $footerHtml .= '<td style="width: 50%;"></td>';
    }
    $footerHtml .= '</tr>';
    $footerHtml .= '</table>';

    // Signature boxes - 4 columns - TALLER boxes (22mm)
    $footerHtml .= '<table style="width: 100%; border-collapse: collapse;">';
    $footerHtml .= '<tr>';
    $footerHtml .= '<td style="text-align: center; width: 25%; padding: 1mm;"><br>';
    $footerHtml .= '<div style="border: 1px solid #000; height: 22mm; margin-bottom: 1mm;"></div>';
    $footerHtml .= '<div style="font-size: 7pt;">رئیس مرکز / واحد</div>';
    $footerHtml .= '<div style="font-size: 7pt; font-weight: bold;">' . $bossName . '</div>';
    $footerHtml .= '<br><br>';
    $footerHtml .= '</td>';
    $footerHtml .= '<td style="text-align: center; width: 25%; padding: 1mm;"><br>';
    $footerHtml .= '<div style="border: 1px solid #000; height: 22mm; margin-bottom: 1mm;"></div>';
    $footerHtml .= '<div style="font-size: 7pt;">رئیس اداره آموزش</div>';
    $footerHtml .= '<div style="font-size: 7pt; font-weight: bold;">' . $educationHeadName . '</div>';
    $footerHtml .= '<br><br>';
    $footerHtml .= '</td>';
    $footerHtml .= '<td style="text-align: center; width: 25%; padding: 1mm;"><br>';
    $footerHtml .= '<div style="border: 1px solid #000; height: 22mm; margin-bottom: 1mm;"></div>';
    $footerHtml .= '<div style="font-size: 7pt;">مسئول امتحانات</div>';
    $footerHtml .= '<div style="font-size: 7pt; font-weight: bold;">' . $examHeadName . '</div>';
    $footerHtml .= '<br><br>';
    $footerHtml .= '</td>';
    $footerHtml .= '<td style="text-align: center; width: 25%; padding: 1mm;"><br>';
    $footerHtml .= '<div style="border: 1px solid #000; height: 22mm; margin-bottom: 1mm;"></div>';
    $footerHtml .= '<div style="font-size: 7pt;">مراقب</div>';
    $footerHtml .= '<div style="font-size: 7pt;">&nbsp;</div>';
    $footerHtml .= '<br><br>';
    $footerHtml .= '</td>';
    $footerHtml .= '</tr>';
    $footerHtml .= '</table>';

    $footerHtml .= '</div>';
    $footerHtml .= '<div style="font-family: vazir; text-align: left; font-size: 7pt; margin-top: 1mm;">صفحه ' . toPersianDigits($pageNum) . ' از ' . toPersianDigits($totalPages) . '</div>';

    return $footerHtml;
}

/**
 * Helper function to render a single student row in the attendance sheet
 * Layout: Photo (left) | Info (middle) | Seat# & Name (right)
 * In RTL, the order in HTML is reversed
 */
function renderAttendanceStudentRow($student, $picBaseDir, $saadCode, $primarySeatsMap = [])
{
    if ($student === null) {
        // Empty row - no borders, just empty space
        return '<tr class="empty-row"><td></td><td></td><td></td></tr>';
    }

    $studentId         = $student['student_id'];
    $nationalId        = $student['national_id'] ?? '';
    $firstName         = $student['first_name'];
    $lastName          = $student['last_name'];
    $seatNum           = $student['seat_number'];
    $courseCode        = $student['course_code'];
    $courseName        = $student['course_name'];
    $destinationCenter = isset($student['destination_center']) ? trim((string)$student['destination_center']) : '';
    $sourceCenter      = isset($student['source_center']) ? trim((string)$student['source_center']) : '';
    $sourceCenterName  = isset($student['source_center_name']) ? trim($student['source_center_name']) : '';
    $showSourceCenter  = $destinationCenter !== '' && $sourceCenter !== '' && $destinationCenter !== $sourceCenter && $sourceCenterName !== '';

    // Check if this student has a primary seat (multi-exam student)
    $primarySeat = isset($primarySeatsMap[$studentId]) ? $primarySeatsMap[$studentId] : null;

    // Parse current seat number to check if it equals primary seat
    $currentSeatNum = 0;
    if (preg_match('/(\d+)/', $seatNum, $m)) {
        $currentSeatNum = (int)$m[1];
    }
    // Only show primary seat label if current seat is NOT the primary seat
    $showPrimarySeatLabel = ($primarySeat !== null && $currentSeatNum !== $primarySeat);

    $html = '<tr class="student-row">';

    // Right cell: Seat number (big) + student ID + national ID + primary seat (if applicable)
    $html .= '<td class="seat-col">';
    $html .= '<div class="seat-number">' . toPersianDigits($seatNum) . '</div>';
    $html .= '<div style="font-size: 6pt; margin-top: 1mm;">' . toPersianDigits($studentId) . '</div>';
    $html .= '<div style="font-size: 6pt;">' . toPersianDigits($nationalId) . '</div>';
    if ($showPrimarySeatLabel) {
        $html .= '<div style="font-size: 6pt; margin-top: 1mm; background: #fff; color: #000; padding: 2px 0; text-align: center;">استقرار: ' . toPersianDigits($primarySeat) . '</div>';
    }
    $html .= '</td>';

    // Middle cell: Student name (centered, larger font) + Course info
    $html .= '<td class="info-col">';
    $html .= '<div style="text-align: center; font-size: 9pt; font-weight: bold; margin-bottom: 2mm;">' . $firstName . ' ' . $lastName . '</div>';
    $html .= '<div class="exam-info-line">' . toPersianDigits($courseCode) . '</div>';
    $html .= '<div class="exam-info-line">' . $courseName . '</div>';
    if ($showSourceCenter) {
        $html .= '<div class="source-center-line">' . $sourceCenterName . '</div>';
    }
    $html .= '</td>';

    // Left cell: Photo with border box
    $html      .= '<td class="photo-col">';
    $html      .= '<div class="photo-box">';
    $photoPath  = $picBaseDir . '/' . $studentId . '.jpg';

    // Check if file exists and has reasonable size (at least 100 bytes)
    $showPhoto = false;
    if (@file_exists($photoPath)) {
        $fileSize = @filesize($photoPath);
        if ($fileSize !== false && $fileSize > 100) {
            $showPhoto = true;
        }
    }

    if ($showPhoto) {
        $html .= '<img class="student-photo" src="' . $photoPath . '" />';
    } else {
        $html .= '<div class="no-photo">بدون عکس</div>';
    }
    $html .= '</div>';
    $html .= '</td>';

    $html .= '</tr>';

    return $html;
}

/**
 * Generate session summary report with "شروع از" column and footer only on last page
 * Also includes answer sheet summary table
 */
function generateSessionSummaryReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Check if location-based report mode is enabled
    $locationMode = isset($config['ReproductionReportMode']) && strtolower($config['ReproductionReportMode']) === 'location';

    if ($locationMode) {
        generateSessionSummaryReportByLocation($pdo, $mpdf, $examDate, $examTime, $config);
        return;
    }

    // Default: course-based report
    // Fetch Courses with min class (for "شروع از" column)
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            MAX(es.exam_type) AS exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count,
            MIN(es.class_name) as min_class
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        die('No courses found for this session.');
    }

    // Sort logic (Electronic first)
    usort($courses, function ($a, $b) {
        $typeA = $a['exam_type'] ?? '';
        $typeB = $b['exam_type'] ?? '';
        if ($typeA === $typeB) {
            return (int)$a['course_code'] - (int)$b['course_code'];
        }
        if ($typeA === 'الکترونیکی')
            return -1;
        if ($typeB === 'الکترونیکی')
            return 1;
        return strcmp($typeA, $typeB);
    });

    // Calculate answer sheet counts
    $testCount               = 0;
    $descriptiveCount        = 0;
    $testDescriptiveCount    = 0;
    $electronicTestCount     = 0;
    $electronicTestDescCount = 0;

    foreach ($courses as $course) {
        $ct           = $course['course_type'] ?? '';
        $count        = (int)($course['student_count'] ?? 0);
        $isElectronic = ($course['exam_type'] ?? '') === 'الکترونیکی';

        if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
            $testDescriptiveCount += $count;
            if ($isElectronic) {
                $electronicTestDescCount += $count;
            }
        } elseif (stripos($ct, 'تستی') !== false) {
            $testCount += $count;
            if ($isElectronic) {
                $electronicTestCount += $count;
            }
        } elseif (stripos($ct, 'تشریحی') !== false) {
            $descriptiveCount += $count;
        }
    }

    // Total answer sheets (subtract electronic exams from test sheets - they don't have physical answer sheets)
    $totalTestSheets        = $testCount + $testDescriptiveCount - $electronicTestCount - $electronicTestDescCount;
    $totalDescriptiveSheets = $descriptiveCount + $testDescriptiveCount;

    // Calculate Semester/Year
    $semesterLabel = "نامشخص";
    $partsDate     = explode('/', toEnglishDigits($examDate));
    $year          = isset($partsDate[0]) ? (int)$partsDate[0] : 0;
    $month         = isset($partsDate[1]) ? (int)$partsDate[1] : 0;

    if (in_array($month, [9, 10]))
        $semesterLabel = "نیمسال اول";
    elseif (in_array($month, [2, 3]))
        $semesterLabel = "نیمسال دوم";
    elseif (in_array($month, [5, 6]))
        $semesterLabel = "دوره تابستان";
    else {
        if ($month >= 7 && $month <= 12)
            $semesterLabel = "نیمسال اول";
        elseif ($month >= 1 && $month <= 4)
            $semesterLabel = "نیمسال دوم";
    }

    if ($semesterLabel === "نیمسال اول") {
        $acadStart = $year;
        $acadEnd   = $year + 1;
    } else {
        $acadStart = $year - 1;
        $acadEnd   = $year;
    }
    $acadYearStr = toPersianDigits($acadEnd) . '-' . toPersianDigits($acadStart);

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));
    $bossName   = $config['BossNickName'] ?? '________________';
    $headName   = $config['HeadOfEDU'] ?? '________________';
    $chairName  = $config['Chairman'] ?? '________________';

    // Pagination - 15 items per page like regular session report
    $perPage      = 18;
    $totalCourses = count($courses);
    $chunks       = array_chunk($courses, $perPage);

    if (empty($chunks)) {
        $chunks = [$courses];
    }

    $totalPages = count($chunks);

    $baseStyle = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { width: 100%; border-bottom: 1px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header-table { width: 100%; }
        .logo { width: 80px; }
        .title { font-size: 16pt; font-weight: bold; padding-bottom: 20px; margin-bottom: 20px; }
        .meta { text-align: right; margin-bottom: 10px; font-size: 10pt; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; table-layout: fixed; }
        .courses-table th { background-color: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; white-space: nowrap; overflow: hidden; }
        .courses-table td { border: 1px solid #ccc; padding: 6px 4px; text-align: center; white-space: nowrap; overflow: hidden; }
        .courses-table td.name { text-align: right; }
        .courses-table tr.electronic { background-color: #e8f5e9; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; margin-bottom: 10px; }
        .summary-table th, .summary-table td { border: 1px solid #ccc; padding: 5px; text-align: center; }
        .summary-table th { background-color: #efefef; font-weight: bold; }
        .footer-signs { width: 100%; margin-top: 8px; }
        .bottom-section { width: 100%; margin-top: 15px; }
    </style>
    ';

    foreach ($chunks as $index => $chunk) {
        if ($index > 0)
            $mpdf->AddPage();

        $isLastPage = ($index === $totalPages - 1);

        $pageHtml = $baseStyle;

        // Header
        $pageHtml .= '
        <div class="header">
            <table class="header-table">
                <tr>
                    <td style="width: 20%; text-align: center; border: none;">
                        <img src="../assets/app/Pnulogo.png" class="logo"><br>
                        <span style="font-size: 9pt; font-weight: bold;">مرکز سنجش و آزمون</span>
                    </td>
                    <td style="width: 60%; text-align: center; border: none;">
                        <div class="title">صورتجلسه آزمون</div>
                        <br>
                        <div style="font-size: 12pt;">' . $university . '</div>
                    </td>
                    <td style="width: 20%; border: none;"></td>
                </tr>
            </table>
        </div>';

        $pageHtml .= '<div class="meta">آزمون دروس زیر در ' . $semesterLabel . ' سالتحصیلی ' . $acadYearStr . ' با حضور امضاء کنندگان زیر در ساعت ' . toPersianDigits($examTime) . ' مورخ ' . toPersianDigits($examDate) . ' شروع گردید. (نمونه سوال ضمیمه می باشد)</div>';

        // Table with "شروع از" column
        $pageHtml .= '<table class="courses-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 12%;">نوع درس</th>
                    <th style="width: 13%;">شروع از</th>
                    <th style="width: 10%;">کد درس</th>
                    <th style="width: 40%;">نام درس</th>
                    <th style="width: 8%;">تعداد</th>
                    <th style="width: 12%;">حاضر / غایب</th>
                </tr>
            </thead>
            <tbody>';

        // Calculate startRow based on previous chunks
        $startRow = 1;
        for ($ci = 0; $ci < $index; $ci++) {
            $startRow += count($chunks[$ci]);
        }
        foreach ($chunk as $i => $course) {
            $rowNum        = $startRow + $i;
            $count         = $course['student_count'] ?? 0;
            $minClass      = $course['min_class'] ?? '-';
            $isElectronic  = ($course['exam_type'] ?? '') === 'الکترونیکی';
            $rowClass      = $isElectronic ? ' class="electronic"' : '';
            $pageHtml     .= '<tr' . $rowClass . '>
                <td>' . toPersianDigits($rowNum) . '</td>
                <td>' . ($course['course_type'] ?? '') . '</td>
                <td>' . $minClass . '</td>
                <td>' . toPersianDigits($course['course_code']) . '</td>
                <td class="name">' . ($course['course_name']) . '</td>
                <td>' . toPersianDigits($count) . '</td>
                <td> ___ / ___ </td>
            </tr>';
        }
        $pageHtml .= '</tbody></table>';

        // Summary table only on last page
        if ($isLastPage) {
            $pageHtml .= '
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 40%;"></th>
                        <th style="width: 20%;">تعداد کل</th>
                        <th style="width: 40%;">مجموع حاضرین / مجموع غایبین</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>پاسخنامه‌های تستی</td>
                        <td>' . toPersianDigits($totalTestSheets) . '</td>
                        <td>___________ / ___________</td>
                    </tr>
                    <tr>
                        <td>پاسخنامه‌های تشریحی</td>
                        <td>' . toPersianDigits($totalDescriptiveSheets) . '</td>
                        <td>___________ / ___________</td>
                    </tr>
                </tbody>
            </table>';
        }

        // Signature HTML for footer (on every page)
        $signatureHtml = '<div style="border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 8px; text-align: center; font-size: 9pt;">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>' .
            '<table style="width: 100%; border: none;">' .
            '<tr>' .
            '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ' . $bossName . '</td>' .
            '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
            '</tr>' .
            '<tr>' .
            '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس اداره آموزش: ' . $headName . '</td>' .
            '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
            '</tr>' .
            '<tr>' .
            '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی مسئول جلسه: ' . $chairName . '</td>' .
            '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
            '</tr>' .
            '<tr>' .
            '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی ناظران/مراقبان جلسه:</td>' .
            '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
            '</tr>' .
            '<tr>' .
            '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</td>' .
            '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
            '</tr>' .
            '</table>';

        // Page Number
        $pageNumberHtml = '<div style="background-color: #444; color: #fff; text-align: center; padding: 5px; font-size: 9pt; margin-top: 15px;">صفحه ' . toPersianDigits($index + 1) . ' از ' . toPersianDigits($totalPages) . '</div>';

        // Signatures on every page, page number below
        $mpdf->SetHTMLFooter($signatureHtml . $pageNumberHtml);

        $mpdf->WriteHTML($pageHtml);
    }
}

/**
 * Generate session summary report grouped by building (location mode)
 */
function generateSessionSummaryReportByLocation($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch all exam seats with course info for this session
    $stmt = $pdo->prepare("
        SELECT 
            es.course_code,
            es.building,
            es.class_name,
            es.student_id,
            c.course_name,
            c.course_type,
            es.exam_type
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
    ");
    $stmt->execute([$examDate, $examTime]);
    $allSeats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allSeats)) {
        die('No exam seats found for this session.');
    }

    // Group by building
    $buildings = [];
    foreach ($allSeats as $seat) {
        $building = trim($seat['building'] ?? '') ?: 'بدون ساختمان';

        if (!isset($buildings[$building])) {
            $buildings[$building] = [
                'building' => $building,
                'courses' => [],
                'seats' => []
            ];
        }

        $courseCode = $seat['course_code'];
        if (!isset($buildings[$building]['courses'][$courseCode])) {
            $buildings[$building]['courses'][$courseCode] = [
                'course_code' => $courseCode,
                'course_name' => $seat['course_name'],
                'course_type' => $seat['course_type'],
                'exam_type' => $seat['exam_type'],
                'student_count' => 0,
                'classes' => []
            ];
        }
        $buildings[$building]['courses'][$courseCode]['student_count']++;

        // Track classes for this course
        $className = $seat['class_name'] ?? '';
        if ($className && !in_array($className, $buildings[$building]['courses'][$courseCode]['classes'])) {
            $buildings[$building]['courses'][$courseCode]['classes'][] = $className;
        }
    }

    // Sort buildings by name
    uksort($buildings, 'strcmp');

    // Calculate Semester/Year
    $semesterLabel = "نامشخص";
    $partsDate     = explode('/', toEnglishDigits($examDate));
    $year          = isset($partsDate[0]) ? (int)$partsDate[0] : 0;
    $month         = isset($partsDate[1]) ? (int)$partsDate[1] : 0;

    if (in_array($month, [9, 10]))
        $semesterLabel = "نیمسال اول";
    elseif (in_array($month, [2, 3]))
        $semesterLabel = "نیمسال دوم";
    elseif (in_array($month, [5, 6]))
        $semesterLabel = "دوره تابستان";
    else {
        if ($month >= 7 && $month <= 12)
            $semesterLabel = "نیمسال اول";
        elseif ($month >= 1 && $month <= 4)
            $semesterLabel = "نیمسال دوم";
    }

    if ($semesterLabel === "نیمسال اول") {
        $acadStart = $year;
        $acadEnd   = $year + 1;
    } else {
        $acadStart = $year - 1;
        $acadEnd   = $year;
    }
    $acadYearStr = toPersianDigits($acadEnd) . '-' . toPersianDigits($acadStart);

    // Config Values
    $university = $config['University'] ?? 'دانشگاه پیام نور';
    $university = trim(preg_replace('/^نسار\s*-\s*/u', '', $university));
    $bossName   = $config['BossNickName'] ?? '________________';
    $headName   = $config['HeadOfEDU'] ?? '________________';
    $chairName  = $config['Chairman'] ?? '________________';

    $baseStyle = '
    <style>
        body { font-family: vazir; font-size: 10pt; }
        .header { width: 100%; border-bottom: 1px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header-table { width: 100%; }
        .logo { width: 80px; }
        .title { font-size: 16pt; font-weight: bold; margin-bottom: 8px; }
        .university-name { font-size: 12pt; margin-bottom: 6px; }
        .location-name { font-size: 10pt; font-weight: bold; color: #333; margin-top: 4px; }
        .meta { text-align: right; margin-bottom: 10px; font-size: 10pt; }
        .courses-table { width: 100%; border-collapse: collapse; font-size: 9pt; table-layout: fixed; }
        .courses-table th { background-color: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; white-space: nowrap; overflow: hidden; }
        .courses-table td { border: 1px solid #ccc; padding: 6px 4px; text-align: center; white-space: nowrap; overflow: hidden; }
        .courses-table td.name { text-align: right; }
        .courses-table tr.electronic { background-color: #e8f5e9; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; margin-bottom: 10px; }
        .summary-table th, .summary-table td { border: 1px solid #ccc; padding: 5px; text-align: center; }
        .summary-table th { background-color: #efefef; font-weight: bold; }
        .footer-signs { width: 100%; margin-top: 8px; }
        .bottom-section { width: 100%; margin-top: 15px; }
    </style>
    ';

    $locationIndex  = 0;
    $totalLocations = count($buildings);

    foreach ($buildings as $buildingName => $buildingData) {
        $locationIndex++;
        $locationLabel = $buildingData['building'];

        // Convert courses array to indexed array and sort
        $courses = array_values($buildingData['courses']);

        // Sort logic (Electronic first)
        usort($courses, function ($a, $b) {
            $typeA = $a['exam_type'] ?? '';
            $typeB = $b['exam_type'] ?? '';
            if ($typeA === $typeB) {
                return (int)$a['course_code'] - (int)$b['course_code'];
            }
            if ($typeA === 'الکترونیکی')
                return -1;
            if ($typeB === 'الکترونیکی')
                return 1;
            return strcmp($typeA, $typeB);
        });

        // Find min class for each course
        foreach ($courses as &$course) {
            sort($course['classes']);
            $course['min_class'] = !empty($course['classes']) ? $course['classes'][0] : '-';
        }
        unset($course);

        // Calculate answer sheet counts for this building
        $testCount               = 0;
        $descriptiveCount        = 0;
        $testDescriptiveCount    = 0;
        $electronicTestCount     = 0;
        $electronicTestDescCount = 0;

        foreach ($courses as $course) {
            $ct           = $course['course_type'] ?? '';
            $count        = (int)($course['student_count'] ?? 0);
            $isElectronic = ($course['exam_type'] ?? '') === 'الکترونیکی';

            if (stripos($ct, 'تستی') !== false && stripos($ct, 'تشریحی') !== false) {
                $testDescriptiveCount += $count;
                if ($isElectronic) {
                    $electronicTestDescCount += $count;
                }
            } elseif (stripos($ct, 'تستی') !== false) {
                $testCount += $count;
                if ($isElectronic) {
                    $electronicTestCount += $count;
                }
            } elseif (stripos($ct, 'تشریحی') !== false) {
                $descriptiveCount += $count;
            }
        }

        // Total answer sheets (subtract electronic exams - they don't have physical answer sheets)
        $totalTestSheets        = $testCount + $testDescriptiveCount - $electronicTestCount - $electronicTestDescCount;
        $totalDescriptiveSheets = $descriptiveCount + $testDescriptiveCount;

        // Pagination - 15 items per page like regular session report
        $perPage      = 18;
        $totalCourses = count($courses);
        $chunks       = array_chunk($courses, $perPage);

        if (empty($chunks)) {
            $chunks = [$courses];
        }

        $totalPages = count($chunks);

        foreach ($chunks as $pageIndex => $chunk) {
            if ($locationIndex > 1 || $pageIndex > 0)
                $mpdf->AddPage();

            $isLastPage = ($pageIndex === $totalPages - 1);

            $pageHtml = $baseStyle;

            // Header with location name
            $pageHtml .= '
            <div class="header">
                <table class="header-table">
                    <tr>
                        <td style="width: 20%; text-align: center; border: none;">
                            <img src="../assets/app/Pnulogo.png" class="logo"><br>
                            <span style="font-size: 9pt; font-weight: bold;">مرکز سنجش و آزمون</span>
                        </td>
                        <td style="width: 60%; text-align: center; border: none;">
                            <div class="title">صورتجلسه آزمون</div>
                            <div class="university-name">' . $university . '</div>
                            <div class="location-name">' . $locationLabel . '</div>
                        </td>
                        <td style="width: 20%; border: none;"></td>
                    </tr>
                </table>
            </div>';

            $pageHtml .= '<div class="meta">آزمون دروس زیر در ' . $semesterLabel . ' سالتحصیلی ' . $acadYearStr . ' با حضور امضاء کنندگان زیر در ساعت ' . toPersianDigits($examTime) . ' مورخ ' . toPersianDigits($examDate) . ' شروع گردید. (نمونه سوال ضمیمه می باشد)</div>';

            // Table with "شروع از" column
            $pageHtml .= '<table class="courses-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 12%;">نوع درس</th>
                        <th style="width: 13%;">شروع از</th>
                        <th style="width: 10%;">کد درس</th>
                        <th style="width: 40%;">نام درس</th>
                        <th style="width: 8%;">تعداد</th>
                        <th style="width: 12%;">حاضر / غایب</th>
                    </tr>
                </thead>
                <tbody>';

            // Calculate startRow based on previous chunks within this building
            $startRow = 1;
            for ($ci = 0; $ci < $pageIndex; $ci++) {
                $startRow += count($chunks[$ci]);
            }
            foreach ($chunk as $i => $course) {
                $rowNum        = $startRow + $i;
                $count         = $course['student_count'] ?? 0;
                $minClass      = $course['min_class'] ?? '-';
                $isElectronic  = ($course['exam_type'] ?? '') === 'الکترونیکی';
                $rowClass      = $isElectronic ? ' class="electronic"' : '';
                $pageHtml     .= '<tr' . $rowClass . '>
                    <td>' . toPersianDigits($rowNum) . '</td>
                    <td>' . ($course['course_type'] ?? '') . '</td>
                    <td>' . $minClass . '</td>
                    <td>' . toPersianDigits($course['course_code']) . '</td>
                    <td class="name">' . ($course['course_name']) . '</td>
                    <td>' . toPersianDigits($count) . '</td>
                    <td> ___ / ___ </td>
                </tr>';
            }
            $pageHtml .= '</tbody></table>';

            // Summary table only on last page of this building
            if ($isLastPage) {
                $pageHtml .= '
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;"></th>
                            <th style="width: 20%;">تعداد کل</th>
                            <th style="width: 40%;">مجموع حاضرین / مجموع غایبین</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>پاسخنامه‌های تستی</td>
                            <td>' . toPersianDigits($totalTestSheets) . '</td>
                            <td>___________ / ___________</td>
                        </tr>
                        <tr>
                            <td>پاسخنامه‌های تشریحی</td>
                            <td>' . toPersianDigits($totalDescriptiveSheets) . '</td>
                            <td>___________ / ___________</td>
                        </tr>
                    </tbody>
                </table>';
            }

            // Signature HTML for footer (on every page)
            $signatureHtml = '<div style="border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 8px; text-align: center; font-size: 9pt;">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>' .
                '<table style="width: 100%; border: none;">' .
                '<tr>' .
                '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ' . $bossName . '</td>' .
                '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
                '</tr>' .
                '<tr>' .
                '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی رئیس اداره آموزش: ' . $headName . '</td>' .
                '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
                '</tr>' .
                '<tr>' .
                '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی مسئول جلسه: ' . $chairName . '</td>' .
                '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
                '</tr>' .
                '<tr>' .
                '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی ناظران/مراقبان جلسه:</td>' .
                '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
                '</tr>' .
                '<tr>' .
                '<td style="border: none; text-align: right; padding: 6px;">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</td>' .
                '<td style="border: none; text-align: left; padding: 6px;">امضاء</td>' .
                '</tr>' .
                '</table>';

            // Page Number with location info
            $footerText = 'مکان ' . toPersianDigits($locationIndex) . ' از ' . toPersianDigits($totalLocations);
            if ($totalPages > 1) {
                $footerText .= ' | صفحه ' . toPersianDigits($pageIndex + 1) . ' از ' . toPersianDigits($totalPages);
            }
            $pageNumberHtml = '<div style="background-color: #444; color: #fff; text-align: center; padding: 5px; font-size: 9pt; margin-top: 15px;">' . $footerText . '</div>';

            // Signatures on every page, page number below
            $mpdf->SetHTMLFooter($signatureHtml . $pageNumberHtml);

            $mpdf->WriteHTML($pageHtml);
        }
    }
}

/**
 * Generate Exam Booklet Report - All sessions grouped by date/time
 * Electronic courses first (green), then written courses
 * Session totals: تستی، تشریحی، تستی‌تشریحی، الکترونیکی، کتبی، کل
 * Daily totals at end of each day
 * @param string $filter - 'all', 'electronic', 'written'
 */
function generateExamBookletReport($pdo, $mpdf, $config, $filter = 'all')
{
    $universityName = $config['University'] ?? 'دانشگاه';

    // Check if exam_type exists in exam_seats
    $hasExamType = false;
    try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_seats' AND COLUMN_NAME = 'exam_type'");
        $colStmt->execute();
        $hasExamType = (bool)$colStmt->fetchColumn();
    } catch (Exception $e) {
    }

    // Build HAVING clause for filter
    $having      = "";
    $havingParam = [];
    if ($hasExamType && $filter === 'electronic') {
        $having      = "HAVING MAX(es.exam_type) = ?";
        $havingParam = ['الکترونیکی'];
    } elseif ($hasExamType && $filter === 'written') {
        $having      = "HAVING MAX(es.exam_type) = ?";
        $havingParam = ['کتبی'];
    }

    // Fetch all courses with student counts, grouped by session
    $sql  = "
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            c.course_type,
            MAX(es.exam_type) AS exam_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
        $having
        ORDER BY c.exam_date ASC, c.exam_time ASC, 
            CASE WHEN MAX(es.exam_type) = 'الکترونیکی' THEN 0 ELSE 1 END,
            c.course_code ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($havingParam);
    $allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allCourses)) {
        die('No courses found.');
    }

    // Group by date then time
    $groupedByDate = [];
    foreach ($allCourses as $course) {
        $date = $course['exam_date'];
        $time = $course['exam_time'];
        if (!isset($groupedByDate[$date])) {
            $groupedByDate[$date] = [];
        }
        if (!isset($groupedByDate[$date][$time])) {
            $groupedByDate[$date][$time] = [];
        }
        $groupedByDate[$date][$time][] = $course;
    }

    // Sort dates
    ksort($groupedByDate);

    // Set page footer with dark background
    $mpdf->SetHTMLFooter('
        <div style="text-align:center;background:#1a365d;color:#fff;padding:8px 0;font-size:9pt;margin:0 -15mm;width:calc(100% + 30mm);">
            صفحه {PAGENO} از {nbpg}
        </div>
    ');

    $css = '
    <style>
        body { font-family: vazir; direction: rtl; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #333; padding: 5px 8px; text-align: center; white-space: nowrap; }
        th { background: #2d3748; color: #fff; }
        td.course-name { text-align: right; }
        tr.electronic { background: #E8F5E9; }
        tr.written { background: #fff; }
        tr.total-row { background: #e2e8f0; font-weight: bold; }
        tr.daily-total { background: #e2e8f0; color: #000; font-weight: bold; }
        .page-header { text-align: center; margin-bottom: 15px; }
        .page-header h2 { margin: 0; font-size: 14pt; }
        .session-header { background: #1a365d; color: #fff; padding: 8px; text-align: center; font-weight: bold; margin-top: 20px; margin-bottom: 5px;  font-size: 12pt;}
        .date-header { background: #2c5282; color: #fff; padding: 10px; text-align: center; font-size: 12pt; font-weight: bold; margin-top: 25px; }
    </style>';

    $mpdf->WriteHTML($css);

    // Page Header
    $headerHtml = '<div class="page-header">
        <h2>' . htmlspecialchars($universityName) . '</h2>
        <div>دفترچه کلی آزمون‌ها</div>
    </div>';
    $mpdf->WriteHTML($headerHtml);

    foreach ($groupedByDate as $examDate => $sessions) {
        // Convert to Jalali
        $dateParts = explode('-', $examDate);
        if (count($dateParts) === 3) {
            list($jalaliYear, $jalaliMonth, $jalaliDay) = gregorian_to_jalali(intval($dateParts[0]), intval($dateParts[1]), intval($dateParts[2]));
            $jalaliDateStr                              = sprintf('%04d/%02d/%02d', $jalaliYear, $jalaliMonth, $jalaliDay);
        } else {
            $jalaliDateStr = $examDate;
        }

        // Daily totals
        $dailyTotal      = 0;
        $dailyTest       = 0;
        $dailyDesc       = 0;
        $dailyTestDesc   = 0;
        $dailyElectronic = 0;
        $dailyWritten    = 0;

        //$dateHtml = '<div class="date-header">تاریخ: ' . toPersianDigits($jalaliDateStr) . '</div>';
        //$mpdf->WriteHTML($dateHtml);

        ksort($sessions); // Sort by time

        foreach ($sessions as $examTime => $courses) {
            // Session totals
            $sessionTotal      = 0;
            $sessionTest       = 0;
            $sessionDesc       = 0;
            $sessionTestDesc   = 0;
            $sessionElectronic = 0;
            $sessionWritten    = 0;

            $sessionHtml  = '<div class="session-header">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($jalaliDateStr) . '</div>';
            $sessionHtml .= '<table>
                <thead>
                    <tr>
                        <th style="width: 7%;">#</th>
                        <th style="width: 8%;">کد درس</th>
                        <th style="width: 60%;">نام درس</th>
                        <th style="width: 15%;">نوع درس</th>
                        <th style="width: 10%;">تعداد</th>
                    </tr>
                </thead>
                <tbody>';

            // Sort: electronic first
            usort($courses, function ($a, $b) {
                $typeA = $a['exam_type'] ?? '';
                $typeB = $b['exam_type'] ?? '';
                if ($typeA === 'الکترونیکی' && $typeB !== 'الکترونیکی')
                    return -1;
                if ($typeA !== 'الکترونیکی' && $typeB === 'الکترونیکی')
                    return 1;
                return strcmp($a['course_code'], $b['course_code']);
            });

            // Reset row number for each session
            $sessionRowNum = 0;
            foreach ($courses as $course) {
                $sessionRowNum++;
                $examType     = $course['exam_type'] ?? 'کتبی';
                $courseType   = $course['course_type'] ?? '-';
                $studentCount = intval($course['student_count']);
                $isElectronic = ($examType === 'الکترونیکی');
                $rowClass     = $isElectronic ? 'electronic' : 'written';

                $sessionTotal += $studentCount;

                if ($isElectronic) {
                    $sessionElectronic += $studentCount;
                } else {
                    $sessionWritten += $studentCount;
                    // Count by course type
                    if (strpos($courseType, 'تستی') !== false && strpos($courseType, 'تشریحی') !== false) {
                        $sessionTestDesc += $studentCount;
                    } elseif (strpos($courseType, 'تستی') !== false) {
                        $sessionTest += $studentCount;
                    } elseif (strpos($courseType, 'تشریحی') !== false) {
                        $sessionDesc += $studentCount;
                    }
                }

                $sessionHtml .= '<tr class="' . $rowClass . '">
                    <td>' . toPersianDigits($sessionRowNum) . '</td>
                    <td>' . htmlspecialchars($course['course_code']) . '</td>
                    <td class="course-name">' . htmlspecialchars($course['course_name']) . '</td>
                    <td>' . htmlspecialchars($courseType) . '</td>
                    <td>' . toPersianDigits($studentCount) . '</td>
                </tr>';
            }

            // Session total row
            $sessionHtml .= '<tr class="total-row">
                <td colspan="2">جمع جلسه</td>
                <td colspan="3">
                    کل: ' . toPersianDigits($sessionTotal) . ' | 
                    تستی: ' . toPersianDigits($sessionTest) . ' | 
                    تشریحی: ' . toPersianDigits($sessionDesc) . ' | 
                    تستی‌تشریحی: ' . toPersianDigits($sessionTestDesc) . ' | 
                    الکترونیکی: ' . toPersianDigits($sessionElectronic) . ' | 
                    کتبی: ' . toPersianDigits($sessionWritten) . '
                </td>
            </tr>';

            $sessionHtml .= '</tbody></table>';
            $mpdf->WriteHTML($sessionHtml);

            // Accumulate daily totals
            $dailyTotal      += $sessionTotal;
            $dailyTest       += $sessionTest;
            $dailyDesc       += $sessionDesc;
            $dailyTestDesc   += $sessionTestDesc;
            $dailyElectronic += $sessionElectronic;
            $dailyWritten    += $sessionWritten;
        }

        // Daily total row
        $dailyHtml = '<table><tbody><tr class="daily-total">
            <td colspan="3">
                کل: ' . toPersianDigits($dailyTotal) . ' | 
                تستی: ' . toPersianDigits($dailyTest) . ' | 
                تشریحی: ' . toPersianDigits($dailyDesc) . ' | 
                تستی‌تشریحی: ' . toPersianDigits($dailyTestDesc) . ' | 
                الکترونیکی: ' . toPersianDigits($dailyElectronic) . ' | 
                کتبی: ' . toPersianDigits($dailyWritten) . '
            </td>
        </tr></tbody></table>';
        $mpdf->WriteHTML($dailyHtml);
    }
}

/**
 * Generate Seat Labels Report
 * 8 labels per A4 page (2 columns x 4 rows), numbered starting from appropriate base
 * Total is calculated from max students in any session
 */
function generateSeatLabelsReport($pdo, $mpdf, $config)
{
    $universityName = $config['University'] ?? 'دانشگاه';

    // Get max students in any session from database (join courses to get date/time)
    $stmt = $pdo->query(
        "SELECT c.exam_date, c.exam_time, COUNT(es.student_id) as student_count
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        GROUP BY c.exam_date, c.exam_time
        ORDER BY student_count DESC
        LIMIT 1"
    );

    $maxSession = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $total      = $maxSession ? intval($maxSession['student_count']) : 100;

    // Determine starting number based on total
    // Start from 11 for <90, 101 for <900, 1001 for <9000
    if ($total < 90) {
        $startNum = 11;
    } elseif ($total < 900) {
        $startNum = 101;
    } elseif ($total < 9000) {
        $startNum = 1001;
    } else {
        $startNum = 10001;
    }

    // CSS for 8 labels per page (2x4 grid)
    $css = '
    <style>
        @page { margin: 5mm; }
        body { font-family: vazir; direction: rtl; margin: 0; padding: 0; }
        table.labels-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.labels-table td {
            width: 50%;
            height: 68mm;
            border: 2px solid #000;
            text-align: center;
            vertical-align: middle;
            padding: 8px;
        }
        .label-header {
            font-size: 12pt;
            font-weight: bold;
            border-bottom: 1px solid #999;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .seat-number {
            font-size: 90pt;
            font-weight: bold;
            color: #000;
            line-height: 1.2;
        }
        .label-footer {
            font-size: 10pt;
            color: #333;
            margin-top: 10px;
        }
    </style>';

    $mpdf->WriteHTML($css);

    // Generate labels - 8 per page (4 rows x 2 columns)
    $labelsPerPage = 8;
    $totalLabels   = $total;
    $labelIndex    = 0;

    while ($labelIndex < $totalLabels) {
        // Start new table for each page
        $html = '<table class="labels-table">';

        for ($row = 0; $row < 4; $row++) {
            $html .= '<tr>';
            for ($col = 0; $col < 2; $col++) {
                if ($labelIndex < $totalLabels) {
                    $seatNum  = $startNum + $labelIndex;
                    $html    .= '<td>
                        <div class="label-header">' . htmlspecialchars($universityName) . '</div>
                        <div class="seat-number">' . toPersianDigits($seatNum) . '</div>
                    </td>';
                    $labelIndex++;
                } else {
                    $html .= '<td></td>';
                }
            }
            $html .= '</tr>';
        }

        $html .= '</table>';
        $mpdf->WriteHTML($html);

        // Add new page if more labels remain
        if ($labelIndex < $totalLabels) {
            $mpdf->AddPage();
        }
    }
}
