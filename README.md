# Taranehyar Scout | ترانه‌یار اسکات

**کشف، تطبیق و مدیریت هوشمند آثار موسیقی در وردپرس**

- **نام فارسی:** ترانه‌یار اسکات
- **نام انگلیسی:** Taranehyar Scout
- **نویسنده:** اتحاد وردپرس | EtehadWP
- **وب‌سایت:** https://etehadwp.com/
- **شعار:** کشف کن، تطبیق بده، هوشمندانه منتشر کن.
- **نسخه:** 1.0.0
- **مجوز:** GPL-2.0-or-later

## معرفی

ترانه‌یار اسکات یک افزونه مستقل وردپرس است که به عنوان مکمل افزونه‌های اصلی Taranehyar و Taranehyar HostDL عمل می‌کند. این ابزار به مدیر سایت امکان می‌دهد نام انگلیسی یک خواننده را وارد کند، آثار او را از منابع مجاز جمع‌آوری کند، با آرشیو سایت تطبیق دهد و پس از بررسی، آثار مجاز را منتشر کند.

## ویژگی‌ها

- 🔍 موتور کشف چندمنبعی با providerهای مستقل
- 🎵 پشتیبانی از MyBia2Music و Mock Provider
- 📊 استانداردسازی متادیتا و تشخیص نوع اثر
- 🎯 تطبیق هوشمند چندمرحله‌ای با آرشیو
- 🇮🇷 پنل مدیریت فارسی RTL
- 🚀 خط لوله انتشار مرحله‌ای و قابل‌ردیابی
- 🔌 یکپارچگی با Taranehyar و HostDL
- ⚖️ مدیریت مجوز و حقوق استفاده
- 📡 صف پس‌زمینه با WP-Cron
- 🔒 امنیت کامل (SSRF, XSS, CSRF, Path Traversal)
- 📝 لاگ و گزارش عملیات

## نصب

1. فایل `taranehyar-scout.zip` را دانلود کنید
2. از پیشخوان وردپرس: افزونه‌ها > افزودن > بارگذاری افزونه
3. فعال‌سازی
4. به اسکات > تنظیمات بروید و پیکربندی کنید

راهنمای کامل: `docs/INSTALL-fa.md`

## ساختار

```
taranehyar-scout/
├── taranehyar-scout.php      # فایل اصلی با هدر برند
├── includes/
│   ├── Core/                 # هسته، فعال‌سازی، لودر
│   ├── Security/             # امنیت
│   ├── Logging/              # لاگ
│   ├── Providers/            # ارائه‌دهندگان
│   ├── Metadata/             # استانداردسازی
│   ├── Catalog/              # آرشیو و آداپتور ترانه‌یار
│   ├── Matching/             # تطبیق
│   ├── Publishing/           # انتشار
│   ├── HostDL/               # انتقال فایل
│   ├── Jobs/                 # صف
│   └── Admin/                # پنل مدیریت
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── docs/
│   ├── AUDIT_REPORT.md
│   ├── ARCHITECTURE.md
│   ├── PROVIDER_GUIDE.md
│   └── INSTALL-fa.md
└── languages/
```

## توسعه

### افزودن Provider جدید

مستندات کامل در `docs/PROVIDER_GUIDE.md`

```php
class MyProvider implements ProviderInterface {
    public function get_id() { return 'myprovider'; }
    public function search_artist($artist_en, $options = []) { /* ... */ }
    // ...
}

add_filter('tscout_providers', function($providers) {
    $providers[] = new MyProvider();
    return $providers;
});
```

### تست

Mock Provider را در تنظیمات فعال کنید و جستجو کنید.

## مستندات

- [گزارش ممیزی ترانه‌یار](docs/AUDIT_REPORT.md)
- [معماری اسکات](docs/ARCHITECTURE.md)
- [راهنمای Provider](docs/PROVIDER_GUIDE.md)
- [راهنمای نصب فارسی](docs/INSTALL-fa.md)

## امنیت

- هیچ محدودیتی دور زده نمی‌شود (CAPTCHA, paywall, احراز هویت)
- فایل‌ها تا تأیید مجوز منتقل نمی‌شوند
- SSRF, XSS, CSRF, Path Traversal محافظت شده
- Capability و Nonce در همه عملیات

## مجوز

GPL-2.0-or-later

توسعه داده شده توسط **اتحاد وردپرس** - https://etehadwp.com/

نام و برند افزونه‌های اصلی Taranehyar و Taranehyar HostDL تغییر نکرده است.

## تغییرات

به `CHANGELOG.md` مراجعه کنید.

## پشتیبانی

وب‌سایت: https://etehadwp.com/
