# نقشه امنیتی نِسار – آبان ۱۴۰۴

این سند خلاصهٔ همهٔ اقداماتی است که در هفته‌های اخیر برای افزایش امنیت سامانه انجام شده است و وضعیت فعلی هر لایه را مشخص می‌کند. هدف این است که توسعه‌دهندگان بدانند چه چیزهایی فعال است، چه سناریوهایی باید تست شود و برای استقرار بعدی چه چک‌لیست‌هایی داریم.

---

## 🎯 خلاصه اجرایی

- لایهٔ لایسنس گارد بازنویسی شد (`includes/license_guard.php`) و الان قبل از سرو کردن هر API وضعیت لایسنس را با **کش، Grace Period و ریت‌لیمیت** بررسی می‌کند.
- تمام درخواست‌های حساس سمت سرور با **CSRF، نشست امن و توابع `guardedFetch`** در `assets/app/app.js` و `dashboard/dashboard.js` محافظت می‌شوند.
- برای جلوگیری از سوءاستفادهٔ داخلی، **احراز هویت داخلی** (`includes/internal_auth.php`) و **ثبت وقایع** (`includes/audit_log.php`) به‌صورت پیش‌فرض در تمام جریان‌های لایسنس فعال شد.
- APIها و اسکریپت‌های داشبورد قبل از اجرای عملیات، **Rate Limiting سطح IP** و **بررسی نشست** را فراخوانی می‌کنند؛ بنابراین حملات brute-force و flood کنترل می‌شود.

نتیجه: سطح ریسک بحرانی از «قرمز» به «سبز» منتقل شده و الان پنج لایهٔ دفاعی هم‌زمان فعال است.

---

## 🧱 لایه‌های دفاعی

### 1. لایسنس گارد و کش هوشمند

- فایل اصلی: `includes/license_guard.php`
- هر API حساس ابتدا `license_guard_enforce_api()` را صدا می‌زند (مثلاً `API/getLicenseToken.php`, `API/getLicenseCache.php`, `API/updateLicenseStatus.php`).
- لایسنس دایمی هر ۲۴ ساعت و لایسنس آزمایشی هر ۱۵ دقیقه بازآزمایی می‌شود. اگر webhook در دسترس نباشد، Grace Period ۲۴ ساعته فعال می‌شود.
- برای هر بار چک جدید، rate-limit ۱۰۰ درخواست در ۶۰ ثانیه اعمال می‌شود و نتیجه در جدول Config + AuditLogs ذخیره می‌شود.

### 2. احراز هویت داخلی

- فایل: `includes/internal_auth.php`
- توکن ۶۴ کاراکتری در `Config.InternalAPIToken` نگهداری و نسخهٔ plaintext در `database/internal_api_token.secret` ذخیره می‌شود.
- API های داخلی (مثل `updateLicenseStatus.php`) فقط وقتی اجرا می‌شوند که:
  1. هدر `X-Internal-Token` معتبر باشد، یا
  2. درخواست از `127.0.0.1/::1` بیاید، یا
  3. هدر `X-Internal-Call: true` تنظیم شده باشد.

### 3. محافظت CSRF و نشست امن

- فایل: `includes/csrf_protection.php`
- متا‌تگ `csrf_meta_tag()` در `index.php` و داشبورد تزریق می‌شود؛ `guardedFetch` در سمت کلاینت به صورت خودکار توکن را به هر درخواست POST/PUT/PATCH/DELETE اضافه می‌کند.
- تمام فُرم‌ها و APIهای مدیریتی قبل از اجرا `csrf_enforce()` را صدا می‌زنند. در صورت خطا، پاسخ ۴۰۳ JSON استاندارد برمی‌گردد.

### 4. Rate Limiting و پاسخ انسانی

- فایل: `includes/rate_limit.php`
- داده‌ها در جدول `RateLimits` ذخیره می‌شود و هر ساعت رکوردهای منقضی حذف می‌شوند.
- برای هر کلید (مثلاً `license_validation`, `admin_login`, `api_upload`) می‌توان پنجره، سقف و پیام را تنظیم کرد. پاسخ ۴۲۹ با پیام فارسی و هدر `Retry-After` ارسال می‌شود.

### 5. لاگ‌برداری و مانیتورینگ

- فایل: `includes/audit_log.php`
- همهٔ رخدادها (بررسی لایسنس، تغییر تنظیمات، ورود/خروج، دسترسی API) در جدول `AuditLogs` با متادیتای IP و User-Agent ذخیره می‌شود.
- توابع کمکی `audit_log_license`, `audit_log_auth`, `audit_log_config`, `audit_log_api` در APIهای جدید استفاده شده‌اند؛ برای پاک‌سازی هم `audit_cleanup($pdo, $keepDays)` داریم.

---

## 🆕 تغییرات شاخص اخیر

