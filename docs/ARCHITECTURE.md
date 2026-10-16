# معماری Taranehyar Scout - نسخه 1.0.0
# توسعه‌دهنده: اتحاد وردپرس - https://etehadwp.com/

## 1. نمای کلی

Taranehyar Scout یک افزونه مستقل وردپرس است که به عنوان مکمل Taranehyar عمل می‌کند. معماری آن بر اساس جداسازی مسئولیت‌ها، امنیت و توسعه‌پذیری طراحی شده است.

```
┌─────────────────────────────────────────┐
│         WordPress Admin (RTL)           │
│  ┌──────────┐ ┌──────────┐ ┌─────────┐ │
│  │ Dashboard│ │ Discovery│ │ Results │ │
│  └──────────┘ └──────────┘ └─────────┘ │
└──────────────────┬──────────────────────┘
                   │
┌──────────────────▼──────────────────────┐
│           Core / Loader                 │
│  - Constants, Activator, Deactivator    │
│  - Security (Capability, Sanitizer,     │
│    UrlValidator)                        │
│  - Logging                              │
└──────┬──────────┬──────────┬────────────┘
       │          │          │
┌──────▼───┐ ┌────▼─────┐ ┌──▼──────────┐
│ Providers│ │ Metadata │ │   Catalog   │
│ - Registry│ │ - Normalizer│ │ - Adapter │
│ - MyBia2M │ │           │ │ - Scanner │
│ - Mock   │ │           │ │           │
└──────┬───┘ └────┬─────┘ └──┬──────────┘
       │          │          │
       └──────────┼──────────┘
                  │
         ┌────────▼────────┐
         │    Matching     │
         │ - Matcher       │
         │ - Similarity    │
         └────────┬────────┘
                  │
     ┌────────────┼────────────┐
     │            │            │
┌────▼─────┐ ┌────▼─────┐ ┌────▼─────┐
│Publishing│ │  HostDL  │ │   Jobs   │
│ - Publisher│ │ - Adapter│ │ - Queue  │
│ - StateMachine│ │        │ │ - Cron   │
└──────────┘ └──────────┘ └──────────┘
```

## 2. ساختار دایرکتوری

```
taranehyar-scout/
├── taranehyar-scout.php      # فایل اصلی افزونه با هدر برند
├── readme.txt                # توضیحات وردپرس
├── uninstall.php             # پاک‌سازی
├── includes/
│   ├── Core/
│   │   ├── Constants.php     # ثابت‌ها و وضعیت‌ها
│   │   ├── Activator.php     # ایجاد جداول و تنظیمات
│   │   ├── Deactivator.php   # پاک‌سازی cron
│   │   ├── Loader.php        # مدیریت هوک‌ها
│   │   └── Plugin.php        # بوت‌استرپ اصلی
│   ├── Security/
│   │   ├── Capability.php    # بررسی دسترسی
│   │   ├── Sanitizer.php     # پاک‌سازی ورودی
│   │   └── UrlValidator.php  # ضد SSRF
│   ├── Logging/
│   │   └── Logger.php        # لاگ عملیات
│   ├── Providers/
│   │   ├── ProviderInterface.php
│   │   ├── ProviderRegistry.php
│   │   ├── MyBia2MusicProvider.php
│   │   └── MockProvider.php
│   ├── Metadata/
│   │   └── Normalizer.php    # استانداردسازی
│   ├── Catalog/
│   │   ├── TaranehyarAdapter.php # آداپتور ترانه‌یار
│   │   └── ArchiveScanner.php    # اسکن آرشیو
│   ├── Matching/
│   │   └── Matcher.php       # تطبیق هوشمند
│   ├── Publishing/
│   │   ├── Publisher.php     # انتشار
│   │   └── StateMachine.php  # ماشین وضعیت
│   ├── HostDL/
│   │   └── HostDLAdapter.php # انتقال فایل
│   ├── Jobs/
│   │   └── JobQueue.php      # صف پس‌زمینه
│   └── Admin/
│       ├── AdminPages.php    # منوها و صفحات
│       ├── AjaxHandlers.php  # هندلرهای AJAX
│       └── views/            # قالب‌های ادمین
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── languages/                # ترجمه‌ها
└── docs/
    ├── AUDIT_REPORT.md
    ├── ARCHITECTURE.md
    └── PROVIDER_GUIDE.md
```

## 3. جداول دیتابیس

### discoveries
- `id`, `external_id`, `provider`, `provider_hash` (UNIQUE)
- `artist_en`, `artist_fa`, `artist_normalized`
- `title`, `title_normalized`, `title_fa`, `album`
- `track_type`, `is_remix`, `is_live`, `is_collab`
- `year`, `duration`, `genre`, `language`
- `source_url`, `cover_url`, `audio_url_128`, `audio_url_320`, `video_urls`
- `metadata_json`, `confidence`, `license_status`, `match_status`, `matched_post_id`, `discovery_status`
- Indexها: provider, artist_normalized, title_normalized, match_status, discovery_status

