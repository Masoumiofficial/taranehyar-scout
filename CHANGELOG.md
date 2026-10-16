# Changelog - Taranehyar Scout

## 1.0.7 - 2026-10-17

### ویژگی جدید: پشتیبانی از گانجا2موزیک
- **Ganja2MusicProvider.php** اضافه شد - https://www.ganja2music.com/
  - جستجو: `?s={artist}` پارامتر استاندارد وردپرس با پارس `/ID/slug/` و کارت‌های نتایج
  - تشخیص صفحه reCAPTCHA: `is_recaptcha_page()` با WP_Error `recaptcha_blocked` - کاربر با پیام فارسی مطلع می‌شود
  - استخراج دقیق: `دانلود آهنگ X به نام Y` → artist_fa و track_fa فارسی خالص
  - EN: `Artist – Track` یا slug به EN (Kaveh Zhav Sunrise)
  - کاور از `wp-content/uploads/` با حذف thumbnail (-300x300)
  - MP3 لینک‌های 128 و 320 از `ganja2music.com` یا `dl` ساب‌دامین
  - متن ترانه ورس به ورس + اعتبارات Lyrics/Music/Arrangement/Mix/Producer
  - نرخ محدود: 3 ثانیه بین درخواست‌ها
  - تست زمینه: 1187787 kaveh-zhav-sunrise و 1187747 roozbeh-bemani-booseye-akhar (هر دو هنگام تست reCAPTCHA quota exceeded داشتند - هندل شد)
- **nicmusic** بررسی شد: در زمان تست، nicmusic.net بدون reCAPTCHA و قابل استفاده است - مشکل گزارش‌شده احتمالاً موقتی بوده یا مربوط به یک آهنگ خاص
- **Activator**: سیاست پیش‌فرض برای ganja2music (rate_limit 3, license needs_review)
- **ProviderRegistry**: ثبت Ganja2MusicProvider
- ساختار ZIP صحیح حفظ شد: `taranehyar-scout/taranehyar-scout.php`

## 1.0.6 - 2026-10-17

### رفع خطای "پرونده افزونه پیدا نشد"
- **علت:** در v1.0.0 تا v1.0.5 فایل ZIP با دستور `zip -r dist/taranehyar-scout.zip taranehyar-scout.php readme.txt ...` ساخته می‌شد که ساختار فلت داشت (فایل‌ها در روت ZIP بدون پوشه)
- وردپرس هنگام نصب انتظار دارد ZIP شامل یک پوشه `taranehyar-scout/` باشد که داخلش `taranehyar-scout.php` باشد
- وقتی ساختار فلت باشد، وردپرس فایل‌ها را مستقیم در `wp-content/plugins/` می‌ریزد و سپس دنبال `taranehyar-scout/taranehyar-scout.php` می‌گردد و خطای "پرونده افزونه پیدا نشد" می‌دهد
- **رفع:** تغییر اسکریپت ساخت ZIP به:
  ```
  mkdir -p /tmp/build/taranehyar-scout
  cp -r ... /tmp/build/taranehyar-scout/
  cd /tmp/build && zip -r ... taranehyar-scout
  ```
- حالا ZIP شامل `taranehyar-scout/taranehyar-scout.php` است و نصب بدون خطا انجام می‌شود

## 1.0.5 - 2026-10-17

### رفع خطای بحرانی Fatal Error
- **باگ:** در v1.0.4 فایل `NicMusicProvider.php` ساخته شد و در `ProviderRegistry` ثبت شد ولی در `taranehyar-scout.php` لود نشده بود → `Class 'NicMusicProvider' not found` → "یک خطای مهم در این وب سایت وجود دارد"
- **رفع:** اضافه کردن `require_once ... NicMusicProvider.php` در فایل اصلی
- تست: افزونه بدون Fatal فعال می‌شود، هر دو provider (mybia2music و nicmusic) در دسترس هستند

## 1.0.4 - 2026-10-17

### ویژگی جدید: پشتیبانی از نیک موزیک
- **NicMusicProvider.php** اضافه شد - https://nicmusic.net/
  - جستجو: `?s={artist}` استاندارد وردپرس
  - استخراج دقیق: `**محسن یگانه** به نام **دیوار**` و `By **Mohsen Yeganeh** Called **Divar**`
  - کاور از `/wp-content/uploads/` با حذف thumbnail
  - MP3 از `musitraf.com` مستقیم (320 و 128)
  - متن ترانه ورس به ورس از `متن آهنگ` تا `لینک های دانلود`
  - بدون نیاز به دور زدن reCAPTCHA (برخلاف ganja2music.com)
- **ganja2music.com** بررسی شد: محافظت reCAPTCHA Enterprise دارد و برای provider خودکار مناسب نیست - در صورت ارائه API رسمی قابل اضافه شدن است
- **ProviderRegistry** به‌روزرسانی: ثبت nicmusic
- **UrlValidator**: دامنه‌های nicmusic و ganja2music به لیست مجاز اضافه شد
- **Activator**: سیاست پیش‌فرض برای nicmusic

