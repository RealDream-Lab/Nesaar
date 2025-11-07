# نسار – سامانه نمایش شماره صندلی آزمون‌های پیام نور

<div dir="rtl">

**نِسار** یک وب‌اپلیکیشن پیشرفته و مدرن است که با بهره‌گیری از طراحی مبتنی بر **تجربه کاربری نوین** و سبک **گلس‌مورفیسم (Glassmorphism)**، به دانشجویان دانشگاه پیام نور این امکان را می‌دهد تا **برنامه امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را به‌صورت یکپارچه و متمرکز** مشاهده و مدیریت کنند.

## مهم‌ترین قابلیت‌ها

- 🪟 **رابط کاربری شیشه‌ای**: تم iOS–style با بلور، گرادیان و انیمیشن‌های نرم در کارت‌ها و فرم‌ها
- � **هشدارهای تعاملی**: SweetAlert2 با شمارنده‌ی معکوس مینیمال، واکنش‌گرا و هماهنگ با تم
- 📱 **PWA کامل**: نصب روی موبایل/دسکتاپ، آیکن‌های جدید `pwa-icons/` و پشتیبانی از حالت آفلاین
- ⚡ **کش هوشمند**: Service Worker با استراتژی‌های به‌روزشده (Network-first برای منابع اصلی و Cache-first برای بقیه)
- 🔐 **امنیت و حریم خصوصی**: رمزگذاری شناسه‌ها، نشست ۳۰ روزه با تشخیص هوشمند نمایش/مخفی‌سازی لوگو
- 🌐 **RTL و فونت فارسی**: استفاده از Vazir با ارقام هم‌عرض (tabular) برای خوانایی بهتر تایمر و داده‌ها
- 🛡️ **سیستم لایسنس پیشرفته**: Cache هوشمند با Grace Period 24 ساعته برای اطمینان از دسترسی حتی در قطعی اینترنت

## 📚 مستندات

برای اطلاعات تکمیلی به پوشه [`docs/`](docs/) مراجعه کنید:

- **[CHANGELOG.md](docs/CHANGELOG.md)** - تاریخچه تغییرات نسخه‌ها
- **[LICENSE_CACHE_GRACE_PERIOD.md](docs/LICENSE_CACHE_GRACE_PERIOD.md)** - سیستم Cache و Grace Period لایسنس
- **[LICENSE_SMART_CHECK_DOCUMENTATION.md](docs/LICENSE_SMART_CHECK_DOCUMENTATION.md)** - سیستم هوشمند بررسی لایسنس
- **[LICENSE_CHECK_DOCUMENTATION.md](docs/LICENSE_CHECK_DOCUMENTATION.md)** - مستندات سیستم لایسنس
- **[SECURITY_IMPROVEMENTS.md](SECURITY_IMPROVEMENTS.md)** - گزارش کامل بهبودهای امنیتی

## نسخه فعلی: ۰.۳.۵

### تغییرات نسخه ۰.۳.۰

- نمایش placeholder جمع‌وجور در کارت «نمودار گزارش‌ها» در صورت نبود داده؛ شامل دکمهٔ «بارگذاری مجدد» برای تلاش مجدد.
- بهبود تجربهٔ گزارش «آزمون بعدی»: لیست اسامی به‌صورت lazy-load بارگذاری می‌شود تا بار اولیهٔ صفحه کاهش یابد؛ کاربران می‌توانند با انتخاب یک درس یا «همه دروس» اسامی را مشاهده کنند.
- بهبود UI: کارت نمودارها هنگام نبود داده حذف نمی‌شود و یک placeholder جمع‌وجور نمایش می‌یابد تا چیدمان داشبورد ثابت بماند.
- به‌روزرسانی نسخهٔ نرم‌افزار به `0.3.0` و بروزرسانی سرویس‌ورکر برای پاک‌سازی کش‌های قدیمی.
- CI: اضافه شدن منطق پاکسازی نسخه‌های قدیمی ایمیج در GHCR پس از انتشار (نیاز به PAT با دسترسی پکیج برای حذف نسخه‌ها جهت عملکرد کامل).

### تغییرات نسخه ۰.۲.۰

- هدر گزارش «آخرین گزارش درخواستی»: نمایش همیشگی خلاصه جلسه به‌صورت پرانتزی (تاریخ | ساعت | تعداد دروس | تعداد دانشجویان)
- کارت «آزمون بعدی»: در نبود جلسهٔ بعدی، کارت غیرفعال است و مودال اطلاعاتی نمایش داده نمی‌شود
- برچسب‌های پاکت تشریحی: حذف خودکار دروسی که نوع آن‌ها دقیقاً «تستی» است؛ در نبود برچسب، فقط یک پیغام تأیید نمایش داده می‌شود (بدون اسپینر)

### تغییرات نسخه ۰.۱.۱

- بهبود چاپ برچسب پاکت‌های تشریحی (A5 landscape)
- حذف صفحات خالی اضافی که بعد از هر برچسب ظاهر می‌شد
- بهینه‌سازی layout با استفاده از flexbox و کاهش فاصله‌گذاری
- اصلاح CSS برای جلوگیری از page-break اضافی
- تک‌سازی منبع markup جدول امضا (حذف تکرار کد)

## معماری و راه‌اندازی سریع

### راه‌اندازی با Docker (توصیه‌شده)

اگر Docker و Docker Compose روی سیستم شما نصب است، این روش سریع‌ترین راه برای راه‌اندازی است. تمام وابستگی‌ها (PHP، MySQL، phpMyAdmin) در کانتینرها اجرا می‌شوند.

