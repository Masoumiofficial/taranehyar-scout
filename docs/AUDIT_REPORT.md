# گزارش ممیزی Taranehyar و Taranehyar HostDL نسخه 1.0.5
# تاریخ: 2026-10-16
# تهیه‌کننده: اتحاد وردپرس - ترانه‌یار اسکات

## A. گزارش معماری فعلی

### ساختار کلی
افزونه Taranehyar یک پنل ارسال پست موسیقی برای سایت‌های وردپرسی است که به‌صورت دو بخشی طراحی شده:
- **بخش وردپرس**: `taranehyar.php` به عنوان بوت‌استرپ، 7 کلاس در `includes/`، رابط RTL با تم روشن/تیره
- **بخش هاست دانلود**: اسکریپت‌های PHP مستقل در `hostdl/` که روی هاست دانلود جداگانه نصب می‌شوند و فایل‌ها را دریافت، تگ‌گذاری ID3 و ذخیره می‌کنند.

### فایل‌های اصلی وردپرس
- `taranehyar.php`: تعریف ثابت‌های `TY_VERSION`, `TY_PLUGIN_DIR`, `TY_PLUGIN_URL`، بارگذاری کلاس‌ها، هوک `plugins_loaded` -> `ty_init()`
- `includes/class-ty-settings.php`: مدیریت تنظیمات `ty_options`، شامل اتصال هاست، اطلاعات سایت، دسته‌بندی‌ها، نگاشت متاکی‌ها و الگوهای SEO. کلیدهای مهم:
  - `urldlhost`, `urlfunction_music/remix/nohe/video/album/leech`, `apitoken`, `namesite`, `namesite2`, `urlsite`
  - `cat_music`, `cat_remix`, `cat_nohe`, `cat_video`, `cat_album`
  - `singer_taxonomy`, `meta_key_*` برای 15 کلید متا
  - `seo_*` برای عنوان، توضیحات، متن آغازین فارسی/انگلیسی
- `includes/class-ty-admin-pages.php`: ثبت منوها با `add_menu_page` و `add_submenu_page`، قابلیت `publish_posts` برای پست‌ها و `manage_options` برای تنظیمات و انتقال‌ها. 6 فرم RTL: تک‌آهنگ، ریمیکس، نوحه، موزیک ویدیو، آلبوم، لیچ فایل + صفحه وضعیت انتقال‌ها + تنظیمات. دارایی‌ها: `assets/css/admin.css`, `assets/js/admin.js`
- `includes/class-ty-post-handler.php`: هندلرهای `admin_post_ty_submit_*` برای هر نوع محتوا. شامل:
  - اعتبارسنجی ورودی، sanitize، بررسی nonce با `check_admin_referer`
  - `safe_filename()` ضد Path Traversal
  - `validate_remote_url()` ارجاع به `TaranehYar_Http::validate_remote_url`
  - `upload_or_download()` و `prepare_source()` برای مدیریت آپلود مستقیم vs لینک
  - `archive_path()` از `TaranehYar_Archive::path()`
  - `download_file_url()` از `TaranehYar_Album_Meta::file_url()`
  - ساخت پست با `wp_insert_post`، ذخیره متا با `update_post_meta` و نگاشت متاکی‌ها، تنظیم ترم‌ها با `wp_set_object_terms`
  - افزودن به صف انتقال با `TaranehYar_Queue::add()`
