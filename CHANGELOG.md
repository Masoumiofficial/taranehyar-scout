# Changelog - Taranehyar Scout

## 1.0.0 - 2026-10-16

### Added
- انتشار اولیه ترانه‌یار اسکات
- موتور کشف چندمنبعی با ProviderInterface
- ارائه‌دهنده MyBia2Music با قابلیت جستجوی هنرمند و دریافت جزئیات
- ارائه‌دهنده Mock برای تست
- استانداردسازی متادیتا (Normalizer) با تشخیص نوع اثر (single, album, remix, live, collab)
- تطبیق هوشمند چندمرحله‌ای با آرشیو سایت (Matcher)
  - تطبیق شناسه خارجی
  - تطبیق نام نرمال‌شده هنرمند و عنوان
  - بررسی تفاوت نسخه، ریمیکس، همکاری
  - امتیاز شباهت توضیح‌پذیر
- پنل مدیریت فارسی RTL
  - داشبورد با آمار
  - فرم جستجوی هنرمند
  - صفحه نتایج با فیلتر، مرتب‌سازی، صفحه‌بندی، عملیات گروهی
  - پیش‌نمایش انتشار
  - وضعیت انتقال‌ها (صف)
  - تنظیمات ارائه‌دهندگان و سیاست مجوز
  - صفحه درباره
- خط لوله انتشار مرحله‌ای با StateMachine
  - اعتبارسنجی، بررسی مجوز، بررسی تکراری، پیش‌نمایش، تأیید، آماده‌سازی متادیتا، انتقال فایل، ایجاد پست
  - checkpoint و قابلیت بازیابی
  - جلوگیری از ایجاد تکراری
- یکپارچگی با Taranehyar
  - Adapter برای خواندن تنظیمات، متاکی‌ها، تاکسونومی
  - استفاده از TaranehYar_Http و TaranehYar_Archive در صورت وجود
  - عدم Fatal در صورت نبود وابستگی
- یکپارچگی با HostDL
  - Adapter برای انتقال فایل
  - اعتبارسنجی فایل (پسوند، MIME، حجم)
  - استفاده از API رسمی ترانه‌یار در صورت وجود
- مدیریت مجوز و حقوق استفاده
  - وضعیت‌های: verified, needs_review, metadata_only, file_allowed_not_verified, forbidden
  - سیاست برای هر provider
  - جلوگیری از انتقال تا تأیید مجوز
- صف پس‌زمینه با WP-Cron
  - جدول jobs با وضعیت، پیشرفت، تلاش مجدد، exponential backoff
  - Jobهای: discovery_search, discovery_match, publish_track, transfer_file
  - مدیریت cron: every_five_minutes, daily cleanup
- امنیت
  - Capability: manage_options, publish_posts, edit_posts
  - Nonce برای همه فرم‌ها
  - Sanitizer و UrlValidator ضد SSRF (بررسی IP خصوصی، DNS rebinding، دامنه‌های داخلی)
  - ضد XSS, CSRF, Path Traversal, SQL Injection
- لاگ و گزارش
  - جدول logs با level, message, context, job_id, discovery_id
  - Logger با debug, info, warning, error
- جداول دیتابیس
  - discoveries با provider_hash یکتا
  - jobs با job_id یکتا
  - logs
- دارایی‌ها
  - CSS مدرن RTL با گرادیانت
  - JS برای AJAX و تعامل
- مستندات
  - AUDIT_REPORT.md: گزارش ممیزی ترانه‌یار 1.0.5
  - ARCHITECTURE.md: معماری اسکات
  - PROVIDER_GUIDE.md: راهنمای افزودن provider
  - INSTALL-fa.md: راهنمای نصب فارسی
- برند
  - Author: اتحاد وردپرس
  - Author URI: https://etehadwp.com/
  - شعار: کشف کن، تطبیق بده، هوشمندانه منتشر کن.

### Security
- SSRF protection در UrlValidator
- Path Traversal protection در Sanitizer
- Capability checks در همه عملیات
- Nonce verification

### Technical
- Requires at least: WordPress 5.8, PHP 7.2
- Tested up to: WordPress 6.7
- License: GPL-2.0-or-later
- Text Domain: taranehyar-scout

---

## 1.0.5 Reference - Taranehyar Main Plugin

برای اطلاعات ترانه‌یار اصلی، به CHANGELOG.md در مخزن https://github.com/Masoumiofficial/taranehyar مراجعه کنید.
