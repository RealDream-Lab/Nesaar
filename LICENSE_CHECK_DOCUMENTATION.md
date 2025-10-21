# مستندات سیستم بررسی لایسنس روزانه

## 📋 خلاصه
سیستم لایسنسینگ به گونه‌ای طراحی شده که **فقط یکبار در روز** (از ساعت 00:00) لایسنس را بررسی کند.

---

## 🔄 نحوه عملکرد

### 1️⃣ بررسی تاریخ آخرین چک
```javascript
// دریافت تنظیمات
const config = await fetch('API/getConfig.php');
const lastChecked = config.LicenseLastChecked;

// مقایسه با امروز
if (lastCheckedDate === today) {
    // چک نمیشه - از چک قبلی استفاده میشه
    return { valid: true };
}
```

### 2️⃣ اگر امروز چک نشده
```javascript
// دریافت توکن
const token = await fetch('API/getLicenseToken.php');

// بررسی از webhook
const result = await fetch('webhook/LC?LicenseToken=...');

// ذخیره تاریخ چک امروز
await fetch('API/updateLicenseLastChecked.php');
```

---

## 🗄️ جدول Config

| ConfigName | ConfigValue | توضیحات |
|-----------|-------------|---------|
| `LicenseToken` | `abc123...` | توکن لایسنس |
| `LicenseLastChecked` | `2025-10-21 20:18:48` | آخرین زمان بررسی |
| `IsInit` | `YES` | وضعیت راه‌اندازی اولیه |
| `University` | `دانشگاه پیام نور` | نام دانشگاه |
| `SaadCode` | `1234` | کد ساد مرکز |

---

## 📡 API Endpoints

### 1. دریافت تنظیمات
```http
GET /API/getConfig.php
```

**Response:**
```json
{
  "LicenseToken": "abc123...",
  "LicenseLastChecked": "2025-10-21 20:18:48",
  "IsInit": "YES",
  "University": "دانشگاه پیام نور",
  "SaadCode": "1234"
}
```

### 2. دریافت توکن لایسنس
```http
GET /API/getLicenseToken.php
```

**Response:**
```json
{
  "LicenseToken": "abc123..."
}
```

### 3. آپدیت تاریخ چک لایسنس
```http
POST /API/updateLicenseLastChecked.php
Content-Type: application/json

{"update": true}
```

**Response:**
```json
{
  "success": true,
  "timestamp": "2025-10-21 20:18:48"
}
```

---

## ⏰ تایم‌لاین بررسی

### سناریو 1: روز اول
```
00:00 - کاربر لاگین میکنه
  ↓
LicenseLastChecked = null یا تاریخ قدیمی
  ↓
✅ بررسی webhook انجام میشه
  ↓
LicenseLastChecked = 2025-10-21 00:00:15
  ↓
نتیجه: valid = true

08:00 - کاربر دوباره لاگین میکنه
  ↓
LicenseLastChecked = 2025-10-21 00:00:15 (امروز)
  ↓
❌ بررسی webhook انجام نمیشه
  ↓
نتیجه: valid = true (از چک قبلی)

14:00 - auto-refresh
  ↓
LicenseLastChecked = 2025-10-21 00:00:15 (امروز)
  ↓
❌ بررسی webhook انجام نمیشه
  ↓
نتیجه: valid = true (از چک قبلی)
```

### سناریو 2: روز بعد
```
00:00 - تاریخ تغییر کرد
  ↓
01:00 - اولین لاگین کاربر
  ↓
LicenseLastChecked = 2025-10-21 00:00:15 (دیروز)
  ↓
✅ بررسی webhook انجام میشه (چون روز جدیده)
  ↓
LicenseLastChecked = 2025-10-22 01:00:30
  ↓
نتیجه: valid = true یا false (بستگی به webhook)
```

---

## 🎯 مزایای این روش

### 1️⃣ کاهش بار سرور
- **قبلاً:** هر 60 ثانیه → 1440 درخواست در روز
- **الان:** یکبار در روز → 1 درخواست در روز
- **بهبود:** 99.93% کاهش درخواست

### 2️⃣ بهبود سرعت
- کاربران نیازی به انتظار برای چک لایسنس ندارند
- فقط اولین درخواست روز کمی طول میکشه

### 3️⃣ کاهش مصرف bandwidth
- کمترین استفاده از API خارجی
- کاهش هزینه‌های احتمالی

### 4️⃣ پایداری بیشتر
- در صورت قطعی موقت webhook، سیستم کار میکنه
- از آخرین چک معتبر استفاده میشه

---

## 🔍 مقایسه قبل و بعد

| مورد | قبلاً | الان |
|-----|------|------|
| **تعداد چک در روز** | 1440 بار | 1 بار |
| **زمان پاسخ** | 4s در هر refresh | 4s فقط اولین بار |
| **بار webhook** | بالا | خیلی کم |
| **تجربه کاربر** | کند در refresh | سریع |
| **پایداری** | وابسته به webhook | مستقل از webhook |

---

## 🛠️ تست دستی

### تست 1: چک کردن مقدار فعلی
```bash
curl -s http://localhost/API/getConfig.php | grep LicenseLastChecked
```

### تست 2: آپدیت تاریخ
```bash
curl -X POST http://localhost/API/updateLicenseLastChecked.php \
  -H "Content-Type: application/json" \
  -d '{"update":true}'
```

### تست 3: شبیه‌سازی روز جدید
```sql
-- تنظیم تاریخ به دیروز
UPDATE Config 
SET ConfigValue = DATE_SUB(NOW(), INTERVAL 1 DAY)
WHERE ConfigName = 'LicenseLastChecked';

-- اکنون اولین درخواست باید webhook را صدا بزند
```

---

## ⚠️ نکات مهم

1. **تایم‌زون:** سیستم از `Asia/Tehran` استفاده میکنه
2. **مقایسه تاریخ:** فقط تاریخ مقایسه میشه (نه ساعت)
3. **Fail-safe:** در صورت خطا، اجازه دسترسی داده میشه
4. **Caching:** همه درخواست‌ها با `cache: 'no-store'` هستن

---

## 📊 کد مهم

### تابع اصلی بررسی لایسنس
```javascript
async function checkLicense() {
    // 1. دریافت تنظیمات
    const config = await fetch('API/getConfig.php');
    const lastChecked = config.LicenseLastChecked;
    
    // 2. چک کردن امروز
    if (isSameDay(lastChecked, today)) {
        return { valid: true, skipCheck: true };
    }
    
    // 3. بررسی webhook
    const result = await checkWebhook();
    
    // 4. ذخیره تاریخ
    await updateLicenseLastChecked();
    
    return result;
}
```

### API آپدیت تاریخ
```php
// updateLicenseLastChecked.php
date_default_timezone_set('Asia/Tehran');
$timestamp = date('Y-m-d H:i:s');

UPDATE Config 
SET ConfigValue = ? 
WHERE ConfigName = 'LicenseLastChecked'
```

---

## 🔐 امنیت

- ✅ توکن لایسنس در دیتابیس محلی
- ✅ آدرس webhook در کد obfuscated
- ✅ تاریخ چک در سمت سرور ذخیره میشه
- ✅ کاربر نمیتونه تاریخ رو دستکاری کنه (در سمت سرور)

---

**تاریخ بروزرسانی:** 2025-10-21  
**نسخه:** 1.7.2
