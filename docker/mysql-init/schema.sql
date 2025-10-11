CREATE DATABASE IF NOT EXISTS PnuExamsSeatNumber
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE PnuExamsSeatNumber;

CREATE TABLE IF NOT EXISTS students (
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