### رفع باگ عنوان "دانلود آهنگ Mohsen Yeganeh به نام محسن یگانه زندگیم سوخت" (از 1.0.3 به 1.0.4 منتقل شد)
- Provider mybia2music: clean_fa_title برای حالت بدون دانلود آهنگ و با "از"، fallback از lyricist
- Normalizer و Publisher: بازیابی artist_fa فارسی از metadata اگر انگلیسی بود
- عنوان نهایی: "دانلود آهنگ محسن یگانه به نام زندگیم سوخت"

## 1.0.3 - 2026-10-17

### رفع باگ عنوان "دانلود آهنگ Mohsen Yeganeh به نام محسن یگانه زندگیم سوخت"

**مشکل:** در صفحه "محسن یگانه زندگیم سوخت Mohsen Yeganeh Zendegim Sookht" بدون پیشوند "دانلود آهنگ"، عنوان فارسی شامل نام خواننده بود و artist_fa انگلیسی می‌شد.

**رفع:**
- Provider: `clean_fa_title()` بهبود یافته برای حالت‌های:
  - "محسن یگانه زندگیم سوخت" -> "زندگیم سوخت"
  - "بترس از محسن یگانه" -> "بترس"
  - "محسن یگانه - زندگیم سوخت" -> "زندگیم سوخت"
  - اگر artist_fa انگلیسی و lyricist فارسی، از lyricist به عنوان artist_fa استفاده کن
  - استخراج track از slug اگر عنوان تمیز نشد
- Normalizer: اگر artist_fa انگلیسی و lyricist فارسی، fallback به فارسی + پاکسازی عنوان شامل نام خواننده
- Publisher: اگر artist_fa انگلیسی و metadata شامل فارسی، بازیابی از metadata + تابع clean_track_fa بهبود یافته برای "محسن یگانه زندگیم سوخت"
- عنوان نهایی الان: "دانلود آهنگ محسن یگانه به نام زندگیم سوخت" (فارسی خالص)

## 1.0.2 - 2026-10-17 - (شاخه قبلی pr_merged)

## 1.0.1 - 2026-10-16

### سازگار با Taranehyar 1.0.6
- به‌روزرسانی برای سازگاری با ترانه‌یار 1.0.6 (قالب‌های سه‌گانه intro, ذخیره متن ترانه فقط در music_txt)
- گزارش ممیزی v1.0.6 اضافه شد

### اصلاح‌شده - بر اساس تست کاربر
- **عنوان**: اصلاح استخراج نام فارسی خواننده و نام آهنگ از MyBia2Music. الان عنوان به صورت "دانلود آهنگ محسن یگانه به نام تو خوب" ساخته می‌شود (artist_fa جدا از track_fa)
  - استخراج artist_fa از لینک `/artists/` 
  - جدا کردن نام ترک از عنوان کامل h1
  - جدا کردن track_en از title_en انگلیسی با استفاده از artist_en شناخته شده
- **متن ترانه**: 
  - بهبود parse_track_page با 3 روش regex/XPath برای استخراج lyrics
  - ذخیره در ستون lyrics و metadata_json
  - انتشار در متای `music_text` (برای قالب آهنگ‌بان) و `music_txt` (برای ترانه‌یار)
- **کاور و موزیک**:
  - اصلاح `TaranehyarAdapter::create_post` برای ذخیره `fifu_image_url`, `fifu_image_alt`, `music_text`, `video480/720/1080`
  - اضافه کردن `attach_cover_as_featured()` برای دانلود کاور و ست کردن به عنوان تصویر شاخص وردپرس (media_handle_sideload)
  - ذخیره سازگاری برای هر دو کلید (artist/song و art_name/track_name و music_text/music_txt و video480/video480p)
  - اصلاح `Publisher::should_transfer_file` برای اجازه انتقال پس از تایید مجوز حتی اگر policy می‌گوید allow_audio_download=0
  - پیش‌فرض `allow_external_urls=1` و `allow_audio_download=1` برای MyBia2Music
- **متاکی‌ها**:
  - نگاشت پیش‌فرض جدید برای قالب کاربر: art_name->artist, track_name->song, music_txt->music_text, video480p->video480 و غیره
  - fallback هوشمند در `get_meta_key()` که هم از ty_options و هم از theme_mapping می‌خواند
  - ستون‌های جدید `title_en` و `lyrics` در جدول discoveries با migration خودکار
- **HostDL**:
  - سازگار با https://dl.ahangban.ir/ و endpointهای کاربر
  - بهبود `build_music_transfer_data` با archive_path صحیح

### Added
- ستون‌های `title_en` و `lyrics` در جدول discoveries

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
