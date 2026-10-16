<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Logging\Logger;

class MockProvider implements ProviderInterface {
    
    public function get_id() {
        return 'mock';
    }
    
    public function get_name() {
        return 'Mock Provider';
    }
    
    public function get_label() {
        return 'ارائه‌دهنده آزمایشی';
    }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $enabled = isset($options['enable_mock_provider']) ? (bool) $options['enable_mock_provider'] : false;
        
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (isset($policies['enabled'])) {
            $enabled = (bool) $policies['enabled'];
        }
        
        return $enabled;
    }
    
    public function get_status() {
        return array(
            'id' => $this->get_id(),
            'name' => $this->get_name(),
            'label' => $this->get_label(),
            'available' => $this->is_available(),
            'last_check' => current_time('mysql'),
            'last_success' => current_time('mysql'),
            'rate_limit' => 0,
            'requires_auth' => false,
            'supports_search' => true,
            'supports_types' => array('single', 'album', 'remix', 'video', 'live', 'collab'),
        );
    }
    
    public function validate_config() {
        return true;
    }
    
    public function search_artist($artist_en, $options = array()) {
        $artist_en = trim((string) $artist_en);
        if (empty($artist_en)) {
            return new \WP_Error('empty_artist', 'نام هنرمند خالی است.');
        }
        
        $limit = isset($options['limit']) ? intval($options['limit']) : 10;
        $limit = max(1, min(50, $limit));
        
        Logger::info('Mock search for artist: ' . $artist_en);
        
        // Generate mock data
        $tracks = array();
        $types = array('single', 'remix', 'live', 'album', 'collab');
        
        for ($i = 1; $i <= $limit; $i++) {
            $type = $types[array_rand($types)];
            $tracks[] = array(
                'external_id' => 'mock_' . md5($artist_en . $i),
                'provider' => $this->get_id(),
                'source_url' => 'https://example.com/mock/' . sanitize_title($artist_en) . '/track-' . $i,
                'slug' => sanitize_title($artist_en) . '-track-' . $i,
                'title' => $artist_en . ' Track ' . $i,
                'title_fa' => 'آهنگ ' . $i . ' از ' . $artist_en,
                'artist_en' => $artist_en,
                'artist_fa' => $artist_en,
                'type' => $type,
                'cover_url' => 'https://via.placeholder.com/500x500?text=' . urlencode($artist_en . ' ' . $i),
                'audio_url_128' => 'https://example.com/audio/' . sanitize_title($artist_en) . '-track-' . $i . '-128.mp3',
                'audio_url_320' => 'https://example.com/audio/' . sanitize_title($artist_en) . '-track-' . $i . '-320.mp3',
                'genre' => 'Pop',
                'year' => 2020 + ($i % 6),
                'is_remix' => $type === 'remix' ? 1 : 0,
                'is_live' => $type === 'live' ? 1 : 0,
                'is_collab' => $type === 'collab' ? 1 : 0,
            );
        }
        
        return $tracks;
    }
    
    public function get_track_details($source_url) {
        // Parse mock URL
        $path = parse_url($source_url, PHP_URL_PATH);
        $parts = explode('/', trim($path, '/'));
        $track_name = end($parts);
        
        return array(
            'cover_url' => 'https://via.placeholder.com/500x500?text=' . urlencode($track_name),
            'audio_url_128' => 'https://example.com/audio/' . $track_name . '-128.mp3',
            'audio_url_320' => 'https://example.com/audio/' . $track_name . '-320.mp3',
            'genre' => 'Pop',
            'lyrics' => 'Mock lyrics for ' . $track_name,
        );
    }
}