- `includes/class-ty-http.php`: اعتبارسنجی URL ضد SSRF (بررسی scheme http/https، بلاک localhost, 0.0.0.0, ::1، بررسی IP خصوصی با `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`، بررسی DNS با `gethostbynamel`) و ارسال به هاست دانلود با `wp_remote_post`
- `includes/class-ty-queue.php`: صف مبتنی بر `wp_options` با کلیدهای `ty_queue`, `ty_failed`, `ty_log`. ویژگی‌ها: `add()`, `get_queue()`, `get_failed()`, `schedule_now()`, `run()` (حداکثر 280 ثانیه در هر اجرا)، `retry_failed()`, `discard_failed()`, `discard_pending()`, `clear_history()`, `cleanup_files()` با اعتبارسنجی مسیر داخل `wp_upload_dir`
- `includes/class-ty-archive.php`: نگاشت مسیر آرشیو بدون تاریخ، `artist_slug()` با `remove_accents` و regex `[^a-z0-9]+` -> `-`، `path()` با ساختار `{root}/{letter}/{slug}`، ریشه‌های مجاز: `music`, `remix`, `nohe`, `music-video`, `album`, `leech`
- `includes/class-ty-album-meta.php`: ساخت متادیتای آلبوم، `file_url()`, `build()` با اندیس یک‌مبنا `album_1_...` تا `album_15_...`، حفظ `album_dl` برای سازگاری
- `includes/class-ty-seo.php`: الگوهای SEO با جای‌نگهدارهای `{artist}`, `{track}`, `{artist_en}`, `{track_en}`, `{album}`, `{site}`, `{qualities}`, `{quality_sentence}` و غیره، خروجی Schema.org `MusicRecording`, `MusicAlbum`, `VideoObject`
- `includes/jdf.php`: تاریخ شمسی

### بخش هاست دانلود
- `hostdl/config.php`: توکن امنیتی `$smp_token`
- `hostdl/bootstrap.php`: `smp_require_auth()` با `hash_equals`, `smp_safe_name()`, `smp_validate_archive_path()` با regex دقیق `{root}/{letter}/{slug}`, `smp_archive_directory()`, `smp_host_is_public()` ضد SSRF، `smp_download()` با curl یا fopen و `CURLOPT_FOLLOWLOCATION => false`, `smp_write_id3()` با getID3 1.9.27، `smp_mkdir()`
- `hostdl/curl-music.php`: تک‌آهنگ/ریمیکس/نوحه، پارامتر `archive_root`، مسیر جدید `{root}/{letter}/{slug}`, سازگاری با مسیر تاریخ شمسی قدیمی
- `hostdl/curl-video.php`: ویدیو با کیفیت‌های 1080/720/480 + mp3
- `hostdl/curl-album.php`: آلبوم با ترک‌ها و ساخت ZIP
- `hostdl/curl-leech.php`: لیچ فایل
- `hostdl/PROTOCOL.md`: قرارداد ارتباطی نسخه 1 با جدول فیلدها

## B. نقشه ارتباط افزونه اصلی و HostDL

```
WordPress (Taranehyar)
  -> فرم ادمین (admin-post.php)
    -> اعتبارسنجی ورودی + ساخت پست + ذخیره متا (URL مقصد نهایی)
    -> TaranehYar_Queue::add() -> wp_options ty_queue
  -> WP-Cron ty_process_queue
    -> TaranehYar_Queue::run()
      -> TaranehYar_Http::post_to_dlhost(endpoint_key, data)
        -> wp_remote_post به HostDL endpoint + token

HostDL (remote server)
  -> smp_require_auth() -> 403/503
  -> smp_validate_archive_path() -> 400
  -> smp_download() برای هر لینک (cover, mp3, mp4)
    -> ضد SSRF با smp_host_is_public()
    -> ذخیره در {archive_path}/{file_name}
  -> smp_write_id3() با tag_site مشتری
  -> echo OK -> HTTP 200
```

جریان داده:
- ورودی: لینک یا آپلود مستقیم در فرم -> ذخیره موقت در `wp-content/uploads/{Y/m/d}/`
- پردازش: URL عمومی موقت ساخته می‌شود و به HostDL ارسال می‌شود
- خروجی: فایل نهایی در `music/a/artist/...` روی هاست دانلود + پست وردپرس با متای لینک دانلود

## C. فهرست APIها و توابع قابل‌استفاده

### کلاس TaranehYar_Settings
- `init()`: هوک admin_init, admin_enqueue_scripts
- `register_settings()`, `sanitize()`
- `get($key, $default)`: خواندن از `ty_options`
- `meta_key($key)`: کلید متای نگاشت‌شده با fallback
- ثابت‌ها: `META_KEY_DEFAULTS`, `ALL_KEYS`

