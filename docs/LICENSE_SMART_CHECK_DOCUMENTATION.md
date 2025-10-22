# مستندات سیستم هوشمند بررسی لایسنس

## 📋 خلاصه
سیستم لایسنسینگ به گونه‌ای طراحی شده که بر اساس **نزدیکی به تاریخ انقضا** به صورت هوشمند فرکانس بررسی را تنظیم می‌کند.

---

## 🎯 منطق فرکانس چک

### 📊 جدول فرکانس بر اساس زمان باقیمانده

| وضعیت لایسنس | زمان باقیمانده | فرکانس چک | دلیل |
|--------------|----------------|-----------|------|
| **لایسنس دائمی** | نامحدود | **یکبار در روز** | بار کم روی سرور |
| **بیش از 1 روز** | > 24 ساعت | **یکبار در روز** | زمان کافی وجود دارد |
| **در روز انقضا** | 1-24 ساعت | **هر 1 ساعت** | نیاز به پایش دقیق‌تر |
| **در ساعت انقضا** | < 1 ساعت | **هر 1 دقیقه** | وضعیت حساس |

---

## 🔄 نحوه عملکرد

### مرحله 1: دریافت اطلاعات
```javascript
const config = await fetch('API/getConfig.php');
const lastChecked = config.LicenseLastChecked;  // آخرین زمان چک
const licenseExpiry = config.LicenseExpiry;     // تاریخ انقضا
```

### مرحله 2: محاسبه زمان‌ها
```javascript
const now = new Date();
const timeSinceLastCheck = now - lastCheckedDate;
const timeUntilExpiry = expiryDate - now;
```

### مرحله 3: تصمیم‌گیری هوشمند
```javascript
// سناریو 1: کمتر از 1 ساعت تا انقضا
if (timeUntilExpiry <= 1 hour) {
    if (timeSinceLastCheck < 1 minute) {
        return skipCheck;  // چک نمیشه
    } else {
        checkLicense();    // چک میشه
    }
}

// سناریو 2: کمتر از 24 ساعت تا انقضا
else if (timeUntilExpiry <= 24 hours) {
    if (timeSinceLastCheck < 1 hour) {
        return skipCheck;  // چک نمیشه
    } else {
        checkLicense();    // چک میشه
    }
}

// سناریو 3: بیشتر از 1 روز تا انقضا
else {
    if (sameDay(lastChecked, today)) {
        return skipCheck;  // چک نمیشه
    } else {
        checkLicense();    // چک میشه
    }
}
```

---

## 📈 سناریوهای عملیاتی

### سناریو 1: لایسنس دائمی (Licenced)
```
روز 1:
  00:05 → لاگین
        → چک webhook ✅
        → LicenseExpiry = null
        → نتیجه: valid
  
  08:00 → لاگین
        → skipCheck (همان روز)
        
  14:00 → refresh
        → skipCheck (همان روز)

روز 2:
  01:00 → اولین درخواست
        → چک webhook ✅ (روز جدید)
        → نتیجه: valid
```

### سناریو 2: لایسنس آزمایشی - 3 روز مانده
```
روز 1 (سه‌شنبه - 3 روز مانده):
  09:00 → لاگین
        → چک webhook ✅
        → LicenseExpiry = "2025-10-24 23:59:59"
        → timeUntilExpiry = 72 ساعت
        → فرکانس: روزانه
        → نتیجه: valid
  
  15:00 → refresh
        → skipCheck (همان روز)
        → نتیجه: valid

روز 2 (چهارشنبه - 2 روز مانده):
  10:00 → لاگین
        → چک webhook ✅ (روز جدید)
        → timeUntilExpiry = 48 ساعت
        → فرکانس: روزانه
        → نتیجه: valid
```

### سناریو 3: لایسنس آزمایشی - روز انقضا (کمتر از 24 ساعت)
```
روز انقضا (جمعه - 10 ساعت مانده):
  08:00 → لاگین
        → چک webhook ✅
        → LicenseExpiry = "2025-10-24 18:00:00"
        → timeUntilExpiry = 10 ساعت
        → فرکانس: ساعتی
        → نتیجه: valid
  
  09:00 → refresh (بعد از 1 ساعت)
        → چک webhook ✅
        → timeUntilExpiry = 9 ساعت
        → نتیجه: valid
  
  09:30 → refresh (بعد از 30 دقیقه)
        → skipCheck (کمتر از 1 ساعت)
        → نتیجه: valid
  
  10:00 → refresh (بعد از 1 ساعت)
        → چک webhook ✅
        → timeUntilExpiry = 8 ساعت
        → نتیجه: valid
```

