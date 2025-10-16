# نسار – سامانه نمایش شماره صندلی آزمون‌های پیام نور

<div dir="rtl">

**نسار** سامانه‌ای تحت وب و PWA است که با رویکرد تجربه کاربری مدرن و ظاهر شیشه‌ای (Glassmorphism) به دانشجویان پیام نور کمک می‌کند تا برنامه‌ی امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را یک‌جا مشاهده کنند.

## مهم‌ترین قابلیت‌ها

- 🪟 **رابط کاربری شیشه‌ای**: تم iOS–style با بلور، گرادیان و انیمیشن‌های نرم در کارت‌ها و فرم‌ها
- � **هشدارهای تعاملی**: SweetAlert2 با شمارنده‌ی معکوس مینیمال، واکنش‌گرا و هماهنگ با تم
- 📱 **PWA کامل**: نصب روی موبایل/دسکتاپ، آیکن‌های جدید `pwa-icons/` و پشتیبانی از حالت آفلاین
- ⚡ **کش هوشمند**: Service Worker با استراتژی‌های به‌روزشده (Network-first برای منابع اصلی و Cache-first برای بقیه)
- � **امنیت و حریم خصوصی**: رمزگذاری شناسه‌ها، نشست ۳۰ روزه با تشخیص هوشمند نمایش/مخفی‌سازی لوگو
- 🌐 **RTL و فونت فارسی**: استفاده از Vazir با ارقام هم‌عرض (tabular) برای خوانایی بهتر تایمر و داده‌ها

## معماری و راه‌اندازی سریع

برای استقرار روی Ubuntu 22.04 به Apache، PHP 8، MySQL و git نیاز دارید. مراحل زیر خلاصه شده‌اند؛ در صورت داشتن سرور آماده می‌توانید بخش‌های غیرضروری را حذف کنید.

### ۱. پیش‌نیازها

```bash
sudo apt update -y && sudo apt upgrade -y
sudo apt install apache2 mysql-server php libapache2-mod-php \
    php-mysql php-mbstring php-xml php-curl php-zip php-cli php-bcmath php-json \
    unzip git -y
sudo systemctl enable --now apache2 mysql
```

### ۲. دریافت و استقرار کد

```bash
cd /var/www/html
git clone https://github.com/MehdiHassaniir/PnuSeat.git .
sudo chown -R www-data:www-data /var/www/html
```

### ۳. پیکربندی پایگاه داده

```sql

CREATE DATABASE IF NOT EXISTS PnuExamsSeatNumber
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

-- انتخاب دیتابیس
USE PnuExamsSeatNumber;

-- جدول دانشجویان
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

-- جدول تنظیمات
CREATE TABLE Config (
    ID int NOT NULL,
    ConfigName varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
    ConfigValue varchar(100) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO Config (ID, ConfigName, ConfigValue) VALUES
(1, 'Order', ''),
(2, 'University', ''),
(3, 'IsInit', 'NO');

ALTER TABLE Config
  ADD PRIMARY KEY (ID);

ALTER TABLE Config
  MODIFY ID int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- جداول students، courses، exam_seats و Config مطابق فایل docker/mysql-init/schema.sql
-- (نمونه ساختار در پوشه docker/mysql-init آمده است)
```

### ۴. تنظیم .env

```bash
cp .env.example .env
nano .env   # اطلاعات اتصال پایگاه داده را تکمیل کنید
```

### ۵. بارگذاری مجدد Apache

```bash
sudo systemctl reload apache2
```

پس از انجام مراحل، سامانه روی `http://server-ip/` در دسترس است. برای صدور گواهی SSL از Certbot استفاده کنید.

## نکات فنی مهم

```
/var/www/html
|-- .dockerignore               # فایل نادیده‌گرفتن برای Docker
|-- .env.example                # نمونه متغیرهای محیطی برای پیکربندی
|-- .git                       # اطلاعات داخلی گیت (حذف/تغییر نشود)
|-- .gitignore                 # فایل‌های نادیده‌گرفته‌شده توسط گیت
|-- API                        # endpointهای بک‌اند (PHP)
|   |-- db_init.php            # اسکریپت نمونه ایجاد جداول/دیتابیس
|   |-- getConfig.php          # خواندن پیکربندی اپ از DB
|   |-- getStudentExams.php    # API اصلی: بازگشت اطلاعات امتحان/صندلی
|   |-- index.php              # نقطه ورود API (اختیاری)
|   |-- jdf.php                # توابع تقویم جلالی (Jalali)
|   `-- serverTime.php         # بازگرداندن تاریخ/زمان سرور برای همگام‌سازی
|-- CHANGELOG.md               # یادداشت‌های انتشار (نسخه‌ها و تغییرات)
|-- Dockerfile                 # تعریف ایمیج داکر (اختیاری)
|-- README.md                  # این فایل
|-- assets                     # فایل‌های استاتیک فرانت‌اند
|   |-- app                    # کد اپلیکیشن کاربری
|   |   |-- app.js             # منطق UI، SweetAlert، فوتر ثانیه‌ای
|   |   `-- style.css          # استایل‌ها و تنظیمات فونت (tabular-nums)
|   |-- bootstrap              # فایل‌های بوت‌استرپ محلی
|   |-- crypto-js.min.js       # کتابخانه رمزنگاری (درکلاینت)
|   |-- fonts                  # فونت‌ها (Vazir و زیرپوشه‌ها)
|   `-- sweetalert2            # اسکریپت‌ها و CSS مربوط به SweetAlert2
|-- docker                     # تنظیمات داکر محلی
|   `-- mysql-init             # اسکریپت‌های نمونه برای init دیتابیس
|-- docker-compose.yml         # کانفیگ داکر-کامپوز (اختیاری)
|-- index.html                 # صفحه اصلی PWA
|-- manifest.json              # تنظیمات نصب PWA و آیکن‌ها
|-- pwa-icons                  # آیکن‌های PWA (اندازه‌های مختلف)
|   |-- icon-192.png
|   `-- icon-512.png
`-- service-worker.js          # سرویس‌ورکر: مدیریت کش و بروزرسانی (CACHE_NAME = exam-seat-v2025-10-16-09)

17 directories, 29 files
```
|   |-- index.php
|   |-- jdf.php
|   `-- serverTime.php
|-- CHANGELOG.md
|-- Dockerfile
|-- README.md
|-- assets
|   |-- app
|   |-- bootstrap
|   |-- crypto-js.min.js
|   |-- fonts
|   `-- sweetalert2
|-- docker
|   `-- mysql-init
|-- docker-compose.yml
|-- index.html
|-- manifest.json
|-- pwa-icons
|   |-- icon-192.png
|   `-- icon-512.png
`-- service-worker.js

17 directories, 29 files
```

</div>

<div dir="rtl">

## مجوز

پروژه تحت مجوز MIT ارائه شده است. برای گزارش باگ یا پیشنهاد، Pull Request یا Issue در GitHub باز کنید.

---

**نسار** – مرکز سنجش و آزمون دانشگاه پیام نور

</div>