| ردیف | تغییر                                                                                 | فایل‌های درگیر                                                       | وضعیت   |
| ---- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------------- | ------- |
| 1    | بازنویسی کامل `license_guard_validate` با کش، Grace و لاگ                             | `includes/license_guard.php`, `includes/audit_log.php`               | ✅ فعال |
| 2    | فعال‌سازی `internal_auth_enforce()` روی `API/updateLicenseStatus.php` و webhook داخلی | `API/updateLicenseStatus.php`, `includes/internal_auth.php`          | ✅ فعال |
| 3    | یکپارچه‌سازی `csrf_meta_tag` و `guardedFetch` در داشبورد                              | `dashboard/index.php`, `dashboard/dashboard.js`, `assets/app/app.js` | ✅ فعال |
| 4    | افزودن rate-limit با پیام فارسی روی تمام مسیرهای لایسنس                               | `includes/rate_limit.php`, `includes/license_guard.php`              | ✅ فعال |
| 5    | اضافه شدن بررسی نشست امن پشت پروکسی و پشتیبانی از کوکی ناامن برای محیط dev            | `includes/internal_auth.php`, `includes/session_tokens.php`          | ✅ فعال |

---

## 🔍 سناریوهای تست پیشنهادی

1. **تست لایسنس در حالت عادی**
   - اجرای `curl /API/getLicenseCache.php` → پاسخ ۲۰۰ همراه با `usedCache: true/false`.
2. **شبیه‌سازی قطع وب‌هوک**
   - تغییر DNS یا خاموش‌کردن اینترنت → انتظار پیام «Grace period is active». مقدار `graceUntil` باید در پاسخ باشد.
3. **Rate-Limit**
   - ارسال ۱۱۰ درخواست ظرف ۶۰ ثانیه به `API/getLicenseToken.php` → پاسخ ۴۲۹ با پیام فارسی و هدر `Retry-After`.
4. **CSRF**
   - ارسال POST بدون هدر `X-CSRF-Token` → پاسخ ۴۰۳ `csrf_validation_failed`.
5. **احراز هویت داخلی**
   - فراخوانی `API/updateLicenseStatus.php` از یک IP غیرمجاز بدون هدر → پاسخ ۴۰۳ `forbidden`.
6. **Audit Log**
   - اجرای یکی از سناریو‌های بالا و سپس:
     ```sql
     SELECT event_type, description, created_at
     FROM AuditLogs ORDER BY id DESC LIMIT 5;
     ```
     باید رکورد جدید با IP و متادیتا ثبت شده باشد.

---

## 🧾 فایل‌ها و ماژول‌های مرتبط

- `includes/license_guard.php` – منطق اصلی صدور مجوز + ریت‌لیمیت و Grace
- `includes/internal_auth.php` – تولید و اعتبارسنجی توکن داخلی
- `includes/csrf_protection.php` – ساخت متاتگ، اعتبارسنجی هدر، helper فرم‌ها
- `includes/rate_limit.php` – helper عمومی برای شمارش، پاک‌سازی و enforce
- `includes/audit_log.php` – ثبت وقایع + توابع تخصصی برای license/config/auth/api
- `assets/app/app.js`, `dashboard/dashboard.js` – متد `guardedFetch`, اضافه‌کردن هدرهای CSRF و مدیریت خطاها

---

## ✅ چک‌لیست استقرار Production

1. `php includes/internal_auth.php` → تولید توکن و اطمینان از وجود فایل `database/internal_api_token.secret` با دسترسی 600.
2. اجرای `bash obfuscate.sh` بعد از هر build تا مسیرهای حساس سمت کلاینت مخفی بمانند.
3. مانیتور `AuditLogs` و `RateLimits` با کوئری‌های زیر:
   ```sql
   SELECT event_type, COUNT(*) FROM AuditLogs WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY event_type;
   SELECT identifier, COUNT(*) FROM RateLimits WHERE timestamp >= UNIX_TIMESTAMP() - 3600 GROUP BY identifier;
   ```
4. بررسی دسترسی کرون/وب‌هوک به `API/updateLicenseStatus.php` و اضافه‌کردن هدر `X-Internal-Token`.
5. فعال‌سازی HTTPS و اطمینان از قرارگیری سرور پشت Reverse Proxy با هدری که در `includes/session_tokens.php` تنظیم شده است.

---

## ℹ️ نکات تکمیلی

- فایل‌های قبلی مربوط به «بهبودهای امنیتی لایسنس» با این سند ادغام شده‌اند؛ از این پس تنها مرجع رسمی وضعیت امنیتی، همین فایل است.
- در صورت اضافه‌شدن لایه جدید (مثلاً WAF یا IDS)، لطفاً بخش «لایه‌های دفاعی» و «سناریوهای تست» را به‌روزرسانی کنید.
- هرگونه تغییر در ساختار جداول `Config`, `RateLimits`, `AuditLogs` باید در این سند و `docs/LICENSE_SECURITY.md` ثبت شود.

---

**آخرین به‌روزرسانی:** ۲۵ آبان ۱۴۰۴