### کلاس TaranehYar_Http
- `validate_remote_url($url)`: بررسی SSRF، بازگشت true|WP_Error
- `post_to_dlhost($endpoint_key, $data)`: ارسال به HostDL با token خودکار

### کلاس TaranehYar_Queue
- `add($args)`: افزودن job، زمان‌بندی cron، spawn_cron
- `get_queue()`, `get_failed($limit)`, `get_log($limit)`
- `schedule_now()`, `run()`, `retry_failed()`, `discard_failed()`, `discard_pending()`, `clear_history()`
- `log()`, `status_label()`

### کلاس TaranehYar_Archive
- `roots()`: آرایه ریشه‌های مجاز
- `artist_slug($artist_en)`: ساخت slug انگلیسی
- `path($root, $artist_en)`: مسیر آرشیو

### کلاس TaranehYar_Album_Meta
- `file_url($host, $archive_path, $filename)`: ساخت URL دانلود
- `build($tracks, $artist, $song, $host, $archive_path, $album_name)`: ساخت متاهای آلبوم

### کلاس TaranehYar_SEO
- `option_key($type, $field)`, `default_template($type, $field)`
- `render_template($template, $variables)`, `profile($type, $variables, $overrides)`
- `tags_for($type, $artist, $artist_en, $track)`

### کلاس TaranehYar_Admin_Pages
- `register_menus()`, `enqueue_assets()`
- صفحات: `page_music()`, `page_remix()`, `page_nohe()`, `page_video()`, `page_album()`, `page_leech()`, `page_transfers()`

### توابع HostDL
- `smp_require_auth()`, `smp_safe_name()`, `smp_validate_archive_path()`, `smp_archive_directory()`, `smp_host_is_public()`, `smp_download()`, `smp_write_id3()`, `smp_mkdir()`

## D. schema واقعی اطلاعات آثار موسیقی

### پست وردپرس
- `post_type`: `post`
- `post_status`: `publish` یا `draft`
- `post_title`: از الگوی SEO ساخته می‌شود، مثل "دانلود آهنگ {artist} به نام {track}"
- `post_content`: محتوای غنی با مشخصات، متن ترانه، فهرست ترک‌ها (برای آلبوم)، پیوند دسته، Schema.org
- `post_category`: آرایه ID دسته‌ها از تنظیمات
- `post_name`: slug از `enname - entrack` با `sanitize_title_with_dashes`

### متادیتا (کلیدهای قابل‌تنظیم)
- `art_name`: نام فارسی خواننده
- `track_name`: نام فارسی اثر
- `artist_en`: نام انگلیسی خواننده
- `song_en`: نام انگلیسی اثر
- `music128`, `music320`: URL فایل MP3 تک‌آهنگ/ریمیکس/نوحه
- `music_txt`: متن ترانه
- `musics_type`: نوع محتوا: `mp3` برای آهنگ، `mp4` برای ویدیو، `album` برای آلبوم
- `video1080p`, `video720p`, `video480p`: URL ویدیو
- `album`: تعداد ترک‌ها (عدد)
- `album_1_song_names` تا `album_15_song_names`: نام هر ترک (یک‌مبنا)
- `album_1_albumlink128`, `album_1_albumlink320` تا `album_15_*`: URL هر ترک
- `album_dl`: آرایه قدیمی ترک‌ها برای سازگاری
- `album128`, `album320`: URL ZIP آلبوم
- `talbume128`, `talbume320`: URL پوشه آلبوم
- `fifu_image_url`, `fifu_image_alt`: کاور (FIFU)
- `fifu_image_url` به `wp-content/uploads/{Y/m/d}/{trackname}.jpg` اشاره دارد اما پس از انتقال، کاور اصلی در HostDL نیست، بلکه برای تگ ID3 استفاده می‌شود
- `online_ply`: `off`

