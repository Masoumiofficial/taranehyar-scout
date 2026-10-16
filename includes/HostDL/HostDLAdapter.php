<?php
namespace TaranehyarScout\HostDL;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;
use TaranehyarScout\Catalog\TaranehyarAdapter;

class HostDLAdapter {
    
    private $taranehyar_adapter;
    
    public function __construct() {
        $this->taranehyar_adapter = TaranehyarAdapter::get_instance();
    }
    
    /**
     * Check if HostDL is configured
     */
    public function is_configured() {
        $host = $this->taranehyar_adapter->get_download_host();
        $token = $this->taranehyar_adapter->get_api_token();
        $endpoint = $this->taranehyar_adapter->get_endpoint('music');
        
        return !empty($host) && !empty($token) && !empty($endpoint);
    }
    
    /**
     * Get HostDL status
     */
    public function get_status() {
        return array(
            'configured' => $this->is_configured(),
            'host' => $this->taranehyar_adapter->get_download_host(),
            'has_token' => !empty($this->taranehyar_adapter->get_api_token()),
            'endpoints' => array(
                'music' => $this->taranehyar_adapter->get_endpoint('music'),
                'remix' => $this->taranehyar_adapter->get_endpoint('remix'),
                'video' => $this->taranehyar_adapter->get_endpoint('video'),
                'album' => $this->taranehyar_adapter->get_endpoint('album'),
            )
        );
    }
    