#### پیش‌نیازها

- پورت‌های 18080 (وب‌سایت)، 3306 (MySQL)، 18081 (phpMyAdmin) آزاد باشند.

#### نصب Docker و Docker Compose (اگر نصب نیست)

```bash
sudo apt update
sudo apt install apt-transport-https ca-certificates curl gnupg lsb-release
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl start docker
sudo systemctl enable docker
```

#### مراحل راه‌اندازی

**گزینه سریع**: اجرای اسکریپت خودکار:

```bash
curl -fsSL https://raw.githubusercontent.com/RealDream-Lab/PnuSeat/master/setup.sh | bash
```

یا دانلود و اجرای دستی:

```bash
wget https://raw.githubusercontent.com/RealDream-Lab/PnuSeat/master/setup.sh
chmod +x setup.sh
./setup.sh
```

**راه‌اندازی دستی**:

```bash
git clone https://github.com/RealDream-Lab/PnuSeat.git
cd PnuSeat
```

2. **تنظیم متغیرهای محیطی**:
   فایل `.env` را ایجاد کنید و مقادیر دلخواه خود را قرار دهید (برای امنیت، رمزهای قوی انتخاب کنید):

   ```bash
   cat > .env << EOF
   # نام دیتابیس (اختیاری، پیش‌فرض: PnuExamsSeatNumber)
   DB_NAME=PnuExamsSeatNumber

   # نام کاربری دیتابیس (اختیاری، پیش‌فرض: pnu_user)
   DB_USER=pnu_user

   # رمز عبور دیتابیس (تغییر دهید!)
   DB_PASS=رمز_قوی_انتخابی_شما

   # رمز عبور root MySQL (تغییر دهید!)
   MYSQL_ROOT_PASSWORD=رمز_قوی_root

   # نام دیتابیس MySQL (اختیاری، پیش‌فرض: PnuExamsSeatNumber)
   MYSQL_DATABASE=PnuExamsSeatNumber

   # نام کاربری MySQL (اختیاری، پیش‌فرض: pnu_user)
   MYSQL_USER=pnu_user

   # رمز عبور MySQL (تغییر دهید!)
   MYSQL_PASSWORD=رمز_قوی_انتخابی_شما
   EOF
   ```

3. **استفاده از ایمیج آماده از GitHub Container Registry (توصیه شده)**:
   به جای build کردن، می‌توانید مستقیماً از ایمیج آماده استفاده کنید:

   ```bash
   # دریافت آخرین نسخه
   docker pull ghcr.io/RealDream-Lab/pnuseat:latest

   # یا دریافت نسخه خاص (مثلاً v1.7.0)
   docker pull ghcr.io/RealDream-Lab/pnuseat:v1.7.0
   ```

4. **ساخت ایمیج (اختیاری، فقط برای توسعه‌دهندگان)**:
   اگر می‌خواهید خودتان ایمیج را بسازید:

   ```bash
   git clone https://github.com/RealDream-Lab/PnuSeat.git
   cd PnuSeat
   docker build -t ghcr.io/RealDream-Lab/pnuseat:latest .
   ```

5. **ساخت و اجرای کانتینرها**:

   ```bash
   docker-compose up --build
   ```

6. **دسترسی**:
   - وب‌سایت: http://localhost:18080
   - phpMyAdmin: http://localhost:18081 (نام کاربری: `root` یا `pnu_user`، رمز: مطابق `.env`)
   - MySQL: localhost:3306

برای توقف: `Ctrl+C` سپس `docker-compose down`. برای پاک کردن داده‌ها: `docker-compose down -v`.

### راه‌اندازی دستی روی سرور (Ubuntu 22.04)

برای استقرار روی Ubuntu 22.04 به Apache، PHP 8، MySQL و git نیاز دارید. مراحل زیر خلاصه شده‌اند؛ در صورت داشتن سرور آماده می‌توانید بخش‌های غیرضروری را حذف کنید.

### ۱. پیش‌نیازها

```bash
sudo apt update -y && sudo apt upgrade -y
sudo apt install apache2 mysql-server php libapache2-mod-php \
    php-mysql php-mbstring php-xml php-curl php-zip php-cli php-bcmath php-json \
    unzip git -y
sudo systemctl enable --now apache2 mysql
```

### پیش‌نیازهای نصب و وابستگی‌ها

برای خواندن و پردازش فایل‌های اکسل (XLSX و CSV) از کتابخانه OpenSpout استفاده می‌شود که سبک، سریع و مناسب برای فایل‌های بزرگ است.

#### نصب وابستگی‌ها با Composer

اگر با Docker راه‌اندازی می‌کنید، Composer به صورت خودکار در کانتینر نصب می‌شود و وابستگی‌ها نصب خواهند شد.

در راه‌اندازی دستی، کافیست در پوشه پروژه دستور زیر را اجرا کنید:

```bash
composer install
```

این دستور تمام وابستگی‌های مورد نیاز از جمله OpenSpout را نصب می‌کند.

#### وابستگی‌های PHP مورد نیاز

- php-mbstring
- php-xml
- php-zip
- php-bcmath
- php-json

#### بسته‌های PHP اضافه‌شده:

- openspout/openspout

### ۲. دریافت و استقرار کد

```bash
cd /var/www/html
git clone https://github.com/RealDream-Lab/PnuSeat.git .
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

## مجوز

پروژه تحت مجوز MIT ارائه شده است. برای گزارش باگ یا پیشنهاد، Pull Request یا Issue در GitHub باز کنید.

---

**نسار** – مرکز سنجش و آزمون دانشگاه پیام نور

</div>