### تاکسونومی‌ها
- `singer`: خواننده (قابل‌تنظیم)
- `songwriter`, `composer`, `regulator`, `mixmaster`: ترانه‌سرا، آهنگساز، تنظیم‌کننده، میکس‌مستر
- تگ‌ها: از `TaranehYar_SEO::tags_for()` شامل "دانلود آهنگ"، نام هنرمند و غیره

### مسیر آرشیو فایل‌ها
- جدید: `{root}/{letter}/{artist-slug}/{file_name}.mp3`
  - مثال: `music/a/alireza-rozegar/Alireza Rozegar - Track.mp3`
- قدیمی (سازگاری): `music/{Y/m/d}/...` با تاریخ شمسی
- ریشه‌ها: `music`, `remix`, `nohe`, `music-video`, `album`, `leech`

## E. نقاط توسعه رسمی و امن

1. **خواندن تنظیمات**: از `TaranehYar_Settings::get()` و `::meta_key()` استفاده کنید، نه دسترسی مستقیم به `get_option('ty_options')`
2. **ارسال به HostDL**: از `TaranehYar_Http::post_to_dlhost()` استفاده کنید که SSRF و توکن را مدیریت می‌کند
3. **مسیر آرشیو**: از `TaranehYar_Archive::path()` برای ساخت مسیر استفاده کنید
4. **URL فایل**: از `TaranehYar_Album_Meta::file_url()` برای ساخت URL مقصد
5. **صف انتقال**: از `TaranehYar_Queue::add()` برای افزودن job جدید
6. **متادیتای آلبوم**: از `TaranehYar_Album_Meta::build()` برای ساخت متاهای آلبوم
7. **الگوهای SEO**: از `TaranehYar_SEO::profile()` و `::render_template()` برای عنوان و توضیحات
8. **اعتبارسنجی URL**: از `TaranehYar_Http::validate_remote_url()` برای بررسی SSRF
9. **هوک‌ها**: 
   - `plugins_loaded` برای init
   - `admin_menu` برای منوها
   - `admin_post_*` برای فرم‌ها
   - `ty_process_queue` برای پردازش صف
10. **امنیت**: `publish_posts` برای ارسال پست، `manage_options` برای تنظیمات، `check_admin_referer` برای nonce

## F. محدودیت‌ها و ریسک‌های فنی

### محدودیت‌های معماری
- **وابستگی به قالب**: متاها و تاکسونومی‌ها به قالب موزیک خاصی کوپل هستند؛ بدون `singer` تاکسونومی، پست بدون خواننده می‌ماند
- **هاست دانلود جداگانه**: نیاز به هاست دوم با PHP و curl، توکن مشترک، تنظیمات دستی
- **WP-Cron وابسته به بازدید**: در سایت‌های کم‌بازدید، صف ممکن است اجرا نشود؛ نیاز به Cron واقعی
- **ذخیره صف در options**: برای آرشیو بزرگ، `ty_queue` و `ty_log` ممکن است بزرگ شوند؛ نیاز به پاک‌سازی دوره‌ای
- **بدون API REST**: همه عملیات از طریق admin-post.php و WP-Cron انجام می‌شود
- **پردازش همزمان محدود**: `run()` حداکثر 280 ثانیه در هر اجرا، بدون مدیریت همزمانی واقعی
- **بدون تراکنش**: شکست در میانه عملیات ممکن است پست ناقص ایجاد کند

### ریسک‌های امنیتی (در نسخه 1.0.5 رفع شده اما نیاز به توجه)
- **توکن**: اگر `config.php` تنظیم نشود، 503؛ اگر توکن نامعتبر باشد، 403. اما اگر توکن لو برود، هر کسی می‌تواند فایل روی هاست دانلود بنویسد
- **Path Traversal**: در نسخه‌های قدیمی وجود داشت، در 1.0.5 با `smp_safe_name()` و `smp_validate_archive_path()` رفع شده
- **SSRF**: در 1.0.5 با `smp_host_is_public()` و بررسی DNS رفع شده اما همچنان نیاز به به‌روزرسانی مداوم دارد
- **آپلود فایل**: فقط پسوندهای `mp3`, `mp4`, `jpg`, `jpeg`, `png`, `webp` مجاز هستند با `wp_check_filetype`
- **حجم فایل**: بدون محدودیت حجم مشخص، ممکن است دیسک پر شود
- **ID3 تگ**: از `tag_site` مشتری استفاده می‌کند، نه برند ثابت

