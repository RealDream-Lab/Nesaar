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
    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE PnuExamsSeatNumber;

-- جداول students، courses و exam_seats مطابق فایل docker/mysql-init/schema.sql
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

- **Service Worker**: فایل `service-worker.js` با شناسهٔ کش نسخه‌دار (`exam-seat-YYYY-MM-DD-nn`) مدیریت می‌شود تا با هر انتشار، کاربران آخرین نسخه را بگیرند. مسیرهای HTML، CSS، JS و manifest به صورت *network-first* کش می‌شوند تا در هر بار انتشار ظاهر تازه نمایش داده شود.
- **PWA نصب‌پذیر**: تمامی ارجاعات آیکن به مسیر `pwa-icons/` منتقل شده تا با Alias پیش‌فرض Apache تداخل نداشته باشد.
- **SweetAlert2**: هشدارهای زمان‌دار علاوه بر شمارنده‌ی معکوس عددی در گوشه‌ی بالا-چپ، نوار پیشرفت پیش‌فرض را حذف می‌کنند تا یکدستی طراحی حفظ شود.
- **فونت‌ها**: فونت Vazir با ارقام فارسی هم‌عرض (tabular-nums) برای نمایش دقیق تایمر و داده‌های عددی فعال شده است.
- **تم شیشه‌ای**: فایل `assets/app/style.css` شامل متغیرهای CSS و لایه‌های پس‌زمینه با فیلتر بلور، آیکن‌های نورانی و کارت‌های شیشه‌ای است.

## تست و نگهداری

- برای ریست کش کاربران پس از انتشار، فقط کافی است نسخهٔ `CACHE_NAME` را افزایش دهید؛ Service Worker بلافاصله فعال می‌شود و نسخه‌های قدیمی حذف می‌گردد.
- جهت بررسی، از ابزار Lighthouse در مرورگر استفاده کنید؛ سامانه برای PWA، دسترس‌پذیری RTL و عملکرد بهینه‌سازی شده است.
- لاگ‌های مهم:

```bash
sudo tail -f /var/log/apache2/error.log
sudo tail -f /var/log/apache2/access.log
```

## ساختار پروژه

</div>

<div dir="ltr">

```
/var/www/html/
├── index.html              # صفحه اصلی (فرم ورود، کارت آزمون‌ها)
├── manifest.json           # تنظیمات PWA و آیکن‌ها
├── service-worker.js       # مدیریت کش و آفلاین
├── API/
│   ├── getStudentExams.php # دریافت اطلاعات آزمون از پایگاه داده
│   ├── index.php
│   └── jdf.php             # کمکی تقویم جلالی
├── assets/
│   ├── app/
│   │   ├── app.js          # منطق UI، SweetAlert، مدیریت نشست
│   │   └── style.css       # تم شیشه‌ای و استایل‌ها
│   ├── bootstrap/
│   ├── fonts/
│   └── sweetalert2/
├── docker/
│   └── mysql-init/
│       └── schema.sql      # نمونه ساختار پایگاه داده
└── pwa-icons/              # آیکن‌های PWA (192px، 512px)
```

</div>

<div dir="rtl">

## مجوز

پروژه تحت مجوز MIT ارائه شده است. برای گزارش باگ یا پیشنهاد، Pull Request یا Issue در GitHub باز کنید.

---

**نسار** – مرکز سنجش و آزمون دانشگاه پیام نور

</div>