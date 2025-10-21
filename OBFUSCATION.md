# Obfuscated JavaScript Files

این پوشه شامل فایل‌های JavaScript است که برای امنیت بیشتر Obfuscate شده‌اند.

## فایل‌ها:

### فایل‌های اصلی (برای ادیت)
- `app.original.js` - نسخه خوانای app.js
- `service-worker.original.js` - نسخه خوانای service-worker.js

### فایل‌های Obfuscate شده (استفاده در production)
- `app.js` - نسخه Obfuscate شده
- `service-worker.js` - نسخه Obfuscate شده

## روش کار:

### 1. برای ادیت کردن:
```bash
# فایل‌های .original.js رو ادیت کن
nano assets/app/app.original.js
nano service-worker.original.js
```

### 2. بعد از ادیت، Obfuscate کن:
```bash
./obfuscate.sh
```

### 3. کامیت و پوش:
```bash
git add .
git commit -m "Update JavaScript files"
git push origin master
```

## نکات مهم:

⚠️ **هیچ‌وقت فایل‌های obfuscate شده (.js) رو مستقیم ادیت نکن!**

✅ همیشه فایل‌های .original.js رو ادیت کن و بعد obfuscate.sh رو اجرا کن.

## تنظیمات Obfuscation:

- ✅ Compact code
- ✅ Control flow flattening (75%)
- ✅ Dead code injection (40%)
- ✅ String array encoding (Base64)
- ✅ Self defending
- ✅ Transform object keys
- ✅ Hexadecimal identifier names