    /**
     * Transfer file to HostDL
     * @param array $data Transfer data
     * @param string $type Content type (music, remix, video, album)
     * @return true|WP_Error
     */
    public function transfer($data, $type = 'music') {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'هاست دانلود پیکربندی نشده است.');
        }
        
        // Validate URLs in data
        $url_fields = array('link320', 'link128', 'link1080', 'link720', 'link480', 'cover');
        foreach ($url_fields as $field) {
            if (!empty($data[$field])) {
                $valid = UrlValidator::validate($data[$field], true);
                if (is_wp_error($valid)) {
                    return new \WP_Error('invalid_url', "آدرس {$field} نامعتبر است: " . $valid->get_error_message());
                }
            }
        }
        
        // Try to use Taranehyar's HTTP class if available
        if (class_exists('TaranehYar_Http')) {
            $endpoint_key = $this->get_endpoint_key($type);
            $result = \TaranehYar_Http::post_to_dlhost($endpoint_key, $data);
            
            if (is_wp_error($result)) {
                Logger::error('HostDL transfer failed via TaranehYar_Http', array('type' => $type, 'error' => $result->get_error_message()));
            } else {
                Logger::info('HostDL transfer succeeded', array('type' => $type));
            }
            
            return $result;
        }
        
        // Fallback: direct implementation
        return $this->direct_transfer($data, $type);
    }
    
    private function direct_transfer($data, $type) {
        $endpoint = $this->taranehyar_adapter->get_endpoint($type);
        if (empty($endpoint)) {
            $endpoint = $this->taranehyar_adapter->get_endpoint('music');
        }
        
        $valid = UrlValidator::validate($endpoint);
        if (is_wp_error($valid)) {
            return $valid;
        }
        
        $token = $this->taranehyar_adapter->get_api_token();
        $data['token'] = $token;
        
        $args = array(
            'body' => $data,
            'timeout' => 300,
            'sslverify' => true,
        );
        
        $response = wp_remote_post($endpoint, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('http_error', "هاست دانلود خطا برگرداند (HTTP {$code})");
        }
        
        return true;
    }
    
    private function get_endpoint_key($type) {
        $map = array(
            'music' => 'urlfunction_music',
            'single' => 'urlfunction_music',
            'remix' => 'urlfunction_remix',
            'nohe' => 'urlfunction_nohe',
            'video' => 'urlfunction_video',
            'album' => 'urlfunction_album',
            'leech' => 'urlfunction_leech',
        );
        return isset($map[$type]) ? $map[$type] : 'urlfunction_music';
    }
    
    /**
     * Validate file before transfer - نسخه سبک برای جلوگیری از خطای آپلود
     * HEAD ممکن است توسط mybia2music مسدود شود، پس فقط extension را چک می‌کنیم
     */
    public function validate_file($url, $allowed_types = array('mp3')) {
        $valid = UrlValidator::validate($url);
        if (is_wp_error($valid)) {
            return $valid;
        }
        
        // Check extension - فقط همین
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        if (!empty($allowed_types) && !in_array($ext, $allowed_types, true)) {
            // اگر پسوند mp3 نیست ولی URL شامل .mp3 است (با query)، قبول کن
            if (stripos($url, '.mp3') === false) {
                return new \WP_Error('invalid_ext', "پسوند فایل ({$ext}) مجاز نیست. مجاز: " . implode(', ', $allowed_types));
            }
        }
        
        // HEAD اختیاری - اگر 200 نبود، فقط لاگ می‌کنیم و اجازه می‌دهیم
        $head = wp_remote_head($url, array('timeout' => 10, 'redirection' => 2));
        if (!is_wp_error($head)) {
            $code = wp_remote_retrieve_response_code($head);
            $headers = wp_remote_retrieve_headers($head);
            $content_length = isset($headers['content-length']) ? intval($headers['content-length']) : 0;
            $max_size = 150 * 1024 * 1024; // 150MB
            if ($content_length > $max_size) {
                return new \WP_Error('file_too_large', "حجم فایل بیش از حد مجاز است ({$content_length} بایت)");
            }
            if ($code !== 200) {
                Logger::warning('HEAD non-200 but allowing', array('url' => $url, 'code' => $code));
            }
        } else {
            Logger::info('HEAD failed but allowing', array('url' => $url, 'error' => $head->get_error_message()));
        }
        
        return true;
    }
    
    /**
     * Build transfer data for music - سازگار با HostDL 1.0.6 و آدرس https://dl.ahangban.ir/
     */
    public function build_music_transfer_data($discovery, $artist_en, $track_en, $file_name) {
        // برای نام فایل، از track_en انگلیسی استفاده کن اگر موجود است، وگرنه فارسی
        $safe_artist = !empty($artist_en) ? $artist_en : ($discovery['artist_en'] ?? 'artist');
        $safe_track = !empty($track_en) ? $track_en : ($discovery['title'] ?? 'track');
        
        // اگر title_en جدا شده داریم، از آن برای song_en استفاده کن
        if (!empty($discovery['title_en'])) {
            $safe_track = $discovery['title_en'];
        } elseif (!empty($discovery['title'])) {
            // اگر title شامل نام هنرمند است، سعی کن جدا کنی
            $safe_track = $discovery['title'];
        }
        
        $archive_path = $this->taranehyar_adapter->get_archive_path('music', $safe_artist);
        if (!$archive_path) {
            $archive_path = 'music/m/' . sanitize_title($safe_artist);
        }
        
        $data = array(
            'file_name' => $file_name,
            'artist_en' => $safe_artist,
            'song_en' => $safe_track,
            'link320' => isset($discovery['audio_url_320']) ? $discovery['audio_url_320'] : '',
            'link128' => isset($discovery['audio_url_128']) ? $discovery['audio_url_128'] : '',
            'cover' => isset($discovery['cover_url']) ? $discovery['cover_url'] : '',
            'tag_site' => $this->get_site_tag(),
            'archive_path' => $archive_path,
            'archive_root' => 'music',
        );
        
        return $data;
    }
    
    /**
     * Build transfer data for video
     */
    public function build_video_transfer_data($discovery, $artist_en, $track_en, $file_name) {
        $safe_artist = !empty($artist_en) ? $artist_en : ($discovery['artist_en'] ?? 'artist');
        $archive_path = $this->taranehyar_adapter->get_archive_path('music-video', $safe_artist);
        
        return array(
            'file_name' => $file_name,
            'artist_en' => $safe_artist,
            'song_en' => $track_en,
            'link1080' => $discovery['video_url_1080'] ?? '',
            'link720' => $discovery['video_url_720'] ?? '',
            'link480' => $discovery['video_url_480'] ?? '',
            'link320' => $discovery['audio_url_320'] ?? '',
            'cover' => $discovery['cover_url'] ?? '',
            'tag_site' => $this->get_site_tag(),
            'archive_path' => $archive_path,
        );
    }
    
    private function get_site_tag() {
        $adapter_settings = $this->taranehyar_adapter->get_settings();
        if (!empty($adapter_settings['namesite2'])) {
            return $adapter_settings['namesite2'];
        }
        if (!empty($adapter_settings['urlsite'])) {
            return $adapter_settings['urlsite'];
        }
        return get_bloginfo('name');
    }
}
