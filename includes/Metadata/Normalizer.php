<?php
namespace TaranehyarScout\Metadata;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\Sanitizer;

class Normalizer {
    
    /**
     * Normalize raw provider data to internal standard structure
     */
    public static function normalize($raw_data, $provider_id) {
        if (!is_array($raw_data)) {
            return new \WP_Error('invalid_data', 'داده نامعتبر');
        }
        
        // متن ترانه ممکن است در فیلدهای مختلف باشد
        $lyrics_raw = '';
        if (isset($raw_data['lyrics']) && !empty($raw_data['lyrics'])) {
            $lyrics_raw = $raw_data['lyrics'];
        } elseif (isset($raw_data['music_text']) && !empty($raw_data['music_text'])) {
            $lyrics_raw = $raw_data['music_text'];
        } elseif (isset($raw_data['music_txt']) && !empty($raw_data['music_txt'])) {
            $lyrics_raw = $raw_data['music_txt'];
        }
        
        $normalized = array(
            // Required fields
            'external_id' => isset($raw_data['external_id']) ? sanitize_text_field($raw_data['external_id']) : '',
            'provider' => sanitize_key($provider_id),
            'provider_hash' => '',
            
            // Artist - بهبود: اگر artist_fa خالی است ولی title_fa شامل نام خواننده است، جدا کن
            'artist_en' => isset($raw_data['artist_en']) ? Sanitizer::sanitize_artist_name($raw_data['artist_en']) : '',
            'artist_fa' => isset($raw_data['artist_fa']) ? sanitize_text_field($raw_data['artist_fa']) : '',
            'artist_normalized' => '',
            
            // Title - بهبود: عنوان فارسی و انگلیسی جدا
            'title' => isset($raw_data['title']) ? sanitize_text_field($raw_data['title']) : '',
            'title_fa' => isset($raw_data['title_fa']) ? sanitize_text_field($raw_data['title_fa']) : '',
            'title_normalized' => '',
            'title_en' => isset($raw_data['title_en']) ? sanitize_text_field($raw_data['title_en']) : '',
            
            // Album
            'album' => isset($raw_data['album']) ? sanitize_text_field($raw_data['album']) : '',
            
            // Type
            'track_type' => isset($raw_data['type']) ? self::normalize_type($raw_data['type']) : 'single',
            'is_remix' => isset($raw_data['is_remix']) ? (bool) $raw_data['is_remix'] : false,
            'is_live' => isset($raw_data['is_live']) ? (bool) $raw_data['is_live'] : false,
            'is_collab' => isset($raw_data['is_collab']) ? (bool) $raw_data['is_collab'] : false,
            
            // Dates
            'year' => isset($raw_data['year']) ? intval($raw_data['year']) : null,
            'duration' => isset($raw_data['duration']) ? intval($raw_data['duration']) : null,
            
            // Genre, language
            'genre' => isset($raw_data['genre']) ? sanitize_text_field($raw_data['genre']) : '',
            'language' => isset($raw_data['language']) ? sanitize_text_field($raw_data['language']) : 'fa',
            
            // URLs
            'source_url' => isset($raw_data['source_url']) ? esc_url_raw($raw_data['source_url']) : '',
            'cover_url' => isset($raw_data['cover_url']) ? esc_url_raw($raw_data['cover_url']) : '',
            'audio_url_128' => isset($raw_data['audio_url_128']) ? esc_url_raw($raw_data['audio_url_128']) : '',
            'audio_url_320' => isset($raw_data['audio_url_320']) ? esc_url_raw($raw_data['audio_url_320']) : '',
            'video_url_480' => isset($raw_data['video_url_480']) ? esc_url_raw($raw_data['video_url_480']) : '',
            'video_url_720' => isset($raw_data['video_url_720']) ? esc_url_raw($raw_data['video_url_720']) : '',
            'video_url_1080' => isset($raw_data['video_url_1080']) ? esc_url_raw($raw_data['video_url_1080']) : '',
            
            // Metadata
            'metadata_json' => '',
            'confidence' => isset($raw_data['confidence']) ? floatval($raw_data['confidence']) : 0.8,
            'license_status' => 'needs_review',
            
            // Extra
            'slug' => isset($raw_data['slug']) ? sanitize_title($raw_data['slug']) : '',
            'lyrics' => !empty($lyrics_raw) ? sanitize_textarea_field($lyrics_raw) : '',
            'mood' => isset($raw_data['mood']) ? sanitize_text_field($raw_data['mood']) : '',
        );
        
        // بهبود: اگر artist_fa خالی است ولی title_fa شامل نام خواننده است، سعی کن جدا کنی
        // این منطق برای مواردی مثل "محسن یگانه تو خوب" که کل h1 است
        if (empty($normalized['artist_fa']) && !empty($normalized['title_fa'])) {
            // اگر title شامل دو بخش فارسی باشد، احتمالاً بخش اول نام خواننده است
            // برای سادگی، اگر title_fa بیش از 2 کلمه دارد، اولین 2 کلمه را به عنوان نام خواننده در نظر بگیر اگر با artist_en همخوانی ندارد
            // اما بهتر است این کار در Provider انجام شود، اینجا فقط fallback
        }
        
        // بهبود: جدا کردن artist_en از title_en اگر عنوان انگلیسی شامل نام هنرمند است
        // مثال: title_en = "Mohsen Yeganeh To Khoob", artist_en = "Mohsen Yeganeh" -> title_en باید "To Khoob" شود
        if (!empty($normalized['artist_en']) && !empty($normalized['title_en'])) {
            $artist_en_lower = strtolower($normalized['artist_en']);
            $title_en_lower = strtolower($normalized['title_en']);
            if (strpos($title_en_lower, $artist_en_lower) === 0) {
                $remaining = trim(substr($normalized['title_en'], strlen($normalized['artist_en'])));
                // حذف خط تیره یا فاصله اضافی
                $remaining = ltrim($remaining, " -");
                if (!empty($remaining)) {
                    $normalized['title_en'] = $remaining;
                }
            }
        }
        
        // اگر title خالی است ولی title_en داریم، از title_en استفاده کن
        if (empty($normalized['title']) && !empty($normalized['title_en'])) {
            $normalized['title'] = $normalized['title_en'];
        }
        // اگر title خالی است ولی title_fa داریم، از title_fa استفاده کن
        if (empty($normalized['title']) && !empty($normalized['title_fa'])) {
            $normalized['title'] = $normalized['title_fa'];
        }
        
        // Generate normalized fields
        $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['artist_en']);
        // اگر artist_en خالی است ولی artist_fa داریم، از artist_fa برای نرمال استفاده کن
        if (empty($normalized['artist_normalized']) && !empty($normalized['artist_fa'])) {
            $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['artist_fa']);
        }
        
        $normalized['title_normalized'] = Sanitizer::normalize_string($normalized['title']);
        
        // If title_en exists, use it for normalized if title is Persian
        if (!empty($normalized['title_en']) && (empty($normalized['title_normalized']) || preg_match('/[\x{0600}-\x{06FF}]/u', $normalized['title']))) {
            $en_norm = Sanitizer::normalize_string($normalized['title_en']);
            if (!empty($en_norm)) {
                $normalized['title_normalized'] = $en_norm;
            }
        }
        
        // If artist_en is empty but we have slug, try to extract
        if (empty($normalized['artist_en']) && !empty($normalized['slug'])) {
            $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['slug']);
        }
        
        // Provider hash for deduplication
        $normalized['provider_hash'] = md5($normalized['provider'] . '|' . $normalized['external_id']);
        
        // Detect remix/live/collab from title if not already set
        if (!$normalized['is_remix']) {
            $normalized['is_remix'] = self::detect_remix($normalized['title'] . ' ' . $normalized['slug']);
        }
        if (!$normalized['is_live']) {
            $normalized['is_live'] = self::detect_live($normalized['title'] . ' ' . $normalized['slug']);
        }
        if (!$normalized['is_collab']) {
            $normalized['is_collab'] = self::detect_collab($normalized['title'] . ' ' . $normalized['slug']);
        }
        
        // Build metadata JSON
        $metadata = array(
            'original_title' => $normalized['title'],
            'title_fa' => $normalized['title_fa'],
            'title_en' => $normalized['title_en'],
            'artist_fa' => $normalized['artist_fa'],
            'genre' => $normalized['genre'],
            'mood' => $normalized['mood'],
            'lyrics' => $normalized['lyrics'],
            'slug' => $normalized['slug'],
            'source' => array(
                'provider' => $provider_id,
                'url' => $normalized['source_url'],
                'retrieved_at' => current_time('mysql'),
            ),
            'confidence_factors' => array(
                'has_cover' => !empty($normalized['cover_url']),
                'has_audio_128' => !empty($normalized['audio_url_128']),
                'has_audio_320' => !empty($normalized['audio_url_320']),
                'has_artist_en' => !empty($normalized['artist_en']),
                'has_title' => !empty($normalized['title']),
            )
        );
        
        $normalized['metadata_json'] = wp_json_encode($metadata, JSON_UNESCAPED_UNICODE);
        
        // Calculate confidence
        $normalized['confidence'] = self::calculate_confidence($normalized, $metadata);
        
        // Determine license status based on provider policy
        $normalized['license_status'] = self::determine_license_status($provider_id, $normalized);
        
        return $normalized;
    }
    
    private static function normalize_type($type) {
        $type = strtolower(trim((string) $type));
        $mapping = array(
            'single' => 'single',
            'track' => 'single',
            'song' => 'single',
            'album' => 'album',
            'ep' => 'ep',
            'remix' => 'remix',
            'live' => 'live',
            'concert' => 'live',
            'collab' => 'collab',
            'collaboration' => 'collab',
            'feat' => 'collab',
            'podcast' => 'podcast',
            'video' => 'single',
            'music-video' => 'single',
        );
        
        return isset($mapping[$type]) ? $mapping[$type] : 'single';
    }
    
    private static function detect_remix($text) {
        $text = strtolower($text);
        $keywords = array('remix', 'ریمیکس', 'mix', 'edit', 'version');
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private static function detect_live($text) {
        $text = strtolower($text);
        $keywords = array('live', 'concert', 'اجرای زنده', 'زنده', 'unplugged', 'acoustic');
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private static function detect_collab($text) {
        $text = strtolower($text);
        $keywords = array('ft.', 'ft ', 'feat', 'featuring', 'with', ' & ', ' and ', ' x ', ' vs ');
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private static function calculate_confidence($normalized, $metadata) {
        $score = 0;
        $max = 0;
        
        // Has title
        $max += 20;
        if (!empty($normalized['title'])) $score += 20;
        
        // Has artist
        $max += 20;
        if (!empty($normalized['artist_en']) || !empty($normalized['artist_fa'])) $score += 20;
        
        // Has cover
        $max += 15;
        if (!empty($normalized['cover_url'])) $score += 15;
        
        // Has audio
        $max += 25;
        if (!empty($normalized['audio_url_320'])) $score += 25;
        elseif (!empty($normalized['audio_url_128'])) $score += 15;
        
        // Has source URL
        $max += 10;
        if (!empty($normalized['source_url'])) $score += 10;
        
        // Has genre/year
        $max += 10;
        if (!empty($normalized['genre'])) $score += 5;
        if (!empty($normalized['year'])) $score += 5;
        
        return $max > 0 ? round(($score / $max) * 100, 1) : 0;
    }
    
    private static function determine_license_status($provider_id, $normalized) {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies']) ? $options['provider_policies'] : array();
        
        if (isset($policies[$provider_id]['license_status'])) {
            return $policies[$provider_id]['license_status'];
        }
        
        // Default policy: needs review for all providers
        // Only mock provider is verified by default for testing
        if ($provider_id === 'mock') {
            return 'verified';
        }
        
        return 'needs_review';
    }
    
    /**
     * Normalize artist name for matching
     */
    public static function normalize_artist_for_matching($artist) {
        return Sanitizer::normalize_string($artist);
    }
    
    /**
     * Normalize title for matching
     */
    public static function normalize_title_for_matching($title) {
        $title = Sanitizer::normalize_string($title);
        // Remove common suffixes like (320), [128], Remix, Live, etc for better matching
        $title = preg_replace('/\s*[\(\[]\s*(320|128|remix|live|acoustic|original|version)[^\)\]]*[\)\]]/i', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }
}