### سناریو 4: لایسنس آزمایشی - ساعت انقضا (کمتر از 1 ساعت)
```
ساعت انقضا (17:30 - 30 دقیقه مانده):
  17:30 → refresh
        → چک webhook ✅
        → LicenseExpiry = "2025-10-24 18:00:00"
        → timeUntilExpiry = 30 دقیقه
        → فرکانس: دقیقه‌ای
        → نتیجه: valid
  
  17:31 → refresh (بعد از 1 دقیقه)
        → چک webhook ✅
        → timeUntilExpiry = 29 دقیقه
        → نتیجه: valid
  
  17:31:30 → refresh (بعد از 30 ثانیه)
        → skipCheck (کمتر از 1 دقیقه)
        → نتیجه: valid
  
  17:32 → refresh (بعد از 1 دقیقه)
        → چک webhook ✅
        → timeUntilExpiry = 28 دقیقه
        → نتیجه: valid
  
  18:00 → refresh
        → چک webhook ✅
        → timeUntilExpiry = 0 (منقضی شده)
        → نتیجه: INVALID ❌
        → Alert مسدودکننده
```

---

## 🗄️ ساختار Config در دیتابیس

| ConfigName | ConfigValue | توضیحات |
|-----------|-------------|---------|
| `LicenseToken` | `abc123xyz...` | توکن لایسنس دریافتی از webhook |
| `LicenseLastChecked` | `2025-10-24 17:32:15` | آخرین زمان بررسی لایسنس |
| `LicenseExpiry` | `2025-10-24 18:00:00` | تاریخ انقضای لایسنس (null برای دائمی) |
| `IsInit` | `YES` | وضعیت راه‌اندازی اولیه |
| `University` | `دانشگاه پیام نور مرکز بیجار` | نام دانشگاه |
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
  "LicenseLastChecked": "2025-10-24 17:32:15",
  "LicenseExpiry": "2025-10-24 18:00:00",
  "IsInit": "YES",
  "University": "دانشگاه پیام نور مرکز بیجار",
  "SaadCode": "1234"
}
```

### 2. آپدیت تاریخ چک لایسنس
```http
POST /API/updateLicenseLastChecked.php
Content-Type: application/json

{
  "update": true,
  "expiry": "2025-10-24 18:00:00"  // یا null برای دائمی
}
```

**Response:**
```json
{
  "success": true,
  "timestamp": "2025-10-24 17:32:15",
  "expiry": "2025-10-24 18:00:00"
}
```

---

## 📊 نمودار تصمیم‌گیری

```
درخواست جدید
      ↓
دریافت Config
      ↓
آیا LicenseExpiry وجود دارد؟
      ↓
    ┌───┴───┐
    │  بله  │  خیر
    ↓       ↓
محاسبه    لایسنس
زمان      دائمی
باقیمانده    ↓
    ↓     چک روزانه
    │
    ├→ < 1 ساعت    → چک دقیقه‌ای (هر 1 دقیقه)
    ├→ < 24 ساعت   → چک ساعتی (هر 1 ساعت)
    └→ > 24 ساعت   → چک روزانه (یکبار در روز)
```

---

## 💡 مزایای این روش

### 1️⃣ کارایی بهینه
- **لایسنس دائمی:** حداقل بار (1 درخواست/روز)
- **لایسنس معمولی:** بار متعادل (1 درخواست/روز)
- **نزدیک انقضا:** دقت بالا (60 درخواست/ساعت)

### 2️⃣ واکنش سریع
- در لحظات حساس (نزدیک انقضا) واکنش سریع
- احتمال قطع خدمات غیرمنتظره کاهش می‌یابد

### 3️⃣ تجربه کاربری
- کاربران عادی: سرعت بالا (بدون تاخیر)
- کاربران در معرض انقضا: اطمینان از بررسی دقیق

### 4️⃣ کاهش بار سرور
```
مقایسه با روش قدیمی (هر 60 ثانیه):

لایسنس دائمی:
  قبلاً: 1440 درخواست/روز
  الان: 1 درخواست/روز
  بهبود: 99.93% ↓

لایسنس معمولی (30 روز):
  قبلاً: 43,200 درخواست/ماه
  الان: 30 درخواست/ماه (29 روز) + 24 درخواست (روز آخر)
  بهبود: 99.87% ↓

روز آخر (24 ساعت):
  قبلاً: 1440 درخواست
  الان: 24 درخواست (ساعتی) + 60 درخواست (ساعت آخر)
  بهبود: 94.2% ↓

