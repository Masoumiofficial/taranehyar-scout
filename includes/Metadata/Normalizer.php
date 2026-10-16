<?php
namespace TaranehyarScout\Metadata;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\Sanitizer;

class Normalizer {
    
    public static function normalize($raw_data, $provider_id) {
        if (!is_array($raw_data)) {
            return new \WP_Error('invalid_data', 'داده نامعتبر');
        }
        
        $lyrics_raw = '';
        if (isset($raw_data['lyrics']) && !empty($raw_data['lyrics'])) $lyrics_raw = $raw_data['lyrics'];
        elseif (isset($raw_data['music_text']) && !empty($raw_data['music_text'])) $lyrics_raw = $raw_data['music_text'];
        elseif (isset($raw_data['music_txt']) && !empty($raw_data['music_txt'])) $lyrics_raw = $raw_data['music_txt'];
        
        $artist_fa_raw = isset($raw_data['artist_fa']) ? trim($raw_data['artist_fa']) : '';
        $artist_en_raw = isset($raw_data['artist_en']) ? trim($raw_data['artist_en']) : '';
        $title_fa_raw = isset($raw_data['title_fa']) ? trim($raw_data['title_fa']) : '';
        $title_en_raw = isset($raw_data['title_en']) ? trim($raw_data['title_en']) : '';
        
        // اگر artist_fa انگلیسی است و lyricist فارسی داریم، از lyricist استفاده کن
        if (!empty($artist_fa_raw) && !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa_raw)) {
            if (!empty($raw_data['lyricist']) && preg_match('/[\x{0600}-\x{06FF}]/u', $raw_data['lyricist'])) {
                $artist_fa_raw = trim($raw_data['lyricist']);
            } elseif (!empty($raw_data['credits']['lyricist']) && preg_match('/[\x{0600}-\x{06FF}]/u', $raw_data['credits']['lyricist'])) {
                $artist_fa_raw = trim($raw_data['credits']['lyricist']);
            }
        }
        
        // پاکسازی عنوان فارسی
        if (!empty($title_fa_raw)) {
            $title_fa_raw = self::clean_fa_title_static($title_fa_raw, $artist_fa_raw);
        }
        