### ریسک‌های سازگاری
- **نسخه PHP**: حداقل 7.2، پیشنهاد 8.1+؛ تست شده تا 8.3
- **وردپرس**: حداقل 5.8، تست شده تا 6.7
- **تاکسونومی**: اگر `singer` وجود نداشته باشد، `wp_set_object_terms` بی‌صدا شکست می‌خورد
- **متای قدیمی**: `album` در نسخه‌های قدیمی آرایه بود، در 1.0.5 عدد است؛ سازگاری با `album_dl` حفظ شده
- **مسیر قدیمی**: درخواست‌های بدون `archive_path` در مسیر تاریخ شمسی ذخیره می‌شوند

### محدودیت‌های HostDL
- **ماژول‌های PHP**: نیاز به `curl` (ترجیحی) یا `allow_url_fopen`، `zip` برای آلبوم
- **فضای دیسک**: بدون محدودیت خودکار، ممکن است پر شود
- **timeout**: 3600 ثانیه برای دانلود، ممکن است در هاست‌های اشتراکی fail شود
- **تگ ID3**: فقط برای MP3، نیاز به کتابخانه getID3

## G. تصمیم مستند درباره روش یکپارچه‌سازی

### روش انتخابی: Adapter + API رسمی موجود

**دلیل**: Taranehyar API عمومی مستند ندارد اما توابع و کلاس‌های داخلی قابل‌استفاده هستند. تغییرات در افزونه اصلی باید به حداقل برسد.

**معماری Adapter**:
1. **Taranehyar_Adapter**: کلاسی که وجود افزونه اصلی را بررسی می‌کند (`class_exists('TaranehYar_Settings')`)، تنظیمات را می‌خواند، متاکی‌ها را نگاشت می‌کند، و روش‌های ایجاد پست را فراهم می‌کند. اگر افزونه نصب نباشد، خطای Fatal نمی‌دهد بلکه پیام مناسب نمایش می‌دهد.
2. **HostDL_Adapter**: از `TaranehYar_Http::post_to_dlhost()` اگر موجود باشد استفاده می‌کند، در غیر این صورت پیاده‌سازی مستقل با همان منطق SSRF و توکن.
3. **Catalog_Scanner**: از `$wpdb` برای جستجوی پست‌های موجود استفاده می‌کند، با در نظر گرفتن متاکی‌های قابل‌تنظیم.
4. **Publisher**: از `wp_insert_post` و `update_post_meta` با همان ساختار Taranehyar استفاده می‌کند، بدون دسترسی مستقیم به دیتابیس HostDL.

**قراردادهای یکپارچه‌سازی**:
- برای ایجاد پست: از `wp_insert_post` با همان فیلدهای Taranehyar استفاده می‌شود
- برای متا: از `TaranehYar_Settings::meta_key()` برای نگاشت کلیدها
- برای فایل: از `TaranehYar_Archive::path()` و `TaranehYar_Album_Meta::file_url()` برای ساخت مسیر و URL
- برای انتقال: از `TaranehYar_Queue::add()` یا صف اختصاصی Scout با همان ساختار
- برای SEO: از `TaranehYar_SEO::profile()` برای عنوان و توضیحات

**عدم تغییر در افزونه اصلی**:
- هیچ فایلی از Taranehyar ویرایش نمی‌شود
- فقط از APIهای موجود و هوک‌های وردپرس استفاده می‌شود
- در صورت نیاز، API داخلی جدید و نسخه‌بندی‌شده در Scout طراحی می‌شود

