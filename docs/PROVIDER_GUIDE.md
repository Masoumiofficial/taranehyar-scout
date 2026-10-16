# راهنمای افزودن Provider جدید - Taranehyar Scout
# توسعه‌دهنده: اتحاد وردپرس - https://etehadwp.com/

## 1. مقدمه

Providerها منابع کشف آثار موسیقی هستند. هر provider یک کلاس مستقل است که ProviderInterface را پیاده‌سازی می‌کند.

## 2. ساختار ProviderInterface

```php
interface ProviderInterface {
    public function get_id(); // شناسه یکتا مثل 'mybia2music'
    public function get_name(); // نام انگلیسی
    public function get_label(); // نام فارسی
    public function is_available(); // آیا فعال است؟
    public function get_status(); // وضعیت
    public function search_artist($artist_en, $options = []); // جستجوی هنرمند
    public function get_track_details($source_url); // جزئیات اثر
    public function validate_config(); // اعتبارسنجی
}
```

## 3. نمونه ساده

```php
<?php
namespace TaranehyarScout\Providers;

class MyCustomProvider implements ProviderInterface {
    
    public function get_id() {
        return 'mycustom';
    }
    
    public function get_name() {
        return 'My Custom Source';
    }
    
    public function get_label() {
        return 'منبع سفارشی من';
    }
    
    public function is_available() {
        $options = get_option('tscout_options', []);
        $policies = $options['provider_policies'][$this->get_id()] ?? [];
        return $policies['enabled'] ?? true;
    }
    
    public function get_status() {
        return [
            'id' => $this->get_id(),
            'name' => $this->get_name(),
            'label' => $this->get_label(),
            'available' => $this->is_available(),
            'supports_search' => true,
            'supports_types' => ['single', 'album'],
        ];
    }
    
    public function validate_config() {
        return true;
    }
    
    public function search_artist($artist_en, $options = []) {
        // $artist_en = 'Mohsen Yeganeh'
        // $options = ['limit' => 20, 'types' => ['single']]
        
        // اینجا منطق جستجوی خود را پیاده‌سازی کنید
        // می‌توانید از wp_remote_get استفاده کنید
        
        $tracks = [];
        
        // مثال: دریافت از API
        $response = wp_remote_get('https://api.example.com/search?q=' . urlencode($artist_en));
        if (is_wp_error($response)) {
            return $response;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        foreach ($data['tracks'] as $item) {
            $tracks[] = [
                'external_id' => $item['id'], // شناسه یکتا در منبع
                'provider' => $this->get_id(),
                'source_url' => $item['url'], // لینک صفحه اثر
                'title' => $item['title'],
                'artist_en' => $artist_en,
                'type' => 'single', // single, album, remix, live, collab
                'cover_url' => $item['cover'],
                'audio_url_128' => $item['audio_128'] ?? '',
                'audio_url_320' => $item['audio_320'] ?? '',
                'genre' => $item['genre'] ?? '',
                'year' => $item['year'] ?? null,
            ];
        }
        
        return $tracks;
    }
    
    public function get_track_details($source_url) {
        // دریافت جزئیات بیشتر از صفحه اثر
        // اختیاری است - اگر در search_artist اطلاعات کامل دارید، می‌توانید خالی برگردانید
        
        $response = wp_remote_get($source_url);
        if (is_wp_error($response)) {
            return $response;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Parse HTML و استخراج اطلاعات
        // ...
        
        return [
            'cover_url' => 'https://...',
            'audio_url_128' => 'https://...',
            'audio_url_320' => 'https://...',
            'genre' => 'Pop',
        ];
    }
}
```

## 4. ثبت Provider

### روش 1: از طریق فیلتر (پیشنهادی)

در فایل افزونه یا قالب خود:

```php
add_filter('tscout_providers', function($providers) {
    require_once __DIR__ . '/MyCustomProvider.php';
    $providers[] = new \TaranehyarScout\Providers\MyCustomProvider();
    return $providers;
});
```

