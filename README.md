# نسار - سامانه مشاهده شماره صندلی آزمون‌های پیام نور

سامانه‌ای برای مشاهده شماره صندلی و جزئیات امتحانات دانشجویان دانشگاه پیام نور

## ویژگی‌های سامانه

- 🔐 **امنیت بالا**: رمزگذاری اطلاعات حساس دانشجویان
- 📱 **PWA**: قابلیت نصب روی موبایل و دسکتاپ
- 🌐 **RTL**: پشتیبانی کامل از زبان فارسی و طراحی راست‌چین
- 🔄 **Session Management**: نگهداری جلسه کاربر تا ۳۰ روز
- 📋 **کارت‌های زیبا**: نمایش اطلاعات امتحانات در قالب کارت‌های جذاب
- ⚡ **عملکرد سریع**: کش محلی و Service Worker برای بارگذاری سریع

## نیازمندی‌های سرور

### نرم‌افزارهای مورد نیاز

```bash
# Apache Web Server
sudo apt update
sudo apt install apache2

# PHP 7.4 یا بالاتر
sudo apt install php php-cli php-mysql php-mbstring php-xml php-zip

# MySQL Server
sudo apt install mysql-server

# phpMyAdmin (اختیاری)
sudo apt install phpmyadmin
```

### تنظیمات Apache

```bash
# فعال‌سازی mod_rewrite
sudo a2enmod rewrite

# راه‌اندازی مجدد Apache
sudo systemctl restart apache2

# بررسی وضعیت
sudo systemctl status apache2
```

### تنظیمات MySQL

```bash
# ایمن‌سازی MySQL
sudo mysql_secure_installation

# ورود به MySQL
sudo mysql -u root -p

# ایجاد پایگاه داده
CREATE DATABASE PnuExamsSeatNumber CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# ایجاد کاربر
CREATE USER 'pnu_user'@'localhost' IDENTIFIED BY 'your_secure_password';

# اعطای دسترسی
GRANT ALL PRIVILEGES ON PnuExamsSeatNumber.* TO 'pnu_user'@'localhost';
FLUSH PRIVILEGES;
```

## نصب و راه‌اندازی

### 1. دانلود پروژه

```bash
# رفتن به مسیر وب سرور
cd /var/www/html

# دانلود از GitHub
git clone https://github.com/MehdiHassaniir/PnuSeat.git

# یا دانلود zip و استخراج
wget https://github.com/MehdiHassaniir/PnuSeat/archive/master.zip
unzip master.zip
mv PnuSeat-master/* ./
```

### 2. تنظیم مجوزها

```bash
# مالکیت فایل‌ها
sudo chown -R www-data:www-data /var/www/html

# مجوزهای مناسب
sudo chmod -R 755 /var/www/html
sudo chmod -R 644 /var/www/html/*.php
```

### 3. تنظیم پایگاه داده

#### ساختار جداول مورد نیاز:

```sql
-- ساخت دیتابیس
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
```

### 4. تنظیم متغیرهای محیطی

فایل `.env` ایجاد کنید (اختیاری):

```bash
DB_HOST=localhost
DB_NAME=PnuExamsSeatNumber
DB_USER=pnu_user
DB_PASS=your_secure_password
```

### 5. تنظیم Virtual Host (اختیاری)

```bash
# ایجاد فایل تنظیمات
sudo nano /etc/apache2/sites-available/pnuseat.conf
```

محتوای فایل:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html
    
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/pnuseat_error.log
    CustomLog ${APACHE_LOG_DIR}/pnuseat_access.log combined
</VirtualHost>
```

فعال‌سازی:

```bash
sudo a2ensite pnuseat.conf
sudo systemctl reload apache2
```

## تست سامانه

### 1. بررسی دسترسی

```bash
# بررسی وضعیت Apache
sudo systemctl status apache2

# بررسی وضعیت MySQL
sudo systemctl status mysql

# بررسی PHP
php -v
```

### 2. تست در مرورگر

- آدرس: `http://localhost` یا `http://your-server-ip`
- بررسی کنسول Developer Tools برای خطاها
- تست قابلیت PWA (نصب روی دسکتاپ/موبایل)

### 3. تست API

```bash
# تست endpoint اصلی
curl -X POST http://localhost/API/getStudentExams.php \
  -d "encrypted_data=test"

# باید پاسخ JSON برگرداند
```

## امنیت

### نکات مهم امنیتی:

1. **رمز پایگاه داده**: از رمز قوی استفاده کنید
2. **SSL/HTTPS**: برای محیط تولید حتماً SSL راه‌اندازی کنید
3. **فایروال**: تنها پورت‌های لازم را باز کنید
4. **بک‌آپ**: مرتب از پایگاه داده بک‌آپ بگیرید

### راه‌اندازی SSL:

```bash
# نصب Certbot
sudo apt install certbot python3-certbot-apache

# دریافت گواهی SSL
sudo certbot --apache -d your-domain.com

# تمدید خودکار
sudo crontab -e
# اضافه کردن خط زیر:
0 12 * * * /usr/bin/certbot renew --quiet
```

## پشتیبانی و توسعه

### لاگ‌ها:

```bash
# لاگ‌های Apache
sudo tail -f /var/log/apache2/error.log

# لاگ‌های PHP
sudo tail -f /var/log/apache2/error.log
```

### بک‌آپ:

```bash
# بک‌آپ پایگاه داده
mysqldump -u pnu_user -p PnuExamsSeatNumber > backup_$(date +%Y%m%d).sql

# بک‌آپ فایل‌ها
tar -czf pnuseat_backup_$(date +%Y%m%d).tar.gz /var/www/html
```

## مشارکت و توسعه

این پروژه توسط **مهدی حسنی** در **مرکز سنجش و آزمون دانشگاه پیام نور** توسعه یافته است.

### ساختار پروژه:

```
/var/www/html/
├── index.html              # صفحه اصلی
├── manifest.json           # تنظیمات PWA
├── service-worker.js       # Service Worker
├── API/                    # فایل‌های PHP
│   ├── getStudentExams.php # دریافت اطلاعات امتحان
│   ├── index.php          # API اصلی
│   └── jdf.php            # کتابخانه تاریخ جلالی
├── assets/                 # فایل‌های استاتیک
│   ├── app/               # JS و CSS اصلی
│   ├── bootstrap/         # فریمورک CSS
│   ├── fonts/             # فونت‌های فارسی
│   └── sweetalert2/       # کتابخانه نمایش پیام
└── icons/                 # آیکون‌های PWA
```

## مجوز

این پروژه تحت مجوز MIT منتشر شده است.

---

**نسار** - مرکز سنجش و آزمون دانشگاه پیام نور