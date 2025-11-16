# معماری امنیت لایسنس نِسار

> نسخه ۱.۰ – به‌روزرسانی ۲۵ آبان ۱۴۰۴

این سند جایگزین سه فایل قبلی (`LICENSE_CHECK_DOCUMENTATION`, `LICENSE_CACHE_GRACE_PERIOD`, `LICENSE_SMART_CHECK_DOCUMENTATION`) است و تمامی منطق‌های لایسنس را در یک مرجع یکپارچه توضیح می‌دهد.

---

## ۱. نمای کلی

سیستم لایسنس سه هدف را هم‌زمان دنبال می‌کند:

1. **اطمینان از قانونی بودن دسترسی** با بررسی مستقیم webhook.
2. **پایداری در زمان قطعی یا تاخیر شبکه** با کش، Grace Period و fallback کنترل‌شده.
3. **کاهش بار روی سرور لایسنس** با فرکانس هوشمند و ثبت آخرین وضعیت معتبر.

ملزومات اصلی:

- تمام متدها در `includes/license_guard.php` پیاده‌سازی شده‌اند.
- داده‌ها در جدول `Config` ذخیره می‌شوند و رویدادها داخل `AuditLogs` ثبت می‌گردند.
- API های کمکی: `API/getLicenseCache.php`, `API/getLicenseToken.php`, `API/updateLicenseStatus.php`, `API/updateLicenseLastChecked.php`.

---

## ۲. داده‌های ذخیره‌شده در Config

| کلید                 | نمونه مقدار                   | توضیح                                        |
| -------------------- | ----------------------------- | -------------------------------------------- |
| `LicenseToken`       | `abcd1234...`                 | توکن اصلی ارسال‌شده به webhook.              |
| `LicenseLastChecked` | `2025-11-15 08:32:04`         | آخرین زمان تلاش برای بررسی (موفق یا ناموفق). |
| `LicenseLastSuccess` | `2025-11-15 08:32:04`         | آخرین زمان بررسی موفق.                       |
| `LicenseLastStatus`  | `valid` / `invalid` / `error` | خروجی آخرین بررسی.                           |
| `LicenseCurrentType` | `permanent` / `trial`         | نوع لایسنس فعلی.                             |
| `InternalAPIToken`   | `SHA-256 hash`                | هش توکن داخلی برای APIهای درون‌سازمانی.      |

> _یادداشت:_ فیلد قدیمی `LicenseExpiry` حذف شده و مقدار انقضا تنها در کش و لاگ نگه‌داری می‌شود.

---

## ۳. فلو گام‌به‌گام

```
درخواست جدید
      ↓
license_guard_validate()
      ↓
1. بررسی وضعیت راه‌اندازی (IsInit)
2. لود داده‌های Config
3. اگر بررسی موفق اخیر وجود دارد:
   • لایسنس دائمی → بازآزمایی هر 24 ساعت
   • لایسنس آزمایشی → بازآزمایی هر 15 دقیقه
4. اگر بازآزمایی لازم باشد:
   • rate_limit_check("license_validation", 100, 60)
   • تماس با webhook
   • نوشتن نتیجه + audit_log
5. اگر webhook در دسترس نبود:
   • Grace Period 24 ساعته براساس last success
6. خروجی شامل `valid`, `message`, `usedCache`, `graceUntil`
```

---

## ۴. Grace Period و Fallback

- **مدت:** ۲۴ ساعت بعد از آخرین بررسی موفق.
- **شرط فعال شدن:** آخرین وضعیت `valid` یا `error` بوده باشد.
- **رفتار:** در پاسخ API، مقدار `graceUntil` و `usedCache` پر می‌شود تا کلاینت بداند دسترسی موقت است.
- اگر Grace منقضی شود ولی webhook همچنان در دسترس نباشد، سیستم پیام خطا می‌دهد و دسترسی را می‌بندد.

---

## ۵. فرکانس هوشمند

| فاصله تا انقضا       | نوع لایسنس | فرکانس بازآزمایی |
| -------------------- | ---------- | ---------------- |
| دائمی یا بدون expiry | permanent  | یک بار در روز    |
| > 24h                | trial      | یک بار در روز    |
| 1h < زمان < 24h      | trial      | هر ۱ ساعت        |
| < 1h                 | trial      | هر ۱ دقیقه       |