### روش 2: مستقیم در Registry (برای توسعه هسته)

در `ProviderRegistry::init()`:

```php
self::register(new MyCustomProvider());
```

## 5. نکات امنیتی

### الزامی:

- **SSRF**: همیشه URLها را با `UrlValidator::validate()` بررسی کنید
- **Rate Limiting**: بین درخواست‌ها تأخیر بگذارید (حداقل 1 ثانیه)
- **Timeout**: timeout مناسب (10-30 ثانیه)
- **User-Agent**: User-Agent مشخص بگذارید
- **No Bypass**: از دور زدن CAPTCHA, paywall, احراز هویت خودداری کنید

```php
use TaranehyarScout\Security\UrlValidator;

$valid = UrlValidator::validate($url);
if (is_wp_error($valid)) {
    return $valid;
}

// Rate limiting
$last = get_transient('tscout_last_request_' . $this->get_id());
if ($last && time() - $last < 2) {
    sleep(2 - (time() - $last));
}
set_transient('tscout_last_request_' . $this->get_id(), time(), 60);
```

### فیلدهای خروجی search_artist:

- `external_id` (الزامی): شناسه یکتا
- `provider` (الزامی): شناسه provider
- `source_url` (الزامی): لینک صفحه
- `title` (الزامی): عنوان
- `artist_en` (پیشنهادی): نام انگلیسی هنرمند
- `type`: single, album, remix, live, collab, ep, video
- `cover_url`: کاور
- `audio_url_128`, `audio_url_320`: لینک صوتی
- `genre`, `year`, `duration`: اختیاری
- `is_remix`, `is_live`, `is_collab`: boolean

## 6. سیاست مجوز

هر provider باید سیاست مجوز داشته باشد. در تنظیمات:

- `verified`: استفاده و بازنشر تأیید شده
- `needs_review`: نیاز به بررسی
- `metadata_only`: فقط متادیتا
- `file_allowed_not_verified`: فایل قابل دریافت اما بازنشر تأیید نشده
- `forbidden`: ممنوع

پیش‌فرض برای همه `needs_review` است تا از انتشار بدون مجوز جلوگیری شود.

## 7. تست Provider

### تست دستی:

1. افزونه Mock Provider را فعال کنید (در تنظیمات)
2. نام هنرمند تستی وارد کنید
3. نتایج را بررسی کنید

### تست خودکار:

```php
// tests/ProviderTest.php
$provider = new MyCustomProvider();
$results = $provider->search_artist('Test Artist', ['limit' => 5]);
assert(!is_wp_error($results));
assert(count($results) > 0);
```

## 8. نمونه واقعی: MyBia2MusicProvider

این provider را به عنوان مرجع بررسی کنید:

- جستجو از طریق `https://www.mybia2music.com/search/{artist}`
- Parse HTML با DOMDocument
- استخراج لینک‌ها با regex: `/(\d+)/([a-z0-9\-]+)`
- دریافت جزئیات با fetch صفحه و regex برای MP3
- Rate limiting 2 ثانیه
- بدون دور زدن محدودیت

## 9. چک‌لیست انتشار Provider

- [ ] ProviderInterface پیاده‌سازی شده
- [ ] get_id یکتا و با حروف کوچک
- [ ] SSRF بررسی می‌شود
- [ ] Rate limiting دارد
- [ ] خطاها به درستی مدیریت می‌شوند (WP_Error)
- [ ] فیلدهای الزامی برگردانده می‌شوند
- [ ] از دور زدن محدودیت استفاده نمی‌کند
- [ ] تست شده
- [ ] مستندات دارد

## 10. پشتیبانی

برای سؤال یا پیشنهاد:
- وب‌سایت: https://etehadwp.com/
- مخزن: https://github.com/Masoumiofficial/taranehyar-scout
