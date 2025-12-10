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

if ($reportType !== 'proctor_notice' && (empty($examDate) || empty($examTime))) {
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
} elseif ($reportType === 'proctor_notice') {
    generateProctorNotices($pdo, $mpdf, $config);
} elseif ($reportType === 'attendance_sheet') {
    generateAttendanceSheet($pdo, $mpdf, $examDate, $examTime, $config);
} else {
    die('Unknown report type');
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

    // Pagination
    $perPage    = 15;
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
        .courses-table th { background-color: #efefef; border: 1px solid #ccc; padding: 5px; font-weight: bold; }
        .courses-table td { border: 1px solid #ccc; padding: 8px 5px; text-align: center; }
        .courses-table td.name { text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .footer-signs { position: fixed; bottom: 15mm; left: 0; right: 0; width: 100%; }
        .sign-row { width: 100%; margin-bottom: 20px; }
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

        $startRow = ($index * $perPage) + 1;
        foreach ($chunk as $i => $course) {
            $rowNum    = $startRow + $i;
            $count     = $course['student_count'] ?? 0;
            $pageHtml .= '<tr>
                <td>' . toPersianDigits($rowNum) . '</td>
                <td>' . ($course['course_type'] ?? '') . '</td>
                <td>' . toPersianDigits($course['course_code']) . '</td>
                <td class="name">' . ($course['course_name']) . '</td>
                <td>' . toPersianDigits($count) . '</td>
                <td> ___ / ___ </td>
            </tr>';
        }
        $pageHtml .= '</tbody></table>';

        // Footer Signatures
        $pageHtml .= '<div class="footer-signs">
            <div style="border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 15px; text-align: center; font-size: 9pt;">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>
            
            <table style="width: 100%; border: none;">
                <tr>
                    <td style="border: none; text-align: right; padding: 10px;">نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ' . $bossName . '</td>
                    <td style="border: none; text-align: left; padding: 10px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 10px;">نام و نام خانوادگی رئیس اداره آموزش: ' . $headName . '</td>
                    <td style="border: none; text-align: left; padding: 10px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 10px;">نام و نام خانوادگی مسئول جلسه: ' . $chairName . '</td>
                    <td style="border: none; text-align: left; padding: 10px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 10px;">نام و نام خانوادگی ناظران/مراقبان جلسه:</td>
                    <td style="border: none; text-align: left; padding: 10px;">امضاء</td>
                </tr>
                <tr>
                    <td style="border: none; text-align: right; padding: 10px;">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</td>
                    <td style="border: none; text-align: left; padding: 10px;">امضاء</td>
                </tr>
            </table>
        </div>';

        // Page Number
        $mpdf->SetHTMLFooter('<div style="background-color: #444; color: #fff; text-align: center; padding: 5px; font-size: 9pt;">صفحه ' . toPersianDigits($index + 1) . ' از ' . toPersianDigits($totalPages) . '</div>');

        $mpdf->WriteHTML($pageHtml);
    }
}

function generateSeatNumbersReport($pdo, $mpdf, $examDate, $examTime, $config)
{
    $mpdf->AddPage('L'); // Landscape

    // Fetch Students with Seat Info
    // We can reuse the query logic from getNextExamReport.php but we need to do it here.
    // First get courses to get the list of course codes
    $stmt = $pdo->prepare("SELECT course_code FROM courses WHERE exam_date = ? AND exam_time = ?");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($courses)) {
        die('No courses found.');
    }

    $placeholders = str_repeat('?,', count($courses) - 1) . '?';

    // Determine Sort Order
    $orderBy = 's.last_name, s.first_name';
    if (isset($config['GroupByCourse']) && strtoupper($config['GroupByCourse']) === 'YES') {
        $orderBy = 'c.course_name, s.last_name, s.first_name';
    }

    $stmt = $pdo->prepare("
        SELECT 
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

    // Pagination
    $perPage    = 44; // 22 per column * 2 columns
    $chunks     = array_chunk($students, $perPage);
    $totalPages = count($chunks);

    $htmlStyle = '
    <style>
        body { font-family: vazir; font-size: 9pt; }
        .page-container { width: 100%; }
        .col { width: 48%; float: right; margin-left: 2%; }
        .col-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
        .col-table th { background-color: #efefef; border-bottom: 1px solid #ccc; padding: 8px 3px; font-weight: bold; text-align: center; font-size: 9pt; }
        .col-table td { 
            border-bottom: 1px solid #eee; 
            padding: 6px 3px; 
            text-align: right; 
            font-size: 9pt; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            vertical-align: middle;
        }
        .col-table td.seat-col { text-align: center; width: 10%; }
        .name-col { width: 25%; }
        .course-col { width: 65%; }
        .page-footer-custom { position: fixed; bottom: 5px; left: 0; right: 0; text-align: center; font-size: 8pt; color: #666; }
    </style>';

    foreach ($chunks as $index => $chunk) {
        if ($index > 0)
            $mpdf->AddPage('L');

        $half = ceil(count($chunk) / 2);
        $col1 = array_slice($chunk, 0, $half);
        $col2 = array_slice($chunk, $half);

        $html = $htmlStyle . '<div class="page-container">';

        // Column 1
        $html .= '<div class="col"><table class="col-table"><thead><tr><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead><tbody>';
        foreach ($col1 as $s) {
            $html .= '<tr><td>' . $s['last_name'] . ' ' . $s['first_name'] . '</td><td>' . $s['course_name'] . '</td><td class="seat-col">' . toPersianDigits($s['seat_number']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        // Column 2
        $html .= '<div class="col" style="margin-left: 0;"><table class="col-table"><thead><tr><th class="name-col">نام و نام خانوادگی</th><th class="course-col">نام درس</th><th class="seat-col">صندلی</th></tr></thead><tbody>';
        foreach ($col2 as $s) {
            $html .= '<tr><td>' . $s['last_name'] . ' ' . $s['first_name'] . '</td><td>' . $s['course_name'] . '</td><td class="seat-col">' . toPersianDigits($s['seat_number']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '</div>';

        // Footer
        $startNum  = ($index * $perPage) + 1;
        $endNum    = min(($index + 1) * $perPage, count($students));
        $html     .= '<div class="page-footer-custom">از شماره ' . toPersianDigits($startNum) . ' تا ' . toPersianDigits($endNum) . '</div>';

        $mpdf->SetHTMLFooter('');
        $mpdf->WriteHTML($html);
    }

    // Kroki Page (Seat Map)
    generateKrokiPage($mpdf, $students, $examDate, $examTime);
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

    $mpdf->AddPage('P');
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
    </style>
    ';

    // Secretary List Header
    $html .= '<div class="header"><div class="title">لیست منشی جلسه</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>';

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

    // Proctor Section (Moved to end)
    if (!empty($proctors)) {
        $paperSaving = isset($config['PaperSaving']) && strtoupper($config['PaperSaving']) === 'YES';

        if (!$paperSaving) {
            $mpdf->AddPage();
        } else {
            $mpdf->WriteHTML('<div style="margin-top: 20px; margin-bottom: 20px; border-top: 2px dashed #000;"></div>');
        }

        $html = '<div class="header"><div class="title">لیست عوامل اجرائی جلسه</div><div class="meta">' . toPersianDigits($examTime) . ' | ' . toPersianDigits($examDate) . '</div></div>';

        // Columns logic (simple 2 columns for now)
        $chunks       = array_chunk($proctors, ceil(count($proctors) / 2));
        $html        .= '<table style="width: 100%; vertical-align: top; table-layout: fixed;"><tr>';
        $globalIndex  = 1;
        foreach ($chunks as $chunk) {
            $html .= '<td style="vertical-align: top; padding: 5px; width: 50%;"><table class="proctor-table"><thead><tr><th style="width: 50px;">ردیف</th><th>نام عامل اجرائی</th></tr></thead><tbody>';
            foreach ($chunk as $p) {
                $html .= '<tr><td style="text-align: center;">' . toPersianDigits($globalIndex++) . '</td><td>' . $p['proctor_name'] . '</td></tr>';
            }
            $html .= '</tbody></table></td>';
        }
        $html .= '</tr></table>';
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
}

function generateLocationLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch all students for this session with their locations and courses (written exams only)
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
        WHERE c.exam_date = ? AND c.exam_time = ? AND es.exam_type = 'کتبی'
        ORDER BY es.building, es.class_name, es.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allStudents)) {
        die('No written exam students found for this session.');
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
    ';

    $pageIndex = 0;
    foreach ($locationGroups as $key => $loc) {
        if ($pageIndex > 0) {
            $mpdf->AddPage('L');
        }
        $pageIndex++;

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

        $html  = '<style>' . $css . '</style>';
        $html .= '<div class="label-page">';

        // Location title (building | class)
        $html .= '<div class="location-title">' . $loc['building'] . ' — ' . $loc['class_name'] . '</div>';

        // Meta info (date, time, total)
        $html .= '<div class="meta-info">' . toPersianDigits($examDate) . ' | ساعت ' . toPersianDigits($examTime) . ' | مجموع: ' . toPersianDigits($totalStudents) . ' نفر</div>';

        // Courses table
        $html .= '<table class="courses-table">';
        $html .= '<thead><tr>
            <th style="width: 5%;">#</th>
            <th style="width: 10%;">از شماره</th>
            <th style="width: 10%;">تا شماره</th>
            <th style="width: 7%;">تعداد</th>
            <th style="width: 8%;">کد درس</th>
            <th style="width: 60%;">نام درس</th>
        </tr></thead><tbody>';

        if (empty($sortedCourses)) {
            $html .= '<tr><td colspan="6">بدون اطلاعات</td></tr>';
        } else {
            $rowIndex = 0;
            foreach ($sortedCourses as $courseData) {
                $rowIndex++;
                $html .= '<tr>
                    <td>' . toPersianDigits($rowIndex) . '</td>
                    <td>' . toPersianDigits($courseData['min_seat']) . '</td>
                    <td>' . toPersianDigits($courseData['max_seat']) . '</td>
                    <td>' . toPersianDigits($courseData['count']) . '</td>
                    <td>' . toPersianDigits($courseData['course_code']) . '</td>
                    <td class="course-name">' . $courseData['course_name'] . '</td>
                </tr>';
            }
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        $mpdf->WriteHTML($html);
    }

    $filename   = 'LocationLabels_' . str_replace(['/', '\\'], '-', $examDate) . '.pdf';
    $outputMode = (isset($config['rptDownload']) && strtoupper($config['rptDownload']) === 'YES') ? 'D' : 'I';
    $mpdf->Output($filename, $outputMode);
    exit;
}

function generateDescriptiveLabels($pdo, $mpdf, $examDate, $examTime, $config)
{
    // Fetch Courses (Descriptive and Test-Descriptive)
    $stmt = $pdo->prepare("
        SELECT * FROM courses 
        WHERE exam_date = ? AND exam_time = ? AND course_type LIKE '%تشریحی%'
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
        body { font-family: vazir; font-size: 13pt; line-height: 1.8; }
        .page { border: 1px solid #fff; padding: 10px; height: 100%; box-sizing: border-box; }
        .strong { font-weight: bold; }
        .blank { display: inline-block; width: 50px; border-bottom: 1px solid #000; }
        .footer-table { width: 100%; border-collapse: collapse; margin-top: 30px; }
        .footer-table td { border: 1px solid #000; padding: 10px; vertical-align: top; height: 80px; width: 50%; }
    </style>';

    foreach ($courses as $i => $c) {
        if ($i > 0)
            $mpdf->AddPage('L');

        $html = $htmlStyle . '
        <div class="page">
            <div style="font-weight: bold; margin-bottom: 20px;">استاد ارجمند؛</div>
            <div style="text-align: justify;">
                بدین وسیله تعداد <span class="blank"></span> برگه تشریحی مربوط به درس <span class="strong">' . $c['course_name'] . '</span> 
                با کد <span class="strong">' . toPersianDigits($c['course_code']) . '</span>
                که آزمون آن در تاریخ <span class="strong">' . toPersianDigits($examDate) . '</span> 
                ساعت <span class="strong">' . toPersianDigits($examTime) . '</span>
                به صورت <span class="strong">' . ($c['course_type'] ?? 'کتبی') . '</span> برگزار گردیده، تحویل حضور استاد محترم می‌گردد.
            </div>
            <div style="margin-top: 20px; font-size: 11pt;">
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

function generateProctorNotices($pdo, $mpdf, $config)
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

    // Fetch Assignments
    $stmt = $pdo->query("SELECT proctor_id, proctor_name, exam_date, exam_time FROM ExamAssignments WHERE TRIM(IFNULL(proctor_name, '')) != ''");
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

    // Pre-calculate total pages for all locations
    foreach ($locations as $location) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM exam_seats es
            JOIN courses c ON es.course_code = c.course_code
            WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ?
        ");
        $stmt->execute([$examDate, $examTime, $location['building'], $location['class_name']]);
        $count             = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $pagesForLocation  = max(1, ceil($count / 18)); // 18 students per page
        $totalGlobalPages += $pagesForLocation;
        $locationData[]    = [
            'building' => $location['building'],
            'class_name' => $location['class_name'],
            'pages' => $pagesForLocation
        ];
    }

    $globalPageNumber = 0;

    // Process each location (building + class)
    foreach ($locations as $location) {
        $building  = $location['building'];
        $className = $location['class_name'];

        // Fetch all students for this specific location
        $stmt = $pdo->prepare("
            SELECT 
                es.student_id, 
                s.first_name, 
                s.last_name,
                s.national_id,
                es.seat_number,
                es.class_name,
                es.exam_type,
                c.course_code,
                c.course_name
            FROM exam_seats es
            JOIN students s ON es.student_id = s.student_id
            JOIN courses c ON es.course_code = c.course_code
            WHERE c.exam_date = ? AND c.exam_time = ? AND es.building = ? AND es.class_name = ?
            ORDER BY es.seat_number ASC
        ");
        $stmt->execute([$examDate, $examTime, $building, $className]);
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
            $html .= '<div class="exam-info">فهرست حضور و غیاب آزمون‌های <span class="dynamic-value">' . toPersianDigits($examTime) . '</span> | <span class="dynamic-value">' . $persianDateStr . '</span> مستقر در <span class="dynamic-value">' . $building . '</span> | <span class="dynamic-value">' . $className . '</span></div>';
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
                $html .= renderAttendanceStudentRow($student, $picBaseDir, $saadCode);
            }
            $html .= '</table>';
            $html .= '</td>';

            // Left column (second half of students)
            $html .= '<td style="width: 50%; vertical-align: top;">';
            $html .= '<table class="column-table">';
            foreach ($column2 as $student) {
                $html .= renderAttendanceStudentRow($student, $picBaseDir, $saadCode);
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
function renderAttendanceStudentRow($student, $picBaseDir, $saadCode)
{
    if ($student === null) {
        // Empty row - no borders, just empty space
        return '<tr class="empty-row"><td></td><td></td><td></td></tr>';
    }

    $studentId  = $student['student_id'];
    $nationalId = $student['national_id'] ?? '';
    $firstName  = $student['first_name'];
    $lastName   = $student['last_name'];
    $seatNum    = $student['seat_number'];
    $courseCode = $student['course_code'];
    $courseName = $student['course_name'];

    $html = '<tr class="student-row">';

    // Right cell: Seat number (big) + student ID + national ID below
    $html .= '<td class="seat-col">';
    $html .= '<div class="seat-number">' . toPersianDigits($seatNum) . '</div>';
    $html .= '<div style="font-size: 6pt; margin-top: 1mm;">' . toPersianDigits($studentId) . '</div>';
    $html .= '<div style="font-size: 6pt;">' . toPersianDigits($nationalId) . '</div>';
    $html .= '</td>';

    // Middle cell: Student name (centered, larger font) + Course info
    $html .= '<td class="info-col">';
    $html .= '<div style="text-align: center; font-size: 9pt; font-weight: bold; margin-bottom: 2mm;">' . $firstName . ' ' . $lastName . '</div>';
    $html .= '<div class="exam-info-line">' . toPersianDigits($courseCode) . '</div>';
    $html .= '<div class="exam-info-line">' . $courseName . '</div>';
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