- منطق داخل `license_guard_validate` براساس مقدار `LicenseCurrentType` و سن last check پیاده‌سازی شده است.
- last checked جدید فقط زمانی ذخیره می‌شود که واقعاً تماس بیرونی انجام شود؛ پاسخ cache، شمارنده را تغییر نمی‌دهد.

---

## ۶. API ها و نحوه استفاده

| Endpoint                            | متد  | توضیح                                                               | فایل                                     |
| ----------------------------------- | ---- | ------------------------------------------------------------------- | ---------------------------------------- |
| `/API/getLicenseCache.php`          | GET  | برگرداندن وضعیت کش شده برای UI                                      | تحت محافظ `license_guard_enforce_api()`  |
| `/API/getLicenseToken.php`          | GET  | فقط برای فرایندهای داخلی، شامل token                                | محافظت شده با license guard و rate limit |
| `/API/updateLicenseStatus.php`      | POST | ذخیره نتیجهٔ جدید (برای cron یا سرویس داخلی)                        | الزام `internal_auth_enforce()`          |
| `/API/updateLicenseLastChecked.php` | POST | ابزاری برای سناریوهای legacy؛ صرفاً timestamp را به‌روزرسانی می‌کند | در حال تبدیل به حالت فقط داخلی           |

نمونه درخواست داخلی:

```bash
curl -X POST https://example.com/API/updateLicenseStatus.php \
  -H "Content-Type: application/json" \
  -H "X-Internal-Token: <token>" \
  -d '{"status":"valid","expiry":null}'
```

---

## ۷. سناریوهای عملیات

1. **حالت عادی**
   - webhook قابل دسترس است → `valid=true`, `usedCache=false`.
2. **قطع موقت اینترنت (< 24h)**
   - `valid=true`, `usedCache=true`, `graceUntil` نمایش داده می‌شود.
3. **لایسنس آزمایشی منقضی**
   - webhook پاسخ `trial` با تاریخ گذشته می‌دهد → `valid=false` با پیام فارسی و لاگ `audit_log_license(... 'expired')`.
4. **حملات flood**
   - بیش از ۱۰۰ درخواست ظرف ۶۰ ثانیه → پاسخ ۴۲۹ با پیام فارسی و لاگ rate-limit.

---

## ۸. تست و دیباگ

### بررسی وضعیت فعلی

```bash
curl -s https://example.com/API/getLicenseCache.php | jq
```

### اجبار به حالت Grace

```sql
UPDATE Config SET ConfigValue = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE ConfigName='LicenseLastSuccess';
```

### مانیتور لاگ

```sql
SELECT event_type, description, metadata, created_at
FROM AuditLogs
WHERE event_type='license_check'
ORDER BY id DESC LIMIT 10;
```

---

## ۹. نگهداشت و توصیه‌ها

- بعد از هر deploy، `bash obfuscate.sh` اجرا شود تا آدرس webhook در bundle عمومی قابل خواندن نباشد.
- فایل `database/internal_api_token.secret` باید مالک `www-data` و دسترسی 600 داشته باشد.
- در سرورهای پشت reverse-proxy، هدر `X-Forwarded-Proto` و `X-Forwarded-For` باید به‌درستی ست شوند تا `license_guard` بتواند IP واقعی را برای rate-limit استفاده کند.
- برای مانیتورینگ، حداقل روزی یک بار کوئری زیر اجرا شود:
  ```sql
  SELECT identifier, COUNT(*) AS attempts
  FROM RateLimits
  WHERE timestamp >= UNIX_TIMESTAMP() - 3600
  GROUP BY identifier
  ORDER BY attempts DESC
  LIMIT 5;
  ```

---

## ۱۰. آینده نزدیک

- **انتقال پارامتر token از query string به Authorization header** (وابسته به تیم webhook).
- **گزارش مدیریتی** برای نمایش وضعیت Grace و آخرین خطا در داشبورد.
- **هشدار ایمیلی** در صورت عبور از درصد مشخصی از Grace Period.

---

این سند باید پس از هر تغییر در `license_guard.php` یا APIهای مرتبط به‌روزرسانی شود.
