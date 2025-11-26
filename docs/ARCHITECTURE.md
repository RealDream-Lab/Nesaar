# معماری پروژه نِسار (Nesaar)

> **نسخه:** ۱.۰.۰  
> **آخرین به‌روزرسانی:** ۶ آذر ۱۴۰۴ (26 November 2025)  
> **نسخه اپلیکیشن:** ۰.۹.۶

---

## 📋 فهرست مطالب

1. [مرور کلی](#-مرور-کلی)
2. [ساختار پوشه‌ها](#-ساختار-پوشهها)
3. [لایه‌های معماری](#-لایههای-معماری)
4. [تکنولوژی‌ها و ابزارها](#-تکنولوژیها-و-ابزارها)
5. [پایگاه داده](#-پایگاه-داده)
6. [امنیت](#-امنیت)
7. [زیرساخت و استقرار](#-زیرساخت-و-استقرار)
8. [استانداردهای کدنویسی](#-استانداردهای-کدنویسی)
9. [مسیر توسعه آینده](#-مسیر-توسعه-آینده)

---

## 🎯 مرور کلی

**نِسار** یک وب‌اپلیکیشن PWA (Progressive Web Application) برای مدیریت برگزاری و نمایش شماره صندلی آزمون‌های دانشگاه پیام نور است. این سامانه با معماری **Monolithic MVC-like** طراحی شده و شامل سه بخش اصلی است:

| بخش                            | توضیح                                     | کاربران هدف    |
| ------------------------------ | ----------------------------------------- | -------------- |
| **صفحه اصلی (Frontend)**       | نمایش شماره صندلی و برنامه امتحانات       | دانشجویان      |
| **داشبورد مدیریت**             | مدیریت داده‌ها، گزارش‌گیری، تخصیص مراقبین | مدیران         |
| **پنل گزارش‌گیری (Recipient)** | دسترسی محدود به گزارشات                   | همکاران اجرائی |

---

## 📁 ساختار پوشه‌ها

```
/var/www/html/
├── index.php                    # Entry point - صفحه اصلی (دانشجویان/همکاران)
├── service-worker.js            # PWA Service Worker
├── manifest.json                # PWA Manifest
├── composer.json                # PHP Dependencies
├── docker-compose.yml           # Docker Orchestration
├── Dockerfile                   # Docker Build Instructions
├── obfuscate.sh                 # JS Obfuscation Script
├── setup.sh                     # Initial Setup Script
│
├── API/                         # 🔌 Backend API Layer
│   ├── db_init.php              # Database connection & initialization
│   ├── jdf.php                  # Jalali Date Functions
│   ├── index.php                # API Documentation/Entry
│   │
│   ├── # --- Authentication ---
│   ├── adminLogin.php           # Admin login
│   ├── adminLogout.php          # Admin logout
│   ├── adminSession.php         # Admin session validation
│   ├── recipientSession.php     # Recipient session validation
│   ├── recipientLogout.php      # Recipient logout
│   ├── userLogout.php           # General user logout
│   │
│   ├── # --- Data Management ---
│   ├── getStudentExams.php      # Fetch student exam data
│   ├── getStudentReport.php     # Generate student report
│   ├── getCourseReport.php      # Course-based reports
│   ├── getNextExamReport.php    # Upcoming exam report
│   ├── getStatistics.php        # Dashboard statistics
│   │
│   ├── # --- Proctors Module ---
│   ├── getProctors.php          # List proctors
│   ├── saveProctor.php          # Create/Update proctor
│   ├── deleteProctor.php        # Delete proctor
│   ├── getProctorAssignments.php
│   ├── getProctorRestrictions.php
│   ├── saveProctorRestrictions.php
│   ├── getProctorSessions.php
│   ├── getProctorNotifications.php
│   │
│   ├── # --- Exam Assignments ---
│   ├── assignDaily.php          # Daily assignment algorithm
│   ├── assignScattered.php      # Scattered assignment algorithm
│   ├── generateExamAssignments.php
│   ├── getExamAssignments.php
│   ├── getAssignmentsPresence.php
│   │
│   ├── # --- Locations ---
│   ├── getLocations.php
│   ├── getLocationsCount.php
│   ├── getLocationsZeros.php
│   ├── saveLocationProctors.php
│   │
│   ├── # --- Configuration ---
│   ├── getConfig.php
│   ├── saveConfig.php
│   ├── updateConfig.php
│   ├── migrate_config.php
│   │
│   ├── # --- License Management ---
│   ├── getLicenseCache.php
│   ├── getLicenseToken.php
│   ├── updateLicenseStatus.php
│   ├── updateLicenseLastChecked.php
│   │
│   ├── # --- Database Operations ---
│   ├── uploadDatabase.php       # Excel upload processing
│   ├── updateDatabase.php       # Database update from temp
│   ├── processExcelToTemp.php   # Excel to temp tables
│   ├── validateExcelHeader.php  # Excel validation
│   ├── getTempTablesCount.php
│   ├── getProcessProgress.php
│   │
│   ├── # --- Reports & PDF ---
│   ├── generatePDF.php          # PDF generation (mPDF)
│   ├── getExamsDetail.php
│   ├── saveExamsDetail.php
│   ├── saveExamsDetailRow.php
│   │
│   └── # --- Miscellaneous ---
│       ├── serverTime.php
│       ├── getSmsCredit.php
│       ├── getUploadLimit.php
│       ├── getCoworkerSessions.php
│       └── getRecipientCredentials.php
│
├── includes/                    # 🛡️ Core PHP Modules (Security & Utilities)
│   ├── license_guard.php        # License validation with cache & grace period
│   ├── csrf_protection.php      # CSRF token generation & validation
│   ├── rate_limit.php           # Request rate limiting
│   ├── audit_log.php            # Event logging system
│   ├── internal_auth.php        # Internal API authentication
│   ├── login_guard.php          # Login attempt protection
│   ├── captcha_math.php         # Math CAPTCHA implementation
│   │
│   ├── # --- Session Management ---
│   ├── admin_session.php        # Admin session handler
│   ├── user_session.php         # User session handler
│   ├── coworker_session.php     # Coworker session handler
│   ├── recipient_session.php    # Recipient session handler
│   ├── privileged_session.php   # Privileged access session
│   └── session_tokens.php       # Secure session tokens
│
├── dashboard/                   # 📊 Admin Dashboard
│   ├── index.php                # Dashboard entry point
│   ├── dashboard.js             # Main dashboard JavaScript
│   └── observers/               # Proctors/Observers module
│       ├── index.php
│       ├── observers.js
│       └── *.png                # Module icons
│
├── Recipient/                   # 📋 Recipient Panel (Reports)
│   ├── index.php
│   └── dashboard.js
│
├── assets/                      # 🎨 Frontend Assets
│   ├── app/                     # Application-specific assets
│   │   ├── app.js               # Main frontend JavaScript
│   │   ├── style.css            # Main stylesheet
│   │   ├── version.js           # Version info (APP_VERSION)
│   │   ├── logo.png
│   │   ├── Pnulogo.png
│   │   └── *.png                # Other icons
│   │
│   ├── bootstrap/               # Bootstrap CSS/JS
│   ├── sweetalert2/             # SweetAlert2 library
│   ├── fonts/                   # Vazir font files
│   │   └── vazir/
│   ├── vendor/                  # Third-party JS libraries
│   │   └── chartjs/
│   └── crypto-js.min.js         # Encryption library
│
├── pwa-icons/                   # 📱 PWA Icons
│   ├── icon-192.png
│   └── icon-512.png
│
├── docs/                        # 📚 Documentation
│   ├── ARCHITECTURE.md          # This file
│   ├── CHANGELOG.md             # Version history
│   ├── LICENSE_SECURITY.md      # License system docs
│   └── SECURITY_IMPROVEMENTS.md # Security documentation
│
├── database/                    # 💾 Runtime Data
│   ├── excel_log.txt
│   ├── upload_log.txt
│   ├── progress_update.json
│   └── internal_api_token.secret
│
├── docker/                      # 🐳 Docker Configuration
│   └── mysql-init/              # MySQL initialization scripts
│
├── vendor/                      # 📦 Composer Dependencies
│   └── ...
│
├── backup/                      # 🗄️ Code Backups
├── temp/                        # ⏳ Temporary Files
├── reports/                     # 📄 Generated Reports
├── scripts/                     # 🔧 Utility Scripts
│
└── .github/                     # 🔄 GitHub Actions
    └── workflows/
        └── docker.yml           # CI/CD Pipeline
```

---

## 🏗️ لایه‌های معماری

### 1. لایه ارائه (Presentation Layer)

```
┌─────────────────────────────────────────────────────────────┐
│                     PRESENTATION LAYER                       │
├─────────────────────────────────────────────────────────────┤
│  index.php          │ dashboard/index.php │ Recipient/       │
│  (Student Portal)   │ (Admin Dashboard)   │ (Reports Panel)  │
├─────────────────────────────────────────────────────────────┤
│  assets/app/app.js  │ dashboard.js        │ dashboard.js     │
│  assets/app/style.css │ observers/observers.js               │
├─────────────────────────────────────────────────────────────┤
│  PWA: service-worker.js, manifest.json                       │
└─────────────────────────────────────────────────────────────┘
```

**مسئولیت‌ها:**

- رندر HTML/CSS با استفاده از PHP templating
- تعاملات کاربر با JavaScript vanilla
- PWA functionality (کش، نصب، آفلاین)
- طراحی Glassmorphism با RTL support

### 2. لایه API (API Layer)

```
┌─────────────────────────────────────────────────────────────┐
│                        API LAYER                             │
├─────────────────────────────────────────────────────────────┤
│                        API/*.php                             │
├─────────────────────────────────────────────────────────────┤
│  Request → Session Check → CSRF Check → Rate Limit          │
│         → License Guard → Business Logic → Response          │
└─────────────────────────────────────────────────────────────┘
```

**استانداردها:**

- همه APIها JSON response برمی‌گردانند
- Error handling با HTTP status codes مناسب
- Input validation قبل از پردازش
- Output escaping برای جلوگیری از XSS

### 3. لایه امنیت (Security Layer)

```
┌─────────────────────────────────────────────────────────────┐
│                      SECURITY LAYER                          │
├─────────────────────────────────────────────────────────────┤
│  includes/license_guard.php    - License validation          │
│  includes/csrf_protection.php  - CSRF tokens                 │
│  includes/rate_limit.php       - Request throttling          │
│  includes/audit_log.php        - Event logging               │
│  includes/internal_auth.php    - Internal API auth           │
│  includes/login_guard.php      - Brute-force protection      │
│  includes/*_session.php        - Session management          │
└─────────────────────────────────────────────────────────────┘
```

### 4. لایه داده (Data Layer)

```
┌─────────────────────────────────────────────────────────────┐
│                       DATA LAYER                             │
├─────────────────────────────────────────────────────────────┤
│  API/db_init.php  - PDO connection & initialization          │
│  MySQL 8.x        - Primary database                         │
│  Config table     - Application settings                     │
│  AuditLogs table  - Security events                          │
│  RateLimits table - Rate limiting data                       │
└─────────────────────────────────────────────────────────────┘
```

---

## 🛠️ تکنولوژی‌ها و ابزارها

### Backend

| تکنولوژی  | نسخه  | کاربرد               |
| --------- | ----- | -------------------- |
| PHP       | 8.3   | زبان سرور            |
| MySQL     | 8.x   | پایگاه داده          |
| PDO       | -     | Database abstraction |
| mPDF      | ^8.2  | تولید PDF            |
| OpenSpout | ^4.32 | پردازش Excel         |

### Frontend

| تکنولوژی          | نسخه    | کاربرد            |
| ----------------- | ------- | ----------------- |
| HTML5/CSS3        | -       | ساختار و استایل   |
| JavaScript (ES6+) | Vanilla | تعاملات کلاینت    |
| Bootstrap         | 5.x     | Grid & Components |
| SweetAlert2       | -       | Dialogs & Toasts  |
| Chart.js          | -       | نمودارها          |
| CryptoJS          | -       | رمزنگاری کلاینت   |
| Vazir Font        | -       | فونت فارسی        |

### DevOps

| ابزار                 | کاربرد                 |
| --------------------- | ---------------------- |
| Docker                | Containerization       |
| Docker Compose        | Orchestration          |
| GitHub Actions        | CI/CD                  |
| Watchtower            | Auto-update containers |
| javascript-obfuscator | Code protection        |

---

## 💾 پایگاه داده

### جداول اصلی

```sql
-- دانشجویان
students (
    student_id CHAR(9) PRIMARY KEY,
    national_id CHAR(10),
    source_center CHAR(4),
    destination_center CHAR(4),
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    degree VARCHAR(15)
)

-- دروس
courses (
    course_code CHAR(7) PRIMARY KEY,
    course_name VARCHAR(100),
    exam_date CHAR(10),        -- تاریخ شمسی
    exam_time CHAR(5),
    course_type VARCHAR(15)
)

-- صندلی‌های امتحان
exam_seats (
    id BIGINT PRIMARY KEY,
    student_id CHAR(9),
    course_code CHAR(7),
    seat_number INT,
    building VARCHAR(100),
    class_name VARCHAR(50),
    seat_row INT,
    exam_type VARCHAR(15)
)

-- مراقبین
Proctors (
    id INT PRIMARY KEY,
    name VARCHAR(100),
    phone VARCHAR(15),
    national_id CHAR(10),
    is_active BOOLEAN
)

-- تخصیص‌های آزمون
ExamAssignments (
    id INT PRIMARY KEY,
    proctor_id INT,
    exam_date CHAR(10),
    exam_time CHAR(5),
    location_id INT
)

-- تنظیمات
Config (
    ID INT PRIMARY KEY,
    ConfigName VARCHAR(50) UNIQUE,
    ConfigValue TEXT
)

-- لاگ امنیتی
AuditLogs (
    id BIGINT PRIMARY KEY,
    event_type VARCHAR(50),
    description TEXT,
    metadata JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP
)

-- محدودیت نرخ
RateLimits (
    id BIGINT PRIMARY KEY,
    identifier VARCHAR(100),
    key_name VARCHAR(50),
    count INT,
    timestamp INT
)
```

### Timezone

- PHP: `Asia/Tehran`
- MySQL: `+03:30`
- تاریخ‌ها: تقویم شمسی (Jalali) با استفاده از `jdf.php`

---

## 🔐 امنیت

### ۱. محافظت CSRF

```php
// در PHP
require_once 'includes/csrf_protection.php';
csrf_enforce();  // در ابتدای هر API

// در HTML
<?php echo csrf_meta_tag(); ?>

// در JavaScript
guardedFetch(url, options);  // اتوماتیک هدر اضافه می‌کند
```

### ۲. Rate Limiting

```php
require_once 'includes/rate_limit.php';
rate_limit_check('api_name', 100, 60);  // 100 req/min
```

### ۳. License Guard

```php
require_once 'includes/license_guard.php';
$status = license_guard_validate();
if (!$status['valid']) {
    // Deny access
}
```

### ۴. Session Security

- کوکی‌های امن با `HttpOnly` و `SameSite`
- Token-based session validation
- Automatic session expiry
- IP binding (optional)

### ۵. Input Validation

- همه ورودی‌ها باید sanitize شوند
- استفاده از prepared statements برای SQL
- Whitelist validation برای enum fields

---

## 🐳 زیرساخت و استقرار

### Docker Services

```yaml
services:
  web:        # PHP/Apache - Port 18080
  db:         # MySQL - Port 3306
  Admin:      # phpMyAdmin - Port 18081
  watchtower: # Auto-update
```

### CI/CD Pipeline

```
Push Tag (v*) → Build Docker Image → Obfuscate JS
             → Push to GHCR → Cleanup old versions
```

### Environment Variables

```env
DB_HOST=db
DB_NAME=PnuExamsSeatNumber
DB_USER=pnu_user
DB_PASS=<secure_password>
TZ=Asia/Tehran
SESSION_COOKIE_ALLOW_INSECURE=0  # Set to 1 for dev
```

---

## 📝 استانداردهای کدنویسی

### PHP

- PSR-12 coding style
- Type hints برای parameters و return types
- DocBlocks برای همه توابع public
- Error handling با try-catch
- No direct `echo` in API files (use `json_encode`)

```php
/**
 * Validates user input for exam lookup.
 *
 * @param string $studentId Student ID (9 digits)
 * @param string $nationalId National ID (10 digits)
 * @return array{valid: bool, errors: string[]}
 */
function validateInput(string $studentId, string $nationalId): array
{
    // Implementation
}
```

### JavaScript

- ES6+ syntax (const/let, arrow functions, async/await)
- No global variables (use IIFE or modules)
- Error handling در همه fetch calls
- Comments برای logic پیچیده

```javascript
/**
 * Fetches exam data for the given student.
 * @param {string} studentId - The 9-digit student ID
 * @returns {Promise<Object>} Exam data object
 */
async function getStudentExams(studentId) {
    // Implementation
}
```

### CSS

- BEM naming convention
- CSS variables برای تم
- Mobile-first responsive design
- RTL-first approach

### نام‌گذاری فایل‌ها

| نوع         | قالب           | مثال                |
| ----------- | -------------- | ------------------- |
| PHP API     | camelCase.php  | getStudentExams.php |
| PHP Include | snake_case.php | csrf_protection.php |
| JavaScript  | camelCase.js   | dashboard.js        |
| CSS         | kebab-case.css | style.css           |

---

## 🔮 مسیر توسعه آینده

### Technical Debt (بدهی فنی)

| اولویت | مورد              | توضیح                      |
| ------ | ----------------- | -------------------------- |
| بالا   | تست خودکار        | پیاده‌سازی PHPUnit و Jest  |
| بالا   | API Documentation | OpenAPI/Swagger specs      |
| متوسط  | Caching Layer     | Redis برای session و cache |
| متوسط  | Error Monitoring  | Sentry integration         |
| پایین  | Code Splitting    | Lazy loading برای JS       |

### بهبودهای پیشنهادی

1. **Microservices**: جداسازی License service
2. **WebSocket**: Real-time updates برای dashboard
3. **Offline-First**: Enhanced PWA با IndexedDB
4. **i18n**: پشتیبانی چندزبانه
5. **A11y**: بهبود دسترس‌پذیری

---

## 📎 پیوندهای مرتبط

- [CHANGELOG.md](./CHANGELOG.md) - تاریخچه تغییرات
- [LICENSE_SECURITY.md](./LICENSE_SECURITY.md) - معماری لایسنس
- [SECURITY_IMPROVEMENTS.md](./SECURITY_IMPROVEMENTS.md) - مستندات امنیتی

---

> **نکته:** این سند باید با هر تغییر ساختاری یا تکنولوژیکی به‌روزرسانی شود.

---

**نِسار** – مرکز سنجش و آزمون دانشگاه پیام نور بیجار
