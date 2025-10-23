SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;CREATE TABLE IF NOT EXISTS students (
    student_id CHAR(9) PRIMARY KEY,
    national_id CHAR(10) NOT NULL,
    source_center CHAR(4) NOT NULL,
    destination_center CHAR(4) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    degree VARCHAR(15) NOT NULL,
    INDEX idx_name (last_name, first_name),
    INDEX idx_source_dest (source_center, destination_center)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS courses (
    course_code CHAR(7) PRIMARY KEY,
    course_name VARCHAR(100) NOT NULL,
    exam_date CHAR(10) NOT NULL,
    exam_time CHAR(5) NOT NULL,
    exam_type VARCHAR(15) NOT NULL,
    course_type VARCHAR(15) NOT NULL,
    INDEX idx_exam_date (exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS exam_seats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id CHAR(9) NOT NULL,
    course_code CHAR(7) NOT NULL,
    seat_number INT NOT NULL,
    building VARCHAR(100) NOT NULL,
    class_name VARCHAR(50) NOT NULL,
    seat_row INT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (course_code) REFERENCES courses(course_code) ON DELETE CASCADE,
    UNIQUE KEY uniq_student_course (student_id, course_code),
    INDEX idx_building_class (building, class_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS Config (
  ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ConfigName varchar(20) COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
  ConfigValue varchar(100) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO students (student_id, national_id, source_center, destination_center, first_name, last_name, degree)
VALUES
    ('970100001', '1234567890', '1101', '1201', 'مهدی', 'حسنی', 'کارشناسی'),
    ('970100002', '2234567890', '1101', '1201', 'سعید', 'مرادی', 'کارشناسی ارشد'),
    ('970100003', '3234567890', '1301', '1201', 'سارا', 'احمدی', 'کارشناسی')
ON DUPLICATE KEY UPDATE
    national_id = VALUES(national_id),
    source_center = VALUES(source_center),
    destination_center = VALUES(destination_center),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    degree = VALUES(degree);

INSERT INTO courses (course_code, course_name, exam_date, exam_time, exam_type, course_type)
VALUES
    ('1100001', 'پایگاه داده‌ها', '1404/08/24', '09:30', 'الکترونیکی', 'تستی'),
    ('1100002', 'ساختمان داده', '1404/08/23', '19:30', 'کتبی', 'تشریحی'),
    ('1100003', 'برنامه‌سازی وب', '1404/08/20', '13:30', 'کتبی', 'تستی'),
    ('1100004', 'ریاضیات عمومی ۱', '1404/08/20', '21:00', 'کتبی', 'تستی و تشریحی'),
    ('1100005', 'فیزیک ۱', '1404/09/21', '20:00', 'کتبی', 'تستی'),
    ('1100006', 'زبان تخصصی', '1404/08/24', '12:30', 'الکترونیکی', 'تستی'),
    ('1100007', 'مدارهای منطقی', '1404/08/23', '08:30', 'کتبی', 'تشریحی'),
    ('1100008', 'مبانی کامپیوتر و برنامه‌سازی', '1404/08/12', '09:00', 'الکترونیکی', 'تستی'),
    ('1100009', 'ریاضی گسسته', '1404/08/24', '11:00', 'کتبی', 'تشریحی'),
    ('1100010', 'سیستم عامل', '1404/08/17', '14:30', 'الکترونیکی', 'تستی'),
    ('1100011', 'شبکه‌های کامپیوتری', '1404/08/20', '10:30', 'الکترونیکی', 'تستی'),
    ('1100012', 'مهندسی نرم‌افزار', '1404/08/22', '08:30', 'الکترونیکی', 'تستی'),
    ('1100013', 'طراحی الگوریتم‌ها', '1404/08/25', '13:00', 'کتبی', 'تستی و تشریحی')
ON DUPLICATE KEY UPDATE
    course_name = VALUES(course_name),
    exam_date = VALUES(exam_date),
    exam_time = VALUES(exam_time),
    exam_type = VALUES(exam_type),
    course_type = VALUES(course_type);

INSERT INTO exam_seats (student_id, course_code, seat_number, building, class_name, seat_row)
VALUES
    ('970100001', '1100001', 15, 'ساختمان A', 'کلاس 203', 2),
    ('970100001', '1100002', 8, 'ساختمان A', 'کلاس 205', 1),
    ('970100001', '1100003', 3, 'ساختمان B', 'کلاس 101', 1),
    ('970100001', '1100004', 5, 'ساختمان A', 'کلاس 201', 1),
    ('970100001', '1100005', 9, 'ساختمان A', 'کلاس 204', 2),
    ('970100001', '1100006', 14, 'ساختمان A', 'کلاس 206', 3),
    ('970100001', '1100007', 3, 'ساختمان A', 'کلاس 202', 1),
    ('970100002', '1100001', 12, 'ساختمان B', 'کلاس 101', 1),
    ('970100001', '1100009', 10, 'ساختمان B', 'کلاس 102', 2),
    ('970100001', '1100011', 11, 'ساختمان C', 'کلاس 302', 2)
ON DUPLICATE KEY UPDATE
    seat_number = VALUES(seat_number),
    building = VALUES(building),
    class_name = VALUES(class_name),
    seat_row = VALUES(seat_row);

INSERT INTO Config (ConfigName, ConfigValue) VALUES
('University', ''),
('IsInit', 'NO')
ON DUPLICATE KEY UPDATE
    ConfigValue = VALUES(ConfigValue);