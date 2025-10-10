# نسار - سامانه مشاهده شماره صندلی آزمون‌های پیام نور

<div dir="rtl">

سامانه‌ای برای مشاهده شماره صندلی و جزئیات امتحانات دانشجویان دانشگاه پیام نور

## ویژگی‌های سامانه

- 🔐 **امنیت بالا**: رمزگذاری اطلاعات حساس دانشجویان
- 📱 **PWA**: قابلیت نصب روی موبایل و دسکتاپ
- 🌐 **RTL**: پشتیبانی کامل از زبان فارسی و طراحی راست‌چین
- 🔄 **Session Management**: نگهداری جلسه کاربر تا ۳۰ روز
- 📋 **کارت‌های زیبا**: نمایش اطلاعات امتحانات در قالب کارت‌های جذاب
- ⚡ **عملکرد سریع**: کش محلی و Service Worker برای بارگذاری سریع

## نیازمندی‌های سرور

<div dir="rtl">

برای راه‌اندازی سریع سرویس، مراحل زیر را به ترتیب اجرا کنید. توضیح هر مرحله بیرون از بلوک کد آمده تا جهت متن به‌درستی نمایش داده شود.

### ۱️⃣ به‌روزرسانی سیستم
</div>

```bash
sudo apt update -y
sudo apt upgrade -y
```

<div dir="rtl">

### ۲️⃣ نصب Apache و فعال‌سازی
</div>

```bash
sudo apt install apache2 -y
sudo systemctl enable apache2
sudo systemctl start apache2
```

<div dir="rtl">

### ۳️⃣ نصب MySQL
</div>

```bash
sudo apt install mysql-server -y
sudo systemctl enable mysql
sudo systemctl start mysql
sudo mysql_secure_installation
```

<div dir="rtl">

### ۴️⃣ نصب PHP و افزونه‌های مورد نیاز
</div>

```bash
sudo apt install php libapache2-mod-php php-mysql php-mbstring php-xml php-curl php-zip php-cli php-bcmath php-json unzip git -y
```

<div dir="rtl">

### ۵️⃣ تنظیم Apache برای PHP
</div>

```bash
sudo a2enmod php*
sudo systemctl restart apache2
```

<div dir="rtl">

### ۶️⃣ نصب phpMyAdmin
</div>

```bash
sudo apt install phpmyadmin -y
sudo phpenmod mbstring
sudo systemctl restart apache2
```

<div dir="rtl">

### ۷️⃣ نصب Node.js و npm
</div>

```bash
sudo apt install curl -y
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

<div dir="rtl">

### ۸️⃣ اطمینان از فعال بودن سرویس‌ها
</div>

```bash
sudo systemctl status apache2
sudo systemctl status mysql
```

<div dir="rtl">

## نصب و راه‌اندازی

### 1. دانلود پروژه

</div>

```bash
# Navigate to web root
cd /var/www/html

# Clone from GitHub
git clone https://github.com/MehdiHassaniir/PnuSeat.git

