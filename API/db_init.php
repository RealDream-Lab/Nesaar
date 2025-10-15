<?php
// Database initialization and connection
require_once 'jdf.php';

// Load .env file when present so Docker/local configs stay in sync
(function () {
    $root = realpath(__DIR__ . '/../');
    $envFile = $root ? $root . '/.env' : null;
    if (!$envFile || !is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$key, $value] = $parts;
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
})();

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'PnuExamsSeatNumber';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '01012556360043214'; // Fallback for legacy dev only
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Log the actual error for debugging (in production, log to file)
    error_log('Database connection failed: ' . $e->getMessage());
    
    // Return generic error message to user
    echo json_encode(['error' => 'خطا در اتصال به پایگاه داده']);
    exit;
}

// Check if tables exist, if not, initialize database
try {
    $result = $pdo->query("SHOW TABLES LIKE 'students'");
    if ($result->rowCount() == 0) {
        // Database not initialized, run initialization SQL
        $initSQL = "
CREATE TABLE students (
    student_id CHAR(9) PRIMARY KEY,              -- شماره دانشجویی (۹ رقم)
    national_id CHAR(10) NOT NULL,               -- شماره ملی / شناسنامه (۱۰ رقم)
    source_center CHAR(4) NOT NULL,              -- کد مرکز مبدأ
    destination_center CHAR(4) NOT NULL,         -- کد مرکز مقصد
    first_name VARCHAR(50) NOT NULL,             -- نام
    last_name VARCHAR(50) NOT NULL,              -- نام خانوادگی
    degree VARCHAR(15) NOT NULL,                 -- مدرک (کارشناسی، ارشد و ...)
    INDEX idx_name (last_name, first_name),
    INDEX idx_source_dest (source_center, destination_center)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول دروس
CREATE TABLE courses (
    course_code CHAR(7) PRIMARY KEY,             -- کد درس ۷ رقمی
    course_name VARCHAR(100) NOT NULL,           -- نام درس
    exam_date CHAR(10) NOT NULL,                 -- تاریخ آزمون (شمسی، مثل 1404/10/25)
    exam_time CHAR(5) NOT NULL,                  -- ساعت آزمون (HH:MM)
    exam_type VARCHAR(15) NOT NULL,              -- نوع آزمون (حضوری / مجازی)
    course_type VARCHAR(15) NOT NULL,            -- نوع درس (نظری / عملی)
    INDEX idx_exam_date (exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- جدول ارتباطی صندلی‌ها (اصلی)
CREATE TABLE exam_seats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id CHAR(9) NOT NULL,
    course_code CHAR(7) NOT NULL,
    seat_number INT NOT NULL,                    -- شماره صندلی
    building VARCHAR(100) NOT NULL,              -- ساختمان
    class_name VARCHAR(50) NOT NULL,             -- کلاس
    seat_row INT NOT NULL,                       -- ردیف در کلاس
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (course_code) REFERENCES courses(course_code) ON DELETE CASCADE,
    UNIQUE KEY uniq_student_course (student_id, course_code),
    INDEX idx_building_class (building, class_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- چند رکورد نمونه تستی برای بررسی عملکرد
INSERT INTO students (student_id, national_id, source_center, destination_center, first_name, last_name, degree)
VALUES
('970100001', '1234567890', '1101', '1201', 'مهدی', 'حسنی', 'کارشناسی'),
('970100002', '2234567890', '1101', '1201', 'سعید', 'مرادی', 'کارشناسی ارشد'),
('970100003', '3234567890', '1301', '1201', 'سارا', 'احمدی', 'کارشناسی');

INSERT INTO courses (course_code, course_name, exam_date, exam_time, exam_type, course_type)
VALUES
('1100001', 'پایگاه داده‌ها', '1404/10/20', '09:30', 'حضوری', 'نظری'),
('1100002', 'ساختمان داده', '1404/10/22', '14:00', 'حضوری', 'نظری'),
('1100003', 'برنامه‌سازی وب', '1404/10/25', '11:00', 'مجازی', 'عملی');

INSERT INTO exam_seats (student_id, course_code, seat_number, building, class_name, seat_row)
VALUES
('970100001', '1100001', 15, 'ساختمان A', 'کلاس 203', 2),
('970100001', '1100002', 8, 'ساختمان A', 'کلاس 205', 1),
('970100002', '1100001', 12, 'ساختمان B', 'کلاس 101', 1),
('970100003', '1100003', 3, 'ساختمان مجازی', 'کلاس آنلاین', 0);
        ";
        $pdo->exec($initSQL);
    }
} catch (\PDOException $e) {
    error_log('Database initialization failed: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در راه‌اندازی پایگاه داده']);
    exit;
}
?>