**مدیریت وابستگی‌ها**:
- در `admin_init` بررسی می‌شود که آیا Taranehyar فعال است یا نه
- اگر نباشد، پیام هشدار نمایش داده می‌شود اما افزونه فعال می‌ماند
- اگر HostDL تنظیم نشده باشد، انتقال فایل غیرفعال می‌شود اما متادیتا قابل ثبت است

**امنیت**:
- همه ورودی‌ها sanitize می‌شوند
- URLها با `TaranehYar_Http::validate_remote_url()` یا پیاده‌سازی مشابه بررسی می‌شوند
- capability `publish_posts` برای انتشار و `manage_options` برای تنظیمات
- nonce برای همه فرم‌ها
- ضد CSRF, XSS, SQL Injection, SSRF, Path Traversal

**نتیجه**: این روش امن، سازگار و قابل‌توسعه است و با معماری واقعی پروژه هماهنگ است.

---

# گزارش تکمیلی ممیزی Taranehyar نسخه 1.0.6
# تاریخ: 2026-10-16
# تهیه‌کننده: اتحاد وردپرس - ترانه‌یار اسکات نسخه 1.0.1

## تغییرات نسخه 1.0.6 نسبت به 1.0.5

### فایل‌های تغییریافته
- `taranehyar.php`: VERSION 1.0.6، همان ثابت‌ها و ساختار
- `includes/class-ty-post-handler.php`: متن ترانه در متای `music_txt` ذخیره می‌شود (نه در محتوای پست)؛ فرم‌ها همان سه قالب فارسی/انگلیسی قابل انتخاب؛ title پست از الگوی SEO profile می‌آید
- `includes/class-ty-seo.php`: الگوها همان، سه قالب قابل ویرایش در تنظیمات و فقط انتخاب در فرم
- `docs/INSTALL-fa.md`: مستند کامل سه‌گانه تک‌آهنگ/ریمیکس/نوحه + آلبوم با کلیدهای یک‌مبنا `album_1_...`، مسیر جدید بدون تاریخ `{root}/{letter}/{slug}`
- `hostdl/bootstrap.php`: همان احراز هویت hash_equals، safe_name و validate_archive_path با regex `{root}/{letter}/{slug}`، لیست ریشه مجاز شامل `music, remix, nohe, music-video, album, leech`
- `hostdl/curl-music.php`: پشتیبانی archive_root برای ریمیکس و نوحه، همان SSRF و ID3
- `hostdl/curl-album.php`: ساخت ZIP با 15 ترک حداکثر، کلیدهای `album_{n}_...`
- `hostdl/PROTOCOL.md`: نسخه 1.0.6 با توضیح archive_path اختیاری و fallback به مسیر شمسی

### متاکی‌های پیش‌فرض (بدون تغییر)
```
singer_taxonomy => singer
musics_type => musics_type
artist_en => artist_en
song_en => song_en
art_name => artist
track_name => song
music_txt => music_text (در فرم لیچ/تک آهنگ، مقدار از POST lyric گرفته و در music_txt ذخیره می‌شود)
music128 => music128
music320 => music320
album => album (در 1.0.6 عدد تعداد ترک‌ها، نه آرایه)
album128 => album128
album320 => album320
video480p => video480
video720p => video720
video1080p => video1080
fifu_image_url / fifu_image_alt => کاور
```
قابل نگاشت از تنظیمات سازگاری قالب.

### تفاوت مهم در مدیریت متن ترانه
- در 1.0.6 متن ترانه از `$_POST['lyric']` خوانده و با `sanitize_textarea_field` ذخیره می‌شود در متای `music_txt`
- در محتوا فقط بخش‌های توضیحی SEO و لیست عوامل و لینک‌های آلبوم قرار می‌گیرد
- این برای جلوگیری از تکرار و سازگاری با قالب است
- **Scout 1.0.1**: ما متن استخراج شده را هم در `music_txt` (برای ترانه‌یار) و هم در `music_text` (برای قالب آهنگ‌بان کاربر) ذخیره می‌کنیم تا هر دو قالب کار کنند.

