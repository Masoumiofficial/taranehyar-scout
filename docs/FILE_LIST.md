# فهرست فایل‌های Taranehyar Scout 1.0.0

## فایل‌های اصلی
- `taranehyar-scout.php` - فایل اصلی افزونه با هدر برند اتحاد وردپرس
- `readme.txt` - توضیحات وردپرس.org
- `uninstall.php` - پاک‌سازی هنگام حذف
- `README.md` - معرفی پروژه
- `CHANGELOG.md` - لیست تغییرات

## هسته (Core)
- `includes/Core/Constants.php` - ثابت‌ها، وضعیت‌ها، آستانه‌ها
- `includes/Core/Activator.php` - ایجاد جداول، تنظیمات پیش‌فرض، cron
- `includes/Core/Deactivator.php` - پاک‌سازی cron
- `includes/Core/Loader.php` - مدیریت هوک‌ها
- `includes/Core/Plugin.php` - بوت‌استرپ، منوها، AJAX، cron

## امنیت (Security)
- `includes/Security/Capability.php` - بررسی دسترسی (manage_options, publish_posts, edit_posts) و nonce
- `includes/Security/Sanitizer.php` - پاک‌سازی ورودی و نرمال‌سازی
- `includes/Security/UrlValidator.php` - ضد SSRF با بررسی IP خصوصی، DNS rebinding

## لاگ (Logging)
- `includes/Logging/Logger.php` - لاگ با سطوح debug, info, warning, error

## ارائه‌دهندگان (Providers)
- `includes/Providers/ProviderInterface.php` - اینترفیس
- `includes/Providers/ProviderRegistry.php` - رجیستری و مدیریت providerها
- `includes/Providers/MyBia2MusicProvider.php` - ارائه‌دهنده واقعی MyBia2Music با جستجو و parse HTML
- `includes/Providers/MockProvider.php` - ارائه‌دهنده آزمایشی

## متادیتا (Metadata)
- `includes/Metadata/Normalizer.php` - استانداردسازی اطلاعات، تشخیص نوع، محاسبه اطمینان

## کاتالوگ (Catalog)
- `includes/Catalog/TaranehyarAdapter.php` - آداپتور ترانه‌یار برای خواندن تنظیمات، متاکی‌ها، ایجاد پست
- `includes/Catalog/ArchiveScanner.php` - اسکن آرشیو با $wpdb و محاسبه شباهت

## تطبیق (Matching)
- `includes/Matching/Matcher.php` - تطبیق چندمرحله‌ای با امتیاز شباهت و وضعیت‌ها

## انتشار (Publishing)
- `includes/Publishing/StateMachine.php` - ماشین وضعیت 14 مرحله‌ای
- `includes/Publishing/Publisher.php` - پیش‌نمایش و انتشار با checkpoint

## هاست دانلود (HostDL)
- `includes/HostDL/HostDLAdapter.php` - انتقال فایل با API رسمی ترانه‌یار و اعتبارسنجی

## صف (Jobs)
- `includes/Jobs/JobQueue.php` - صف با job_id یکتا، پیشرفت، retry با exponential backoff، WP-Cron

## ادمین (Admin)
- `includes/Admin/AdminPages.php` - ثبت منوها، صفحات، تنظیمات
- `includes/Admin/AjaxHandlers.php` - هندلرهای AJAX برای جستجو، تطبیق، انتشار، عملیات گروهی
- `includes/Admin/views/dashboard.php` - داشبورد با آمار
- `includes/Admin/views/discovery.php` - فرم جستجوی هنرمند
- `includes/Admin/views/results.php` - نتایج با فیلتر و عملیات
- `includes/Admin/views/transfers.php` - وضعیت صف
- `includes/Admin/views/settings.php` - تنظیمات ارائه‌دهندگان و سیاست مجوز
- `includes/Admin/views/about.php` - درباره افزونه

## دارایی‌ها (Assets)
- `assets/css/admin.css` - استایل RTL مدرن با گرادیانت
- `assets/js/admin.js` - تعاملات پایه

## مستندات (Docs)
- `docs/AUDIT_REPORT.md` - گزارش ممیزی Taranehyar 1.0.5 (A-G)
- `docs/ARCHITECTURE.md` - معماری اسکات
- `docs/PROVIDER_GUIDE.md` - راهنمای افزودن provider
- `docs/INSTALL-fa.md` - راهنمای نصب فارسی
- `docs/SECURITY.md` - گزارش امنیت
- `docs/FINAL_REPORT.md` - گزارش نهایی
- `docs/FILE_LIST.md` - این فایل

## تست‌ها (Tests)
- `tests/test-sanitizer.php` - تست Sanitizer
- `tests/test-matcher.php` - تست Normalizer

## توزیع (Dist)
- `dist/taranehyar-scout.zip` - بسته قابل نصب

## زبان‌ها (Languages)
- `languages/` - پوشه ترجمه‌ها (آماده برای .po/.mo)

## تعداد کل
- 60 فایل در ZIP
- ~35 فایل سورس PHP
- 6 فایل مستندات
- 2 فایل دارایی