        // اگر هنوز title_fa شامل artist_fa است، دوباره تمیز کن (مثل "محسن یگانه زندگیم سوخت")
        if (!empty($title_fa_raw) && !empty($artist_fa_raw) && preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa_raw)) {
            if (mb_strpos($title_fa_raw, $artist_fa_raw) === 0) {
                $rem = trim(mb_substr($title_fa_raw, mb_strlen($artist_fa_raw)));
                $rem = ltrim($rem, " -–—");
                if (!empty($rem)) $title_fa_raw = $rem;
            }
            // حالت "محسن یگانه - زندگیم سوخت" یا "محسن یگانه زندگیم سوخت" با فاصله
            if (mb_strpos($title_fa_raw, $artist_fa_raw . ' ') === 0) {
                $rem = trim(mb_substr($title_fa_raw, mb_strlen($artist_fa_raw)));
                $rem = ltrim($rem, " -–—");
                if (!empty($rem) && mb_strlen($rem) < 100) $title_fa_raw = $rem;
            }
        }
        
        // پاکسازی عنوان انگلیسی
        if (!empty($title_en_raw) && !empty($artist_en_raw)) {
            if (stripos($title_en_raw, $artist_en_raw) === 0) {
                $rem = trim(substr($title_en_raw, strlen($artist_en_raw)));
                $rem = ltrim($rem, " -–—");
                if (!empty($rem)) $title_en_raw = $rem;
            }
        }
        
        $normalized = array(
            'external_id' => isset($raw_data['external_id']) ? sanitize_text_field($raw_data['external_id']) : '',
            'provider' => sanitize_key($provider_id),
            'provider_hash' => '',
            'artist_en' => !empty($artist_en_raw) ? Sanitizer::sanitize_artist_name($artist_en_raw) : '',
            'artist_fa' => !empty($artist_fa_raw) ? sanitize_text_field($artist_fa_raw) : '',
            'artist_normalized' => '',
            'title' => isset($raw_data['title']) ? sanitize_text_field($raw_data['title']) : '',
            'title_fa' => !empty($title_fa_raw) ? sanitize_text_field($title_fa_raw) : (isset($raw_data['title_fa']) ? sanitize_text_field($raw_data['title_fa']) : ''),
            'title_normalized' => '',
            'title_en' => !empty($title_en_raw) ? sanitize_text_field($title_en_raw) : (isset($raw_data['title_en']) ? sanitize_text_field($raw_data['title_en']) : ''),
            'album' => isset($raw_data['album']) ? sanitize_text_field($raw_data['album']) : '',
            'track_type' => isset($raw_data['type']) ? self::normalize_type($raw_data['type']) : 'single',
            'is_remix' => isset($raw_data['is_remix']) ? (bool) $raw_data['is_remix'] : false,
            'is_live' => isset($raw_data['is_live']) ? (bool) $raw_data['is_live'] : false,
            'is_collab' => isset($raw_data['is_collab']) ? (bool) $raw_data['is_collab'] : false,
            'year' => isset($raw_data['year']) ? intval($raw_data['year']) : null,
            'duration' => isset($raw_data['duration']) ? intval($raw_data['duration']) : null,
            'genre' => isset($raw_data['genre']) ? sanitize_text_field($raw_data['genre']) : '',
            'language' => isset($raw_data['language']) ? sanitize_text_field($raw_data['language']) : 'fa',
            'source_url' => isset($raw_data['source_url']) ? esc_url_raw($raw_data['source_url']) : '',
            'cover_url' => isset($raw_data['cover_url']) ? esc_url_raw($raw_data['cover_url']) : '',
            'audio_url_128' => isset($raw_data['audio_url_128']) ? esc_url_raw($raw_data['audio_url_128']) : '',
            'audio_url_320' => isset($raw_data['audio_url_320']) ? esc_url_raw($raw_data['audio_url_320']) : '',
            'video_url_480' => isset($raw_data['video_url_480']) ? esc_url_raw($raw_data['video_url_480']) : '',
            'video_url_720' => isset($raw_data['video_url_720']) ? esc_url_raw($raw_data['video_url_720']) : '',
            'video_url_1080' => isset($raw_data['video_url_1080']) ? esc_url_raw($raw_data['video_url_1080']) : '',
            'metadata_json' => '',
            'confidence' => isset($raw_data['confidence']) ? floatval($raw_data['confidence']) : 0.8,
            'license_status' => 'needs_review',
            'slug' => isset($raw_data['slug']) ? sanitize_title($raw_data['slug']) : '',
            'lyrics' => !empty($lyrics_raw) ? self::sanitize_lyrics($lyrics_raw) : '',
            'mood' => isset($raw_data['mood']) ? sanitize_text_field($raw_data['mood']) : '',
        );
        
        if (empty($normalized['title']) && !empty($normalized['title_en'])) $normalized['title'] = $normalized['title_en'];
        if (empty($normalized['title']) && !empty($normalized['title_fa'])) $normalized['title'] = $normalized['title_fa'];
        
        $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['artist_en']);
        if (empty($normalized['artist_normalized']) && !empty($normalized['artist_fa'])) {
            $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['artist_fa']);
        }
        $normalized['title_normalized'] = Sanitizer::normalize_string($normalized['title']);
        if (!empty($normalized['title_en']) && (empty($normalized['title_normalized']) || preg_match('/[\x{0600}-\x{06FF}]/u', $normalized['title']))) {
            $en_norm = Sanitizer::normalize_string($normalized['title_en']);
            if (!empty($en_norm)) $normalized['title_normalized'] = $en_norm;
        }
        if (empty($normalized['artist_en']) && !empty($normalized['slug'])) {
            $normalized['artist_normalized'] = Sanitizer::normalize_string($normalized['slug']);
        }
        $normalized['provider_hash'] = md5($normalized['provider'] . '|' . $normalized['external_id']);
        if (!$normalized['is_remix']) $normalized['is_remix'] = self::detect_remix($normalized['title'] . ' ' . $normalized['slug']);
        if (!$normalized['is_live']) $normalized['is_live'] = self::detect_live($normalized['title'] . ' ' . $normalized['slug']);
        if (!$normalized['is_collab']) $normalized['is_collab'] = self::detect_collab($normalized['title'] . ' ' . $normalized['slug']);
        
        $metadata = array(
            'original_title' => $normalized['title'],
            'title_fa' => $normalized['title_fa'],
            'title_en' => $normalized['title_en'],
            'artist_fa' => $normalized['artist_fa'],
            'artist_en' => $normalized['artist_en'],
            'genre' => $normalized['genre'],
            'mood' => $normalized['mood'],
            'lyrics' => $normalized['lyrics'],
            'slug' => $normalized['slug'],
            'lyricist' => isset($raw_data['lyricist']) ? sanitize_text_field($raw_data['lyricist']) : '',
            'composer' => isset($raw_data['composer']) ? sanitize_text_field($raw_data['composer']) : '',
            'arranger' => isset($raw_data['arranger']) ? sanitize_text_field($raw_data['arranger']) : '',
            'mix_master' => isset($raw_data['mix_master']) ? sanitize_text_field($raw_data['mix_master']) : '',
            'credits' => isset($raw_data['credits']) ? $raw_data['credits'] : array(),
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
        $normalized['confidence'] = self::calculate_confidence($normalized, $metadata);
        $normalized['license_status'] = self::determine_license_status($provider_id, $normalized);
        return $normalized;
    }
    
    private static function clean_fa_title_static($fa_title, $artist_fa) {
        $fa_title = trim($fa_title);
        if (empty($fa_title)) return '';
        $prefixes = array('دانلود آهنگ جدید ', 'دانلود آهنگ ', 'دانلود ');
        foreach ($prefixes as $pre) {
            if (mb_strpos($fa_title, $pre) === 0) {
                $fa_title = trim(mb_substr($fa_title, mb_strlen($pre)));
                break;
            }
        }
        if (mb_strpos($fa_title, ' از ') !== false) {
            $parts = explode(' از ', $fa_title);
            $first = trim($parts[0]);
            if (!empty($first) && mb_strlen($first) < 100) {
                if (!empty($artist_fa) && mb_strpos($fa_title, $artist_fa) !== false) {
                    $fa_title = $first;
                } else {
                    $second = trim($parts[1] ?? '');
                    if (!empty($second) && preg_match('/[\x{0600}-\x{06FF}]/u', $second) && mb_strlen($second) < 50) {
                        $fa_title = $first;
                    }
                }
            }
        }
        if (!empty($artist_fa)) {
            if (mb_strpos($fa_title, $artist_fa) === 0) {
                $remaining = trim(mb_substr($fa_title, mb_strlen($artist_fa)));
                $remaining = ltrim($remaining, " -–—");
                if (!empty($remaining)) $fa_title = $remaining;
            }
            if (mb_strpos($fa_title, $artist_fa . ' ') === 0) {
                $remaining = trim(mb_substr($fa_title, mb_strlen($artist_fa)));
                $remaining = ltrim($remaining, " -–—");
                if (!empty($remaining) && mb_strlen($remaining) < 100) $fa_title = $remaining;
            }
        }
        foreach ($prefixes as $pre) {
            if (mb_strpos($fa_title, $pre) === 0) {
                $fa_title = trim(mb_substr($fa_title, mb_strlen($pre)));
                break;
            }
        }
        $fa_title = trim($fa_title, " -–—");
        $fa_title = preg_replace('/\s+/', ' ', $fa_title);
        return $fa_title;
    }
    
    private static function sanitize_lyrics($lyrics) {
        if (empty($lyrics)) return '';
        $lyrics = trim($lyrics);
        $lyrics = str_replace(array("\r\n", "\r"), "\n", $lyrics);
        $lines = preg_split('/\n/', $lyrics);
        $clean = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { $clean[] = ''; continue; }
            $clean[] = preg_replace('/[ \t]+/', ' ', $line);
        }
        $text = implode("\n", $clean);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > 10000) $text = mb_substr($text, 0, 10000);
        return sanitize_textarea_field($text);
    }
    
    private static function normalize_type($type) {
        $type = strtolower(trim((string) $type));
        $mapping = array(
            'single' => 'single','track' => 'single','song' => 'single','album' => 'album','ep' => 'ep','remix' => 'remix','live' => 'live','concert' => 'live','collab' => 'collab','collaboration' => 'collab','feat' => 'collab','podcast' => 'podcast','video' => 'single','music-video' => 'single',
        );
        return isset($mapping[$type]) ? $mapping[$type] : 'single';
    }
    
    private static function detect_remix($text) {
        $text = strtolower($text);
        foreach (array('remix','ریمیکس','mix','edit','version') as $kw) if (strpos($text, $kw) !== false) return true;
        return false;
    }
    private static function detect_live($text) {
        $text = strtolower($text);
        foreach (array('live','concert','اجرای زنده','زنده','unplugged','acoustic') as $kw) if (strpos($text, $kw) !== false) return true;
        return false;
    }
    private static function detect_collab($text) {
        $text = strtolower($text);
        foreach (array('ft.','ft ','feat','featuring','with',' & ',' and ',' x ',' vs ') as $kw) if (strpos($text, $kw) !== false) return true;
        return false;
    }
    private static function calculate_confidence($normalized, $metadata) {
        $score = 0; $max = 0;
        $max += 20; if (!empty($normalized['title'])) $score += 20;
        $max += 20; if (!empty($normalized['artist_en']) || !empty($normalized['artist_fa'])) $score += 20;
        $max += 15; if (!empty($normalized['cover_url'])) $score += 15;
        $max += 25; if (!empty($normalized['audio_url_320'])) $score += 25; elseif (!empty($normalized['audio_url_128'])) $score += 15;
        $max += 10; if (!empty($normalized['source_url'])) $score += 10;
        $max += 10; if (!empty($normalized['genre'])) $score += 5; if (!empty($normalized['year'])) $score += 5;
        return $max > 0 ? round(($score / $max) * 100, 1) : 0;
    }
    private static function determine_license_status($provider_id, $normalized) {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies']) ? $options['provider_policies'] : array();
        if (isset($policies[$provider_id]['license_status'])) return $policies[$provider_id]['license_status'];
        if ($provider_id === 'mock') return 'verified';
        return 'needs_review';
    }
    public static function normalize_artist_for_matching($artist) { return Sanitizer::normalize_string($artist); }
    public static function normalize_title_for_matching($title) {
        $title = Sanitizer::normalize_string($title);
        $title = preg_replace('/\s*[\(\[]\s*(320|128|remix|live|acoustic|original|version)[^\)\]]*[\)\]]/i', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }
}