# Or download zip and extract
wget https://github.com/MehdiHassaniir/PnuSeat/archive/master.zip
unzip master.zip
mv PnuSeat-master/* ./
```

<div dir="rtl">

### 2. تنظیم مجوزها

</div>

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/html

# Set permissions
sudo chmod -R 755 /var/www/html
sudo chmod -R 644 /var/www/html/*.php
```

<div dir="rtl">

### 3. تنظیم پایگاه داده

#### ساختار جداول مورد نیاز:

</div>

```sql
-- Create database
CREATE DATABASE IF NOT EXISTS PnuExamsSeatNumber
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

-- Select database
USE PnuExamsSeatNumber;

-- Students table
CREATE TABLE students (
    student_id CHAR(9) PRIMARY KEY,              -- Student ID (9 digits)
    national_id CHAR(10) NOT NULL,               -- National ID (10 digits)
    source_center CHAR(4) NOT NULL,              -- Source center code
    destination_center CHAR(4) NOT NULL,         -- Destination center code
    first_name VARCHAR(50) NOT NULL,             -- First name
    last_name VARCHAR(50) NOT NULL,              -- Last name
    degree VARCHAR(15) NOT NULL,                 -- Degree level
    INDEX idx_name (last_name, first_name),
    INDEX idx_source_dest (source_center, destination_center)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Courses table
CREATE TABLE courses (
    course_code CHAR(7) PRIMARY KEY,             -- Course code (7 digits)
    course_name VARCHAR(100) NOT NULL,           -- Course name
    exam_date CHAR(10) NOT NULL,                 -- Exam date (e.g., 1404/10/25)
    exam_time CHAR(5) NOT NULL,                  -- Exam time (HH:MM)
    exam_type VARCHAR(15) NOT NULL,              -- Exam type (in-person / virtual)
    course_type VARCHAR(15) NOT NULL,            -- Course type (theory / practical)
    INDEX idx_exam_date (exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Exam seats mapping table
CREATE TABLE exam_seats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id CHAR(9) NOT NULL,
    course_code CHAR(7) NOT NULL,
    seat_number INT NOT NULL,                    -- Seat number
    building VARCHAR(100) NOT NULL,              -- Building name
    class_name VARCHAR(50) NOT NULL,             -- Classroom
    seat_row INT NOT NULL,                       -- Seat row
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (course_code) REFERENCES courses(course_code) ON DELETE CASCADE,
    UNIQUE KEY uniq_student_course (student_id, course_code),
    INDEX idx_building_class (building, class_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

<div dir="rtl">

### 4. تنظیم متغیرهای محیطی

فایل <code>.env</code> ایجاد کنید (اختیاری):

</div>

```bash
DB_HOST=localhost
DB_NAME=PnuExamsSeatNumber
DB_USER=pnu_user
DB_PASS=your_secure_password
```

<div dir="rtl">

### 5. تنظیم Virtual Host (اختیاری)

</div>

```bash
# Create vhost config
sudo nano /etc/apache2/sites-available/pnuseat.conf
```

<div dir="rtl">

محتوای فایل:

</div>

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

<div dir="rtl">

فعال‌سازی:

</div>

```bash
sudo a2ensite pnuseat.conf
sudo systemctl reload apache2
```

<div dir="rtl">

## تست سامانه

### 1. بررسی دسترسی

</div>

```bash
# Check Apache status
sudo systemctl status apache2

# Check MySQL status
sudo systemctl status mysql

# Check PHP version
php -v
```

<div dir="rtl">

### 2. تست در مرورگر

- آدرس: <code>http://localhost</code> یا <code>http://your-server-ip</code>
- بررسی کنسول Developer Tools برای خطاها
- تست قابلیت PWA (نصب روی دسکتاپ/موبایل)

### 3. تست API

</div>

```bash
# Test main endpoint
curl -X POST http://localhost/API/getStudentExams.php \
  -d "encrypted_data=test"

# Expected: JSON response
```

<div dir="rtl">

## امنیت

### نکات مهم امنیتی:

1. **رمز پایگاه داده**: از رمز قوی استفاده کنید
2. **SSL/HTTPS**: برای محیط تولید حتماً SSL راه‌اندازی کنید
3. **فایروال**: تنها پورت‌های لازم را باز کنید
4. **بک‌آپ**: مرتب از پایگاه داده بک‌آپ بگیرید

### راه‌اندازی SSL:

</div>

```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache

# Obtain SSL certificate
sudo certbot --apache -d your-domain.com

# Configure auto-renewal
sudo crontab -e
# Add the following line:
0 12 * * * /usr/bin/certbot renew --quiet
```

<div dir="rtl">

## پشتیبانی و توسعه

### لاگ‌ها:

</div>

```bash
# Apache logs
sudo tail -f /var/log/apache2/error.log

# PHP logs
sudo tail -f /var/log/apache2/error.log
```

<div dir="rtl">

### بک‌آپ:

</div>

```bash
# Database backup
mysqldump -u pnu_user -p PnuExamsSeatNumber > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf pnuseat_backup_$(date +%Y%m%d).tar.gz /var/www/html
```

<div dir="rtl">

## مشارکت و توسعه

این پروژه توسط **مهدی حسنی** در **مرکز سنجش و آزمون دانشگاه پیام نور** توسعه یافته است.

### ساختار پروژه:

</div>

```
/var/www/html/
├── index.html              # Main page
├── manifest.json           # PWA manifest
├── service-worker.js       # Service Worker
├── API/                    # PHP endpoints
│   ├── getStudentExams.php # Fetch exam details
│   ├── index.php           # API index
│   └── jdf.php             # Jalali date helper
├── assets/                 # Static assets
│   ├── app/                # Core JS and CSS
│   ├── bootstrap/          # Bootstrap framework
│   ├── fonts/              # Persian fonts
│   └── sweetalert2/        # SweetAlert2 assets
└── icons/                  # PWA icons
```

<div dir="rtl">

## مجوز

این پروژه تحت مجوز MIT منتشر شده است.

---

**نسار** - مرکز سنجش و آزمون دانشگاه پیام نور

</div>