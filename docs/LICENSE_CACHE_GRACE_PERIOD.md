# سیستم Cache و Grace Period لایسنس

## 📋 فهرست مطالب
- [معرفی](#معرفی)
- [معماری سیستم](#معماری-سیستم)
- [فلوچارت تصمیم‌گیری](#فلوچارت-تصمیم‌گیری)
- [پیاده‌سازی](#پیاده‌سازی)
- [سناریوهای مختلف](#سناریوهای-مختلف)
- [مزایا](#مزایا)
- [API Reference](#api-reference)

---

## معرفی

سیستم **Cache و Grace Period** یک لایه امنیتی و قابلیت اطمینان برای سیستم لایسنس است که:

✅ **مشکل قطعی اینترنت را حل می‌کند**  
✅ **تجربه کاربری بهتری ارائه می‌دهد**  
✅ **بار سرور را کاهش می‌دهد**  
✅ **در عین حال امنیت را حفظ می‌کند**

### 🎯 اهداف

1. **Reliability**: اگر سرور لایسنس در دسترس نباشد، سیستم همچنان کار کند
2. **User Experience**: کاربر نباید با خطاهای لایسنس مواجه شود
3. **Security**: در عین حال، لایسنس منقضی شده نباید قابل استفاده باشد
4. **Performance**: کاهش درخواست‌های غیرضروری به سرور

---

## معماری سیستم

### 🗂️ ساختار Cache

در جدول `Config` فیلدهای زیر ذخیره می‌شوند:

| فیلد | توضیح | مثال |
|-----|-------|------|
| `LicenseLastChecked` | آخرین زمان **هرگونه** بررسی لایسنس | `2025-10-22 14:30:00` |
| `LicenseLastSuccessCheck` | آخرین زمان بررسی **موفق** لایسنس | `2025-10-22 10:00:00` |
| `LicenseLastStatus` | وضعیت آخرین بررسی | `valid` / `invalid` / `error` |
| `LicenseExpiry` | تاریخ انقضای لایسنس (اگر trial باشد) | `2025-12-31 23:59:59` |

### ⏰ Grace Period

**Grace Period** یک دوره ۲۴ ساعته است که:

- از زمان آخرین بررسی **موفق** محاسبه می‌شود
- اگر سرور لایسنس در دسترس نباشد، تا ۲۴ ساعت دسترسی ادامه دارد
- بعد از ۲۴ ساعت، سیستم به cache تاریخ انقضا متکی می‌شود

---

## فلوچارت تصمیم‌گیری

```
┌─────────────────────────────────┐
│  شروع بررسی لایسنس             │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│ آیا زمان بررسی رسیده؟          │◄──── بررسی فرکانس (روزانه/ساعتی/دقیقه‌ای)
└──────────┬──────────────────────┘
           │ بله
           ▼
┌─────────────────────────────────┐
│ دریافت cache لایسنس            │
│ (lastSuccessCheck, expiry, etc) │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│ فراخوانی webhook سرور لایسنس   │
└──────────┬──────────────────────┘
           │
           ├──── موفق ────┐
           │              ▼
           │    ┌─────────────────────┐
           │    │ لایسنس معتبر است؟  │
           │    └─────────┬───────────┘
           │              │
           │              ├── بله ─────► ذخیره success + ✅ دسترسی مجاز
           │              │
           │              └── خیر ─────► ذخیره invalid + ❌ دسترسی رد
           │
           └──── خطا/Timeout ────┐
                                  ▼
                       ┌──────────────────────────┐
                       │ Grace Period فعال است؟   │
                       │ (lastSuccess < 24h ago)  │
                       └──────────┬───────────────┘
                                  │
                                  ├── بله ────► ⚡ دسترسی موقت (Grace Period)
                                  │
                                  ▼
                       ┌──────────────────────────┐
                       │ cache expiry معتبر است؟ │
                       └──────────┬───────────────┘
                                  │
                                  ├── بله ────► ⚡ دسترسی موقت (از cache)
                                  │
                                  └── خیر ────► ⚠️ Fallback (دسترسی موقت محدود)
```

---

## پیاده‌سازی

### 1️⃣ API Endpoints

#### `API/getLicenseCache.php`
دریافت اطلاعات cache شده لایسنس

**Response:**
```json
{
  "success": true,
  "cache": {
    "lastStatus": "valid",
    "lastSuccessCheck": "2025-10-22 10:00:00",
    "lastChecked": "2025-10-22 14:30:00",
    "expiry": "2025-12-31 23:59:59"
  }
}
```

#### `API/updateLicenseStatus.php`
ذخیره وضعیت بررسی لایسنس

**Request:**
```json
{
  "status": "valid",  // "valid" | "invalid" | "error"
  "expiry": "2025-12-31 23:59:59"  // اختیاری
}
```

### 2️⃣ تابع `checkLicense()`

```javascript
async function checkLicense() {
    // 1. دریافت cache
    const cacheResponse = await fetch('API/getLicenseCache.php');
    const licenseCache = await cacheResponse.json();
    
    // 2. بررسی فرکانس
    if (shouldSkipCheck(licenseCache)) {
        return { valid: true, skipCheck: true };
    }
    
    // 3. فراخوانی webhook
    try {
        const result = await callLicenseWebhook();
        if (result.valid) {
            await updateLicenseStatus('valid', result.expiry);
            return result;
        }
    } catch (error) {
        // 4. مدیریت خطا با Grace Period
        return await handleLicenseServerError(licenseCache);
    }
}
```

### 3️⃣ تابع `handleLicenseServerError()`

```javascript
async function handleLicenseServerError(cache) {
    const now = new Date();
    const GRACE_PERIOD_MS = 24 * 60 * 60 * 1000;
    
    // ثبت وضعیت خطا
    await updateLicenseStatus('error', cache.expiry);
    
    // ✅ بررسی Grace Period
    if (cache.lastSuccessCheck) {
        const timeSinceSuccess = now - new Date(cache.lastSuccessCheck);
        if (timeSinceSuccess < GRACE_PERIOD_MS) {
            return { valid: true, gracePeriod: true };
        }
    }
    
    // ✅ بررسی cache expiry
    if (cache.expiry) {
        if (new Date(cache.expiry) > now) {
            return { valid: true, usingCache: true };
        }
    }
    
    // ⚠️ Fallback
    return { valid: true, fallback: true };
}
```

---

## سناریوهای مختلف

### 📊 جدول سناریوها

| سناریو | lastSuccessCheck | Expiry Cache | سرور در دسترس؟ | نتیجه |
|--------|-----------------|--------------|----------------|-------|
| **عادی** | 2h ago | 30 days | ✅ بله | ✅ بررسی عادی، ذخیره success |
| **قطعی موقت** | 2h ago | 30 days | ❌ خیر | ⚡ Grace Period → دسترسی مجاز |
| **قطعی طولانی** | 30h ago | 20 days | ❌ خیر | ⚡ استفاده از cache → دسترسی مجاز |
| **انقضا + قطعی** | 30h ago | expired | ❌ خیر | ❌ دسترسی رد شده |
| **نصب اولیه** | null | null | ❌ خیر | ⚠️ Fallback → دسترسی موقت |

### 🔍 توضیح هر سناریو

#### 1. حالت عادی
```
✅ سرور لایسنس در دسترس
→ بررسی عادی انجام می‌شود
→ وضعیت ذخیره می‌شود
```

#### 2. قطعی موقت اینترنت (< 24 ساعت)
```
⚡ آخرین بررسی موفق: 2 ساعت پیش
❌ سرور در دسترس نیست
→ Grace Period فعال است
→ دسترسی مجاز (باقیمانده: 22 ساعت)
```

#### 3. قطعی طولانی اینترنت (> 24 ساعت)
```
⚠️ آخرین بررسی موفق: 30 ساعت پیش
❌ Grace Period منقضی شده
✅ اما expiry cache: 20 روز دیگر
→ استفاده از cache
→ دسترسی مجاز
```

#### 4. لایسنس منقضی + قطعی اینترنت
```
❌ آخرین بررسی موفق: 30 ساعت پیش
❌ Grace Period منقضی شده
❌ Expiry cache هم منقضی شده
→ دسترسی رد می‌شود
→ پیام: "لایسنس منقضی شده - لطفاً به اینترنت متصل شوید"
```

#### 5. نصب اولیه بدون اینترنت
```
⚠️ هیچ cache ای وجود ندارد
❌ سرور در دسترس نیست
→ Fallback: دسترسی موقت محدود
→ پیام: "خطا در بررسی لایسنس (دسترسی موقت)"
```

---

## مزایا

### 1. 🛡️ Reliability بالا
- سیستم در صورت قطعی اینترنت همچنان کار می‌کند
- تا 24 ساعت بدون دسترسی به سرور، کاربر مشکلی ندارد

### 2. 👥 تجربه کاربری بهتر
- کاربر با خطاهای لایسنس کمتری مواجه می‌شود
- در صورت قطعی موقت، سیستم شفاف کار می‌کند

### 3. ⚡ کاهش بار سرور
- بررسی‌های غیرضروری انجام نمی‌شود
- Cache مدیریت شده دارای TTL هوشمند است

### 4. 🔐 امنیت حفظ شده
- لایسنس منقضی شده قابل استفاده نیست
- Grace Period محدود به 24 ساعت است
- تمام رویدادها لاگ می‌شوند

---

## API Reference

### `updateLicenseStatus(status, expiry)`

**پارامترها:**
- `status` (string): `'valid'` | `'invalid'` | `'error'`
- `expiry` (string|null): تاریخ انقضا به فرمت `YYYY-MM-DD HH:mm:ss`

**عملکرد:**
- اگر `status === 'valid'`: ذخیره `LicenseLastSuccessCheck` و `LicenseExpiry`
- همیشه ذخیره `LicenseLastStatus`

**مثال:**
```javascript
await updateLicenseStatus('valid', '2025-12-31 23:59:59');
await updateLicenseStatus('error', null);
```

### `handleLicenseServerError(cache)`

**پارامترها:**
- `cache`: شیء حاوی `lastSuccessCheck`, `expiry`, `lastStatus`

**بازگشت:**
```javascript
{
  valid: boolean,
  message: string,
  gracePeriod?: boolean,   // اگر از Grace Period استفاده شد
  usingCache?: boolean,    // اگر از cache استفاده شد
  fallback?: boolean       // اگر fallback فعال شد
}
```

---

## لاگ‌ها و Debugging

### کنسول لاگ‌ها

| پیام | معنی |
|-----|------|
| `[License] ✓ Checked <1min ago` | Skip شد - اخیراً چک شده |
| `[License] ✓ Permanent license active` | لایسنس دائمی فعال |
| `[License] ✓ Trial license active (48h)` | لایسنس آزمایشی - 48 ساعت مانده |
| `[License] ⚠ Could not fetch token` | خطا در دریافت توکن |
| `[License] ⚡ Grace Period active (22h)` | Grace Period - 22 ساعت مانده |
| `[License] ⚡ Using cached expiry` | استفاده از cache |
| `[License] ⚡ Fallback: Allowing access` | Fallback فعال |
| `[License] ✗ License expired` | لایسنس منقضی شده |

### بررسی Cache در Console

```javascript
// دریافت cache
fetch('API/getLicenseCache.php')
  .then(r => r.json())
  .then(console.log);

// فورس بررسی لایسنس
checkLicense().then(console.log);
```

---

## نکات مهم ⚠️

1. **Grace Period فقط برای خطاهای سرور است**
   - اگر لایسنس invalid باشد، Grace Period کمکی نمی‌کند

2. **Cache تاریخ انقضا حیاتی است**
   - اگر expiry ذخیره نشود، بعد از 24 ساعت به fallback می‌رسیم

3. **Fallback باید محدود باشد**
   - در نسخه‌های بعدی می‌توان محدودیت‌هایی برای fallback اعمال کرد

4. **لاگ‌ها را نگه دارید**
   - برای debugging مشکلات لایسنس، لاگ‌ها ضروری هستند

---

## نسخه

**نسخه:** 1.7.6  
**تاریخ:** 1404/08/01  
**نویسنده:** تیم توسعه نسار
