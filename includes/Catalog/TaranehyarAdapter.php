<?php
namespace TaranehyarScout\Catalog;

if (!defined('ABSPATH')) exit;

class TaranehyarAdapter {
    
    private static $instance = null;
    private $is_active = false;
    private $settings = array();
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->is_active = class_exists('TaranehYar_Settings');
        if ($this->is_active) {
            $this->settings = get_option('ty_options', array());
        }
    }
    
    public function is_active() {
        return $this->is_active;
    }
    
    public function get_settings() {
        return $this->settings;
    }
    
    public function get_meta_key($key) {
        // نگاشت پیش‌فرض برای قالب‌های متفاوت (مثل آهنگ‌بان) - از تنظیمات Scout هم می‌خواند
        $scout_options = get_option('tscout_options', array());
        $theme_mapping = isset($scout_options['theme_meta_mapping']) ? $scout_options['theme_meta_mapping'] : array();
        
        // نگاشت پیش‌فرض سازگار با قالب کاربر (بر اساس گزارش)
        $theme_defaults = array(
            'musics_type' => 'musics_type',
            'artist_en' => 'artist_en',
            'song_en' => 'song_en',
            'art_name' => 'artist',       // کاربر: artist
            'track_name' => 'song',       // کاربر: song
            'music_txt' => 'music_text',  // کاربر: music_text
            'music128' => 'music128',
            'music320' => 'music320',
            'album' => 'album',
            'album128' => 'album128',
            'album320' => 'album320',
            'video480p' => 'video480',    // کاربر: video480
            'video720p' => 'video720',    // کاربر: video720
            'video1080p' => 'video1080',  // کاربر: video1080
            'fifu_image_alt' => 'fifu_image_alt',
            'fifu_image_url' => 'fifu_image_url',
        );
        
        // اگر در theme_mapping تنظیم شده باشد، از آن استفاده کن
        if (isset($theme_mapping[$key]) && !empty($theme_mapping[$key])) {
            $theme_defaults[$key] = $theme_mapping[$key];
        }
        
        if (!$this->is_active) {
            return isset($theme_defaults[$key]) ? $theme_defaults[$key] : $key;
        }
        
        // اگر ترانه‌یار فعال است، ابتدا از متد رسمی آن بخوان
        if (method_exists('TaranehYar_Settings', 'meta_key')) {
            $official = \TaranehYar_Settings::meta_key($key);
            // اگر رسمی خالی نبود، از آن استفاده کن، وگرنه از نگاشت تم
            if (!empty($official)) {
                // اگر کاربر در تنظیمات ترانه‌یار مقدار سفارشی وارد کرده باشد، همان برگردانده می‌شود
                // اما اگر مقدار رسمی برابر با کلید پیش‌فرض قدیمی باشد و نگاشت تم متفاوت باشد، نگاشت تم را ترجیح بده
                // برای سازگاری، اگر تنظیمات ترانه‌یار مقدار سفارشی دارد، همان را برگردان
                $configured = isset($this->settings['meta_key_' . $key]) ? $this->settings['meta_key_' . $key] : '';
                if (!empty($configured) && is_scalar($configured)) {
                    $san = sanitize_key($configured);
                    if ($san !== '') {
                        return $san;
                    }
                }
                // اگر تنظیم سفارشی ندارد و نگاشت تم متفاوت است، نگاشت تم را برگردان
                if (isset($theme_defaults[$key]) && $theme_defaults[$key] !== $key && $official === $key) {
                    return $theme_defaults[$key];
                }
                return $official;
            }
        }
        
        // Fallback به تنظیمات مستقیم ty_options
        $configured = isset($this->settings['meta_key_' . $key]) ? $this->settings['meta_key_' . $key] : '';
        $configured = is_scalar($configured) ? sanitize_key($configured) : '';
        if ($configured !== '') {
            return $configured;
        }
        
        return isset($theme_defaults[$key]) ? $theme_defaults[$key] : $key;
    }
    
    public function get_singer_taxonomy() {
        if (!$this->is_active) {
            return 'singer';
        }
        
        $configured = isset($this->settings['singer_taxonomy']) ? $this->settings['singer_taxonomy'] : 'singer';
        $taxonomy = is_scalar($configured) ? sanitize_key($configured) : '';
        return $taxonomy !== '' ? $taxonomy : 'singer';
    }
    
    public function get_download_host() {
        if (!$this->is_active) {
            return '';
        }
        return isset($this->settings['urldlhost']) ? $this->settings['urldlhost'] : '';
    }
    
    public function get_endpoint($type) {
        $map = array(
            'music' => 'urlfunction_music',
            'remix' => 'urlfunction_remix',
            'nohe' => 'urlfunction_nohe',
            'video' => 'urlfunction_video',
            'album' => 'urlfunction_album',
            'leech' => 'urlfunction_leech',
        );
        
        $key = isset($map[$type]) ? $map[$type] : 'urlfunction_music';
        
        if (!$this->is_active) {
            return '';
        }
        
        return isset($this->settings[$key]) ? $this->settings[$key] : '';
    }
    
    public function get_api_token() {
        if (!$this->is_active) {
            return '';
        }
        return isset($this->settings['apitoken']) ? $this->settings['apitoken'] : '';
    }
    
    /**
     * Get archive path using Taranehyar's logic if available
     */
    public function get_archive_path($root, $artist_en) {
        if ($this->is_active && class_exists('TaranehYar_Archive')) {
            return \TaranehYar_Archive::path($root, $artist_en);
        }
        
        // Fallback implementation
        $slug = $this->artist_slug($artist_en);
        $letter = substr($slug, 0, 1);
        if (!preg_match('/^[a-z0-9]$/', $letter)) {
            $letter = 'a';
        }
        return $root . '/' . $letter . '/' . $slug;
    }
    
    private function artist_slug($artist_en) {
        $artist_en = (string) $artist_en;
        if (function_exists('remove_accents')) {
            $artist_en = remove_accents($artist_en);
        }
        $slug = strtolower($artist_en);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 120);
        $slug = rtrim($slug, '-');
        return $slug !== '' ? $slug : 'artist';
    }
    
    /**
     * Build file URL using Taranehyar's logic if available
     */
    public function build_file_url($host, $archive_path, $filename) {
        if ($this->is_active && class_exists('TaranehYar_Album_Meta')) {
            return \TaranehYar_Album_Meta::file_url($host, $archive_path, $filename);
        }
        
        $base = rtrim((string) $host, '/');
        $path = trim((string) $archive_path, '/');
        $name = ltrim((string) $filename, '/');
        $url = $base;
        if ($path !== '') {
            $url .= '/' . $path;
        }
        return $url . '/' . $name;
    }
    
    /**
     * Create post using Taranehyar's structure - نسخه 1.0.6 سازگار با قالب کاربر
     */
    public function create_post($data) {
        // Validate required fields
        if (empty($data['title']) || empty($data['artist_fa']) || empty($data['track_fa'])) {
            return new \WP_Error('missing_data', 'اطلاعات ضروری برای ایجاد پست ناقص است.');
        }
        
        $post_data = array(
            'post_author' => get_current_user_id(),
            'post_status' => isset($data['status']) ? $data['status'] : 'draft',
            'post_type' => 'post',
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => isset($data['content']) ? wp_kses_post($data['content']) : '',
            'post_excerpt' => isset($data['excerpt']) ? sanitize_text_field($data['excerpt']) : '',
            'post_category' => isset($data['categories']) ? array_map('intval', $data['categories']) : array(),
        );
        
        if (!empty($data['slug'])) {
            $post_data['post_name'] = sanitize_title($data['slug']);
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Save meta - شامل تمام کلیدهای مورد نیاز قالب کاربر
        $meta_map = array(
            'art_name' => $data['artist_fa'],           // نگاشت به artist در قالب کاربر
            'track_name' => $data['track_fa'],          // نگاشت به song
            'artist_en' => isset($data['artist_en']) ? $data['artist_en'] : '',
            'song_en' => isset($data['track_en']) ? $data['track_en'] : '',
            'music128' => isset($data['music128']) ? $data['music128'] : '',
            'music320' => isset($data['music320']) ? $data['music320'] : '',
            'musics_type' => isset($data['musics_type']) ? $data['musics_type'] : 'mp3',
            'music_txt' => isset($data['music_txt']) ? $data['music_txt'] : '', // متن ترانه - نگاشت به music_text
            'fifu_image_url' => isset($data['cover_url']) ? $data['cover_url'] : '', // کاور برای FIFU
            'fifu_image_alt' => isset($data['title']) ? $data['title'] : '',
        );
        
        // ویدیو اگر وجود دارد
        if (isset($data['video480p'])) $meta_map['video480p'] = $data['video480p'];
        if (isset($data['video720p'])) $meta_map['video720p'] = $data['video720p'];
        if (isset($data['video1080p'])) $meta_map['video1080p'] = $data['video1080p'];
        
        foreach ($meta_map as $key => $value) {
            // حتی اگر خالی است برای music_txt و fifu هم ذخیره کن اگر کلید وجود دارد
            if ($key === 'music_txt' || $key === 'fifu_image_url' || $key === 'fifu_image_alt' || !empty($value)) {
                $meta_key = $this->get_meta_key($key);
                if ($key === 'fifu_image_url' || $key === 'fifu_image_alt') {
                    $meta_key = $key; // FIFU کلید ثابت دارد
                }
                if (!empty($meta_key)) {
                    update_post_meta($post_id, $meta_key, $value);
                }
            }
        }
        
        // کاور را به عنوان تصویر شاخص وردپرس هم دانلود و ست کن (مثل خود ترانه‌یار)
        if (!empty($data['cover_url'])) {
            $this->attach_cover_as_featured($data['cover_url'], $post_id);
        }
        
        // Additional meta
        if (isset($data['extra_meta']) && is_array($data['extra_meta'])) {
            foreach ($data['extra_meta'] as $k => $v) {
                update_post_meta($post_id, sanitize_key($k), $v);
            }
        }
        
        // Taxonomies - singer
        if (!empty($data['artist_fa'])) {
            $taxonomy = $this->get_singer_taxonomy();
            if (taxonomy_exists($taxonomy)) {
                wp_set_object_terms($post_id, $data['artist_fa'], $taxonomy);
            } else {
                // اگر تاکسونومی وجود ندارد، سعی کن ایجاد کنی یا لااقل به عنوان تگ ذخیره کن
                // برای سازگاری، نام خواننده را به تگ‌ها هم اضافه کن
            }
        }
        
        // Tags
        if (!empty($data['tags']) && is_array($data['tags'])) {
            wp_set_post_tags($post_id, $data['tags']);
        }
        
        // برای سازگاری با قالب‌های مختلف، کلیدهای اضافی هم ذخیره کن (هم فارسی هم انگلیسی)
        // مثلاً اگر قالب از 'artist' و 'song' استفاده می‌کند، هم art_name و هم artist را ذخیره کن
        $compat_map = array(
            'artist' => $data['artist_fa'],
            'song' => $data['track_fa'],
            'music_text' => isset($data['music_txt']) ? $data['music_txt'] : '',
            'video480' => isset($data['video480p']) ? $data['video480p'] : '',
            'video720' => isset($data['video720p']) ? $data['video720p'] : '',
            'video1080' => isset($data['video1080p']) ? $data['video1080p'] : '',
        );
        foreach ($compat_map as $k => $v) {
            if (!empty($v)) {
                update_post_meta($post_id, $k, $v);
            }
        }
        
        return $post_id;
    }
    
    /**
     * دانلود کاور و ست کردن به عنوان تصویر شاخص - مشابه ترانه‌یار
     */
    private function attach_cover_as_featured($cover_url, $post_id) {
        if (empty($cover_url)) return;
        
        // اعتبارسنجی URL
        if (!filter_var($cover_url, FILTER_VALIDATE_URL)) return;
        
        // استفاده از WordPress media sideload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // دانلود موقت
        $tmp = download_url($cover_url, 30);
        if (is_wp_error($tmp)) {
            return;
        }
        
        $file_array = array(
            'name' => basename(parse_url($cover_url, PHP_URL_PATH)),
            'tmp_name' => $tmp
        );
        
        // اگر نام فایل پسوند ندارد، jpg اضافه کن
        if (strpos($file_array['name'], '.') === false) {
            $file_array['name'] .= '.jpg';
        }
        
        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return;
        }
        
        set_post_thumbnail($post_id, $id);
    }
    
    /**
     * Check if post exists by title
     */
    public function post_exists_by_title($title) {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
            $title
        ));
        return $existing ? intval($existing) : false;
    }
    
    /**
     * Get post by meta
     */
    public function get_post_by_meta($meta_key, $meta_value) {
        $query = new \WP_Query(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future'),
            'meta_query' => array(
                array(
                    'key' => $meta_key,
                    'value' => $meta_value,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));
        
        if (!empty($query->posts)) {
            return $query->posts[0];
        }
        return false;
    }
}