### jobs
- `id`, `job_id` (UNIQUE), `job_type`, `payload_json`
- `status`, `progress`, `attempts`, `max_attempts`, `last_error`, `checkpoint`
- `created_at`, `updated_at`, `scheduled_at`, `started_at`, `completed_at`

### logs
- `id`, `level`, `message`, `context_json`, `job_id`, `discovery_id`, `created_at`

## 4. جریان داده

### کشف آثار
1. کاربر نام هنرمند را وارد می‌کند
2. Job از نوع `discovery_search` در صف قرار می‌گیرد
3. WP-Cron job را پردازش می‌کند
4. برای هر provider فعال، `search_artist()` صدا زده می‌شود
5. نتایج خام با `Normalizer::normalize()` استاندارد می‌شوند
6. در جدول discoveries ذخیره می‌شوند (با provider_hash یکتا)

### تطبیق
1. Job از نوع `discovery_match`
2. `ArchiveScanner::find_matches()` آثار مشابه را از پست‌های وردپرس می‌یابد
3. `Matcher::match()` وضعیت را تعیین می‌کند: published, draft, possible_duplicate, not_published, needs_manual_review
4. نتیجه در discoveries به‌روز می‌شود

### انتشار
1. کاربر پیش‌نمایش را می‌بیند (Publisher::preview)
2. تأیید مدیر
3. StateMachine مراحل را طی می‌کند:
   - validating -> checking_license -> checking_duplicate -> preview -> awaiting_approval -> preparing_metadata -> transferring_file (اختیاری) -> validating_file -> creating_post -> final_check -> completed
4. در هر مرحله checkpoint ثبت می‌شود
5. در صورت شکست، وضعیت failed و امکان retry

## 5. امنیت

- **Capability**: manage_options برای تنظیمات، publish_posts برای انتشار، edit_posts برای مشاهده
- **Nonce**: همه فرم‌ها و AJAX با wp_nonce
- **Sanitize**: تمام ورودی‌ها با sanitize_text_field, sanitize_key, esc_url_raw
- **SSRF**: UrlValidator با بررسی scheme, IP خصوصی, DNS rebinding, دامنه‌های داخلی
- **Path Traversal**: safe_filename با حذف .., \, /
- **XSS**: esc_html, esc_attr در خروجی‌ها
- **SQL Injection**: $wpdb->prepare
- **File Validation**: بررسی پسوند, MIME, حجم

## 6. توسعه‌پذیری

### افزودن provider جدید
1. کلاسی بسازید که ProviderInterface را پیاده‌سازی کند
2. از طریق فیلتر tscout_providers ثبت کنید
3. مستندات در PROVIDER_GUIDE.md

### افزودن نوع محتوای جدید
- در Constants::TYPE_* اضافه کنید
- در Normalizer::normalize_type نگاشت کنید
- در Provider تشخیص دهید

## 7. وابستگی‌ها

- **نرم**: Taranehyar - اگر نباشد، هشدار نمایش داده می‌شود اما افزونه کار می‌کند
- **نرم**: HostDL - اگر نباشد، فقط متادیتا منتشر می‌شود
- **سخت**: وردپرس 5.8+, PHP 7.2+

## 8. تصمیم‌های فنی

- **چرا جدول اختصاصی؟** برای جلوگیری از N+1 query، ذخیره provider_hash یکتا، و عدم وابستگی به postmeta برای داده‌های خارجی
- **چرا WP-Cron نه Action Scheduler؟** برای سازگاری با ترانه‌یار که از WP-Cron استفاده می‌کند و عدم نیاز به وابستگی خارجی
- **چرا DOMDocument نه کتابخانه خارجی؟** برای عدم وابستگی و امنیت
- **چرا adapter نه تغییر مستقیم؟** برای حفظ سازگاری و عدم شکست در صورت عدم وجود ترانه‌یار

## 9. محدودیت‌ها

- WP-Cron وابسته به بازدید است - برای سایت‌های کم‌بازدید Cron واقعی پیشنهاد می‌شود
- جستجو در MyBia2Music ممکن است به دلیل تغییر ساختار HTML نیاز به به‌روزرسانی داشته باشد
- فایل‌های صوتی فقط در صورت داشتن مجوز منتقل می‌شوند
- حجم فایل‌ها محدود به 100MB (قابل تنظیم)

## 10. آینده

- افزودن providerهای بیشتر (Spotify API, MusicBrainz)
- پشتیبانی از آلبوم کامل
- تشخیص خودکار زبان و سبک
- API REST برای استفاده خارجی