### تفاوت مهم در عنوان
- عنوان پست از الگوی SEO profile با جای‌نگهدار `{artist}`, `{track}` ساخته می‌شود، مثل "دانلود آهنگ {artist} به نام {track}"
- **نیاز کاربر**: عنوان فارسی جدا شده "دانلود آهنگ {artist_fa} به نام {track_fa}" با نام‌های فارسی تفکیک شده از MyBia2Music
- **Scout 1.0.1**: در Publisher عنوان را با artist_fa و track_fa تفکیک شده می‌سازد و track_fa را اگر با artist_fa شروع شود جدا می‌کند.

### سازگاری قالب کاربر (آهنگ‌بان)
کاربر نگاشت زیر را گزارش کرده:
```
taxonomy singer
musics_type → musics_type
artist_en → artist_en
song_en → song_en
art_name → artist
track_name → song
music_txt → music_text
music128 → music128
music320 → music320
album → album
album128 → album128
album320 → album320
video480p → video480
video720p → video720
video1080p → video1080
```
این نگاشت در Scout 1.0.1 به صورت پیش‌فرض در theme_meta_mapping قرار گرفت و در get_meta_key() از ترکیب ty_options + theme_mapping خوانده می‌شود.

### HostDL و انتقال فایل
- پایه: https://dl.ahangban.ir/
- endpointها از ty_options خوانده می‌شوند: `urldlhost`, `urlfunction_music`, `urlfunction_video`, `urlfunction_album`, `urlfunction_leech`
- curl-music.php برای single/remix/nohe با پارامتر archive_root
- curl-video.php برای ویدیو
- curl-album.php برای آلبوم
- curl-leech.php برای لیچ
- احراز هویت با apitoken و hash_equals
- مسیر نهایی: `{root}/{letter}/{artist_slug}/{safe_filename}`
- **Scout 1.0.1**: انتقال با همان منطق HostDL_Adapter انجام می‌شود، archive_path از title_en ساخته می‌شود و safe_artist/track با Constants::sanitize_filename

### باگ‌های گزارش‌شده توسط کاربر در Scout 1.0.0 و رفع در 1.0.1
1. عنوان فارسی به صورت کامل h1 ذخیره می‌شد (مثل "دانلود آهنگ محسن یگانه - تو خوب") → الان جدا شده به artist_fa و track_fa
2. متن ترانه ذخیره نمی‌شد → بهبود parse_track_page با 3 روش و ذخیره در music_txt + music_text
3. کاور در پست ذخیره نمی‌شد → اضافه شدن attach_cover_as_featured با sideload و fifu_image_url
4. موزیک به HostDL منتقل نمی‌شد و متا به URL هاست دانلود اشاره نمی‌کرد → should_transfer_file الان اجازه می‌دهد اگر license_approved باشد و allow_external_urls=1 پیش‌فرض
5. نگاشت متاکی‌های قالب کاربر کار نمی‌کرد → اضافه شدن theme_meta_mapping و fallback در get_meta_key

### تست سازگاری
- Scout 1.0.1 مستقل نصب می‌شود (بدون نیاز به Taranehyar اما اگر باشد استفاده می‌کند)
- بدون Fatal اگر Taranehyar یا HostDL نباشد (بررسی class_exists و is_configured)
- جدول discoveries با ستون‌های جدید title_en و lyrics و migration خودکار
- سیاست مجوز: needs_review نیاز به تایید دستی، license_approved اجازه انتقال

**نتیجه ممیزی 1.0.6**: هیچ تغییر breaking در API اصلی وجود ندارد، فقط متن ترانه به music_txt منتقل شده و الگوهای SEO سه‌گانه قابل انتخاب هستند. Scout 1.0.1 با این تغییرات سازگار است و علاوه بر آن نگاشت قالب آهنگ‌بان را پشتیبانی می‌کند.
