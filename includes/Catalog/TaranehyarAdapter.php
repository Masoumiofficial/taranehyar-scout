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
        if (!$this->is_active) {
            // Fallback to defaults
            $defaults = array(
                'musics_type' => 'musics_type',
                'artist_en' => 'artist_en',
                'song_en' => 'song_en',
                'art_name' => 'art_name',
                'track_name' => 'track_name',
                'music_txt' => 'music_txt',
                'music128' => 'music128',
                'music320' => 'music320',
                'album' => 'album',
                'album128' => 'album128',
                'album320' => 'album320',
                'video480p' => 'video480p',
                'video720p' => 'video720p',
                'video1080p' => 'video1080p',
                'fifu_image_alt' => 'fifu_image_alt',
            );
            return isset($defaults[$key]) ? $defaults[$key] : $key;
        }
        
        if (method_exists('TaranehYar_Settings', 'meta_key')) {
            return \TaranehYar_Settings::meta_key($key);
        }
        
        // Fallback
        $configured = isset($this->settings['meta_key_' . $key]) ? $this->settings['meta_key_' . $key] : '';
        $configured = is_scalar($configured) ? sanitize_key($configured) : '';
        return $configured !== '' ? $configured : $key;
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
     * Create post using Taranehyar's structure
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
        
        // Save meta
        $meta_map = array(
            'art_name' => $data['artist_fa'],
            'track_name' => $data['track_fa'],
            'artist_en' => isset($data['artist_en']) ? $data['artist_en'] : '',
            'song_en' => isset($data['track_en']) ? $data['track_en'] : '',
            'music128' => isset($data['music128']) ? $data['music128'] : '',
            'music320' => isset($data['music320']) ? $data['music320'] : '',
            'musics_type' => isset($data['musics_type']) ? $data['musics_type'] : 'mp3',
        );
        
        foreach ($meta_map as $key => $value) {
            if (!empty($value)) {
                $meta_key = $this->get_meta_key($key);
                if (!empty($meta_key)) {
                    update_post_meta($post_id, $meta_key, $value);
                }
            }
        }
        
        // Additional meta
        if (isset($data['extra_meta']) && is_array($data['extra_meta'])) {
            foreach ($data['extra_meta'] as $k => $v) {
                update_post_meta($post_id, sanitize_key($k), $v);
            }
        }
        
        // Taxonomies
        if (!empty($data['artist_fa'])) {
            $taxonomy = $this->get_singer_taxonomy();
            if (taxonomy_exists($taxonomy)) {
                wp_set_object_terms($post_id, $data['artist_fa'], $taxonomy);
            }
        }
        
        // Tags
        if (!empty($data['tags']) && is_array($data['tags'])) {
            wp_set_post_tags($post_id, $data['tags']);
        }
        
        return $post_id;
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