ساعت آخر:
  قبلاً: 60 درخواست
  الان: 60 درخواست
  بهبود: 0% (همان تعداد برای دقت بالا)
```

---

## 🧪 تست‌های مختلف

### تست 1: شبیه‌سازی لایسنس دائمی
```sql
-- تنظیم تاریخ چک به دیروز
UPDATE Config 
SET ConfigValue = DATE_SUB(NOW(), INTERVAL 1 DAY)
WHERE ConfigName = 'LicenseLastChecked';

-- حذف تاریخ انقضا
DELETE FROM Config WHERE ConfigName = 'LicenseExpiry';

-- نتیجه: باید روزانه چک کنه
```

### تست 2: شبیه‌سازی 3 روز مانده
```sql
-- تنظیم تاریخ انقضا به 3 روز بعد
UPDATE Config 
SET ConfigValue = DATE_ADD(NOW(), INTERVAL 3 DAY)
WHERE ConfigName = 'LicenseExpiry';

-- تنظیم چک به دیروز
UPDATE Config 
SET ConfigValue = DATE_SUB(NOW(), INTERVAL 1 DAY)
WHERE ConfigName = 'LicenseLastChecked';

-- نتیجه: باید روزانه چک کنه
```

### تست 3: شبیه‌سازی 10 ساعت مانده
```sql
-- تنظیم تاریخ انقضا به 10 ساعت بعد
UPDATE Config 
SET ConfigValue = DATE_ADD(NOW(), INTERVAL 10 HOUR)
WHERE ConfigName = 'LicenseExpiry';

-- تنظیم چک به 2 ساعت پیش
UPDATE Config 
SET ConfigValue = DATE_SUB(NOW(), INTERVAL 2 HOUR)
WHERE ConfigName = 'LicenseLastChecked';

-- نتیجه: باید ساعتی چک کنه
```

### تست 4: شبیه‌سازی 30 دقیقه مانده
```sql
-- تنظیم تاریخ انقضا به 30 دقیقه بعد
UPDATE Config 
SET ConfigValue = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
WHERE ConfigName = 'LicenseExpiry';

-- تنظیم چک به 2 دقیقه پیش
UPDATE Config 
SET ConfigValue = DATE_SUB(NOW(), INTERVAL 2 MINUTE)
WHERE ConfigName = 'LicenseLastChecked';

-- نتیجه: باید دقیقه‌ای چک کنه
```

---

## 🔍 Debug و Monitoring

### لاگ‌های مهم در Console
```javascript
// فرکانس روزانه
"License already checked today"

// فرکانس ساعتی
"License checked less than an hour ago (expiring today)"

// فرکانس دقیقه‌ای
"License checked less than a minute ago (expiring soon)"

// بعد از چک موفق
"License valid for X more hours"
```

### بررسی وضعیت فعلی
```bash
# چک کردن تاریخ‌ها
curl -s http://localhost/API/getConfig.php | \
  grep -E "(LicenseLastChecked|LicenseExpiry)"

# محاسبه زمان باقیمانده
mysql -u root -p -e "
SELECT 
  ConfigValue as Expiry,
  TIMESTAMPDIFF(HOUR, NOW(), ConfigValue) as HoursLeft,
  TIMESTAMPDIFF(MINUTE, NOW(), ConfigValue) as MinutesLeft
FROM PnuExamsSeatNumber.Config 
WHERE ConfigName = 'LicenseExpiry';"
```

---

## ⚠️ نکات مهم

1. **تایم‌زون**: همه محاسبات با `Asia/Tehran`
2. **دقت زمانی**: از milliseconds استفاده می‌شود
3. **Fail-safe**: در صورت خطا اجازه دسترسی داده می‌شود
4. **لایسنس دائمی**: `LicenseExpiry = null` یا حذف شده
5. **لایسنس آزمایشی**: `LicenseExpiry` مقدار دارد

---

## 🎯 بهترین عملکرد

| سناریو | درخواست در روز | نسبت به قدیم |
|--------|----------------|-------------|
| لایسنس دائمی | 1 | 0.07% |
| 30 روز عادی | 1×30 = 30 | 2.08% |
| روز انقضا | 24 | 1.67% |
| ساعت انقضا | 60 | 4.17% |
| **میانگین کل** | **≈ 33/روز** | **≈ 2.3%** |

**نتیجه:** در مجموع **97.7% کاهش بار** نسبت به روش قدیمی! 🎉

---

**تاریخ بروزرسانی:** 2025-10-21  
**نسخه:** 1.7.3
