# گزارش امنیت Taranehyar Scout - نسخه 1.0.0
# توسعه‌دهنده: اتحاد وردپرس - https://etehadwp.com/

## 1. کنترل دسترسی (Capability)

- **تنظیمات**: `manage_options` - فقط مدیران
- **انتشار**: `publish_posts` - نویسندگان و بالاتر
- **مشاهده**: `edit_posts` - مشارکت‌کنندگان و بالاتر

همه صفحات ادمین و AJAX handlerها capability را بررسی می‌کنند.

## 2. Nonce و CSRF

- همه فرم‌ها دارای `wp_nonce_field`
- بررسی با `check_admin_referer` و `check_ajax_referer`
- در صورت نامعتبر بودن nonce، درخواست رد می‌شود (403)

## 3. اعتبارسنجی ورودی (Sanitization)

- `sanitize_text_field` برای متن
- `sanitize_key` برای شناسه‌ها
- `esc_url_raw` برای URLها
- `intval` برای اعداد
- `sanitize_title` برای slug
- `wp_kses_post` برای محتوای پست

## 4. جلوگیری از XSS (Escaping)

- `esc_html` برای متن در HTML
- `esc_attr` برای ویژگی‌ها
- `esc_url` برای لینک‌ها
- `esc_textarea` برای textarea

## 5. جلوگیری از SQL Injection

- همه کوئری‌ها با `$wpdb->prepare`
- استفاده از `$wpdb->insert` و `$wpdb->update` با فرمت‌بندی خودکار

## 6. جلوگیری از SSRF

`UrlValidator::validate()`:

- بررسی scheme فقط http/https
- بلاک localhost, 127.0.0.1, 0.0.0.0, ::1
- بررسی IP خصوصی با `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE`
- بررسی DNS با `gethostbynamel` و چک IPهای resolve شده
- بلاک دامنه‌های خصوصی (.local, .internal, .localhost, .test, .example, .invalid)
- بلاک URL با credentials (user:pass@host)
- بلاک path traversal (.. , \0)

برای providerها:

- `is_allowed_provider_domain()` بررسی می‌کند URL از دامنه مجاز provider است یا نه
- MyBia2Music: فقط mybia2music.com و زیرساخت sv*.mybia2music.com

## 7. جلوگیری از Path Traversal

- `Sanitizer::sanitize_filename()` حذف .., \, / و کاراکترهای خطرناک
- در HostDLAdapter و TaranehyarAdapter، نام فایل با basename و regex پاک‌سازی می‌شود
- در JobQueue::cleanup_files() بررسی می‌کند فایل داخل wp_upload_dir است

## 8. محدودیت نرخ درخواست (Rate Limiting)

- MyBia2MusicProvider: 2 ثانیه بین درخواست‌ها
- ذخیره آخرین درخواست در transient
- در صورت نیاز sleep

## 9. محدودیت حجم و اندازه

- `HostDLAdapter::validate_file()` بررسی حجم با HEAD request
- حداکثر 100MB پیش‌فرض
- بررسی پسوند مجاز: mp3, mp4, jpg, png, webp
- بررسی MIME type

## 10. مدیریت امن Credential

- توکن HostDL در `ty_options` ذخیره می‌شود (از Taranehyar)
- در لاگ‌ها، token, password, apitoken حذف می‌شود
- در Providerها، هیچ credential هاردکد نشده

## 11. جلوگیری از افشای اطلاعات حساس

- لاگ‌ها شامل مسیر داخلی، token، رمز عبور نیستند
- پیام‌های خطا اطلاعات حساس را نمایش نمی‌دهند
- Stack trace نمایش داده نمی‌شود

## 12. عملیات گروهی

- بررسی تعداد (حداکثر 100 در هر batch)
- بررسی capability برای حذف (فقط manage_options)
- تأیید با confirm dialog در JS

## 13. آپلود فایل

- در Scout، آپلود مستقیم نداریم (فقط URL خارجی)
- اگر در آینده اضافه شود، باید پسوند و MIME بررسی شود
- در Taranehyar اصلی، بررسی پسوند و wp_check_filetype وجود دارد

## 14. APIهای HTTP

- استفاده از WordPress HTTP API (wp_remote_get, wp_remote_post)
- sslverify => true
- timeout محدود (10-60 ثانیه)
- redirection محدود (2)
- User-Agent مشخص: TaranehyarScout/1.0

## 15. بررسی‌های اضافی

- **Idempotency**: provider_hash یکتا از provider+external_id جلوگیری از ایجاد تکراری
- **Duplicate Check**: قبل از ایجاد پست، عنوان بررسی می‌شود
- **License Governance**: فایل‌ها تا تأیید مجوز منتقل نمی‌شوند

## 16. تست‌های امنیتی

### تست‌های انجام شده:

- [x] دسترسی کاربر غیرمجاز (Contributor نمی‌تواند تنظیمات را ببیند)
- [x] Nonce نامعتبر
- [x] URL داخلی (localhost, 127.0.0.1) بلاک می‌شود
- [x] URL با IP خصوصی بلاک می‌شود
- [x] Path traversal در نام فایل
- [x] XSS در ورودی عنوان
- [x] SQL Injection در فیلترها

### تست‌های نیاز به محیط واقعی:

- [ ] تست با وردپرس واقعی و نقش‌های مختلف
- [ ] تست SSRF با DNS rebinding
- [ ] تست حجم فایل بزرگ
- [ ] تست Rate Limiting با درخواست‌های متعدد

## 17. محدودیت‌های شناخته‌شده

- WP-Cron وابسته به بازدید است - برای سایت‌های کم‌بازدید Cron واقعی نیاز است
- gethostbynamel ممکن است در برخی هاست‌ها غیرفعال باشد - fallback به allow
- فایل‌های 100MB+ ممکن است timeout شوند
- MyBia2Music ساختار HTML ممکن است تغییر کند

## 18. توصیه‌ها

- همیشه وردپرس و PHP را به‌روز نگه دارید
- توکن HostDL را محرمانه نگه دارید
- مجوز فایل‌ها را قبل از انتشار بررسی کنید
- لاگ‌ها را دوره‌ای پاک‌سازی کنید
- از HTTPS استفاده کنید

## 19. گزارش آسیب‌پذیری

اگر آسیب‌پذیری یافتید:

- از طریق https://etehadwp.com/ گزارش دهید
- جزئیات را عمومی نکنید تا رفع شود
- ما در اسرع وقت بررسی می‌کنیم
