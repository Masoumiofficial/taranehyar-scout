<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;

/**
 * Ganja2Music Provider - گانجا2موزیک
 * https://www.ganja2music.com/
 * تست زمینه: 1187787 kaveh-zhav-sunrise و 1187747 roozbeh-bemani-booseye-akhar
 * نکته: سایت گاهی reCAPTCHA Enterprise quota exceeded نشان می‌دهد
 */
class Ganja2MusicProvider implements ProviderInterface {
    
    const BASE_URL = 'https://www.ganja2music.com';
    const SEARCH_PATH = '/?s=';
    
    public function get_id() { return 'ganja2music'; }
    public function get_name() { return 'Ganja2Music'; }
    public function get_label() { return 'گانجا2موزیک'; }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (!isset($policies['enabled'])) return true;
        return (bool) $policies['enabled'];
    }
    
    public function get_status() {
        $last_check = get_option('tscout_provider_status_ganja2music', array());
        return array(
            'id' => $this->get_id(),
            'name' => $this->get_name(),
            'label' => $this->get_label(),
            'available' => $this->is_available(),
            'last_check' => isset($last_check['time']) ? $last_check['time'] : null,
            'last_success' => isset($last_check['success']) ? $last_check['success'] : null,
            'last_error' => isset($last_check['error']) ? $last_check['error'] : null,
            'rate_limit' => 3,
            'requires_auth' => false,
            'supports_search' => true,
            'supports_types' => array('single', 'album', 'video'),
            'base_url' => self::BASE_URL,
        );
    }
    
    public function validate_config() { return true; }
    
    public function search_artist($artist_en, $options = array()) {
        $artist_en = trim((string) $artist_en);
        if (empty($artist_en)) return new \WP_Error('empty_artist', 'نام هنرمند خالی است.');
        $limit = isset($options['limit']) ? intval($options['limit']) : 50;
        $limit = max(1, min(100, $limit));
        $types = isset($options['types']) ? (array) $options['types'] : array();
        
        Logger::info('Ganja2Music search: ' . $artist_en, array('provider' => $this->get_id()));
        
        $search_url = self::BASE_URL . self::SEARCH_PATH . urlencode($artist_en);
        $valid = UrlValidator::validate($search_url);
        if (is_wp_error($valid)) return $valid;
        
        $response = $this->fetch_url($search_url);
        if (is_wp_error($response)) {
            $this->update_status(false, $response->get_error_message());
            return $response;
        }
        
        if ($this->is_recaptcha_page($response)) {
            $error = new \WP_Error('recaptcha_blocked', 'گانجا2موزیک موقتاً با reCAPTCHA مسدود است. لطفاً بعداً تلاش کنید یا از نیک موزیک/بیا2موزیک استفاده کنید.');
            $this->update_status(false, 'reCAPTCHA blocked');
            return $error;
        }
        
        $tracks = $this->parse_search_results($response, $artist_en, $limit, $types);
        if (is_wp_error($tracks)) {
            $this->update_status(false, $tracks->get_error_message());
            return $tracks;
        }
        
        $this->update_status(true);
        Logger::info('Ganja2Music search completed', array('provider' => $this->get_id(), 'found' => count($tracks)));
        return $tracks;
    }
    
    public function get_track_details($source_url) {
        $source_url = trim((string) $source_url);
        $valid = UrlValidator::validate($source_url);
        if (is_wp_error($valid)) return $valid;
        if (!UrlValidator::is_allowed_provider_domain($source_url, $this->get_id())) {
            return new \WP_Error('invalid_domain', 'دامنه مجاز نیست.');
        }
        $response = $this->fetch_url($source_url);
        if (is_wp_error($response)) return $response;
        if ($this->is_recaptcha_page($response)) {
            return new \WP_Error('recaptcha_blocked', 'صفحه گانجا2موزیک با reCAPTCHA مسدود است.');
        }
        return $this->parse_track_page($response, $source_url);
    }
    
    private function is_recaptcha_page($html) {
        if (stripos($html, 'Verifying that you are not a robot') !== false) return true;
        if (stripos($html, 'reCAPTCHA') !== false && stripos($html, 'Bot Verification') !== false) return true;
        if (stripos($html, 'exceeding reCAPTCHA') !== false) return true;
        return false;
    }
    
    private function fetch_url($url) {
        $options = get_option('tscout_options', array());
        $timeout = isset($options['request_timeout']) ? intval($options['request_timeout']) : 30;
        $timeout = max(10, min(60, $timeout));
        $args = array(
            'timeout' => $timeout,
            'redirection' => 2,
            'user-agent' => 'TaranehyarScout/1.0 (+https://etehadwp.com/) - Ganja2Music',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fa,en;q=0.5',
            ),
            'sslverify' => true,
        );
        $last_request = get_transient('tscout_last_request_' . $this->get_id());
        if ($last_request) {
            $elapsed = time() - $last_request;
            $rate_limit = 3;
            if ($elapsed < $rate_limit) sleep($rate_limit - $elapsed);
        }
        $response = wp_remote_get($url, $args);
        set_transient('tscout_last_request_' . $this->get_id(), time(), 60);
        if (is_wp_error($response)) {
            Logger::error('Ganja2Music fetch failed: ' . $url, array('error' => $response->get_error_message()));
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('http_error', "خطای HTTP {$code} برای {$url}");
        }
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) return new \WP_Error('empty_body', 'پاسخ خالی');
        return $body;
    }
    
    private function parse_search_results($html, $artist_en, $limit, $types) {
        $tracks = array();
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $html_enc = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html_enc, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return new \WP_Error('parse_error', 'خطا در تجزیه نتایج گانجا2موزیک');
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        $links = $xpath->query('//a[contains(@href, "ganja2music.com/") and not(contains(@href, "/artist/")) and not(contains(@href, "/archive/")) and not(contains(@href, "?s=")) and not(contains(@href, "/page/")) and @href]');
        $seen = array();
        $count = 0;
        
        foreach ($links as $link) {
            if ($count >= $limit) break;
            $href = $link->getAttribute('href');
            if (strpos($href, '//') === 0) $href = 'https:' . $href;
            elseif (strpos($href, '/') === 0) $href = self::BASE_URL . $href;
            
            if ($href === self::BASE_URL . '/' || $href === self::BASE_URL) continue;
            if (strpos($href, '/artist/') !== false) continue;
            if (strpos($href, '/archive/') !== false) continue;
            if (isset($seen[$href])) continue;
            $seen[$href] = true;
            
            if (!preg_match('#ganja2music\.com/(\d+)/([a-z0-9\-]+)/?#i', $href, $m)) continue;
            $external_id = $m[1];
            $slug = $m[2];
            
            $title = trim($link->getAttribute('title'));
            if (empty($title)) $title = trim($link->textContent);
            if (empty($title) || mb_strlen($title) < 2) continue;
            
            $track = array(
                'external_id' => $external_id,
                'provider' => $this->get_id(),
                'source_url' => $href,
                'slug' => $slug,
                'title' => $title,
                'artist_en' => $artist_en,
                'type' => $this->detect_type_from_slug($slug),
                'cover_url' => '',
            );
            $tracks[] = $track;
            $count++;
        }
        
        $enriched = array();
        $enrich_limit = min(10, count($tracks));
        for ($i = 0; $i < count($tracks); $i++) {
            if ($i < $enrich_limit) {
                $details = $this->get_track_details($tracks[$i]['source_url']);
                if (!is_wp_error($details)) {
                    $enriched[] = array_merge($tracks[$i], $details);
                } else {
                    $enriched[] = $tracks[$i];
                }
                if ($i < $enrich_limit - 1) usleep(700000);
            } else {
                $enriched[] = $tracks[$i];
            }
        }
        
        return $enriched;
    }
    
    private function parse_track_page($html, $source_url) {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $html_enc = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html_enc, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return array();
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $details = array();
        
        $title_nodes = $xpath->query('//title');
        if ($title_nodes->length > 0) $details['page_title'] = trim($title_nodes->item(0)->textContent);
        
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain);
        
        $artist_fa = '';
        $track_fa = '';
        $artist_en = '';
        $track_en = '';
        
        $fa_patterns = array(
            '/دانلود آهنگ\s+([^\n]+?)\s+به نام\s+([^\n]+?)(?:\s+Download|\s+Lyrics|\s+\||\n|$)/u',
            '/دانلود آهنگ جدید\s+([^\n]+?)\s+به نام\s+([^\n]+?)(?:\s+Download|\s+Lyrics|\n|$)/u',
            '/دانلود آهنگ\s+([^\n]+?)\s+به نام\s+([^\n]+)/u',
        );
        foreach ($fa_patterns as $pat) {
            if (preg_match($pat, $plain, $m)) {
                $artist_fa = trim($m[1]);
                $track_fa = trim($m[2]);
                $track_fa = preg_replace('/\s+با کیفیت.*$/u', '', $track_fa);
                $track_fa = preg_replace('/\s+Download.*$/i', '', $track_fa);
                if (!empty($artist_fa) && !empty($track_fa)) break;
            }
        }
        if ((empty($artist_fa) || empty($track_fa)) && isset($details['page_title'])) {
            $pt = $details['page_title'];
            if (preg_match('/دانلود آهنگ\s+(.+?)\s+به نام\s+(.+?)(?:\s+\||\s+-|\s+\(|$)/u', $pt, $m)) {
                if (empty($artist_fa)) $artist_fa = trim($m[1]);
                if (empty($track_fa)) $track_fa = trim($m[2]);
            }
        }
        
        if (preg_match('/([A-Za-z\s]+)\s*[–-]\s*([A-Za-z\s]+)/', $plain, $m)) {
            $maybe_artist = trim($m[1]);
            $maybe_track = trim($m[2]);
            if (empty($artist_en) && preg_match('/^[A-Za-z\s]{3,50}$/', $maybe_artist)) $artist_en = $maybe_artist;
            if (empty($track_en) && preg_match('/^[A-Za-z\s]{2,60}$/', $maybe_track)) $track_en = $maybe_track;
        }
        if (preg_match('/By\s+([A-Za-z\s]+)$/i', $plain, $m)) {
            if (empty($artist_en)) $artist_en = trim($m[1]);
        }
        if (preg_match('/Download\s+([A-Za-z\s]+)\s+a Track By\s+([A-Za-z\s]+)/i', $plain, $m)) {
            if (empty($track_en)) $track_en = trim($m[1]);
            if (empty($artist_en)) $artist_en = trim($m[2]);
        }
        
        $slug = '';
        if (preg_match('#ganja2music\.com/\d+/([a-z0-9\-]+)/?#i', $source_url, $sm)) {
            $slug = strtolower($sm[1]);
        }
        if (!empty($slug)) {
            $parts = explode('-', $slug);
            if (count($parts) >= 3) {
                $artist_slug = $parts[0] . '-' . $parts[1];
                $track_slug = implode('-', array_slice($parts, 2));
                if (empty($artist_en)) $artist_en = $this->slug_to_en($artist_slug);
                if (empty($track_en)) $track_en = $this->slug_to_en($track_slug);
            } elseif (count($parts) == 2) {
                if (empty($artist_en)) $artist_en = $this->slug_to_en($parts[0]);
                if (empty($track_en)) $track_en = $this->slug_to_en($parts[1]);
            }
        }
        
        $credits = array();
        if (preg_match('/Lyrics\s*:\s*([^\n]+?)\s*(?:Music|Arrangement|Mix|$)/i', $plain, $m)) $credits['lyricist'] = trim($m[1]);
        if (preg_match('/Music\s*:\s*([^\n]+?)\s*(?:Arrangement|Mix|Release|$)/i', $plain, $m)) $credits['composer'] = trim($m[1]);
        if (preg_match('/Arrangement\s*:\s*([^\n]+?)\s*(?:Mix|Release|$)/i', $plain, $m)) $credits['arranger'] = trim($m[1]);
        if (preg_match('/Mix\s*(?:&|and)?\s*Mastering\s*:\s*([^\n]+?)\s*(?:Release|$)/i', $plain, $m)) $credits['mix_master'] = trim($m[1]);
        if (preg_match('/ترانه\s*:\s*([^\n,]+)/u', $plain, $m)) $credits['lyricist'] = trim($m[1]);
        if (preg_match('/موزیک\s*:\s*([^\n,]+)/u', $plain, $m)) $credits['composer'] = trim($m[1]);
        if (preg_match('/تنظیم\s*:\s*([^\n,]+)/u', $plain, $m)) $credits['arranger'] = trim($m[1]);
        
        foreach ($credits as $k => $v) {
            $v = trim($v, " \t\n\r\0\x0B-–—");
            if (mb_strlen($v) > 100) $v = mb_substr($v, 0, 100);
            $credits[$k] = $v;
        }
        
        $details['credits'] = $credits;
        $details['lyricist'] = $credits['lyricist'] ?? '';
        $details['composer'] = $credits['composer'] ?? '';
        $details['arranger'] = $credits['arranger'] ?? '';
        $details['mix_master'] = $credits['mix_master'] ?? '';
        
        if (empty($artist_fa) && !empty($credits['lyricist']) && preg_match('/[\x{0600}-\x{06FF}]/u', $credits['lyricist'])) {
            $artist_fa = $credits['lyricist'];
        }
        
        $cover = '';
        $imgs = $xpath->query('//img[contains(@src, "/wp-content/") or contains(@src, "ganja2music")]/@src');
        if ($imgs->length > 0) {
            foreach ($imgs as $img) {
                $src = $img->nodeValue;
                if (strpos($src, 'data:image') === 0) continue;
                if (filter_var($src, FILTER_VALIDATE_URL)) { $cover = $src; break; }
            }
        }
        $details['cover_url'] = $cover;
        
        $mp3_128 = ''; $mp3_320 = '';
        $mp3_nodes = $xpath->query('//a[contains(@href, ".mp3") or contains(@href, ".MP3")]/@href');
        foreach ($mp3_nodes as $node) {
            $url = trim($node->nodeValue);
            if (empty($url)) continue;
            if (strpos($url, '//') === 0) $url = 'https:' . $url;
            if (stripos($url, '128') !== false) {
                if (empty($mp3_128)) $mp3_128 = $url;
            } else {
                if (empty($mp3_320)) $mp3_320 = $url;
            }
        }
        $details['audio_url_128'] = $mp3_128;
        $details['audio_url_320'] = $mp3_320;
        
        if (!empty($artist_fa)) $details['artist_fa'] = $this->clean_fa($artist_fa);
        if (!empty($track_fa)) $details['title_fa'] = $this->clean_fa($track_fa);
        if (!empty($artist_en)) $details['artist_en'] = $this->clean_en($artist_en);
        if (!empty($track_en)) $details['title_en'] = $this->clean_en($track_en);
        
        if (!empty($details['title_fa']) && !empty($details['artist_fa'])) {
            $details['title_fa'] = $this->clean_fa_title($details['title_fa'], $details['artist_fa']);
        }
        
        $lyrics = $this->extract_lyrics($dom, $xpath, $html);
        $details['lyrics'] = $lyrics;
        $details['music_text'] = $lyrics;
        
        $details['genre'] = 'پاپ';
        
        return $details;
    }
    
    private function clean_fa($text) {
        $text = trim($text);
        $text = preg_replace('/^دانلود آهنگ جدید\s*/u', '', $text);
        $text = preg_replace('/^دانلود آهنگ\s*/u', '', $text);
        $text = trim($text, " :–—-");
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }
    
    private function clean_en($text) {
        $text = trim($text);
        $text = trim($text, " :–—-");
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }
    
    private function clean_fa_title($fa_title, $artist_fa) {
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
                if (!empty($artist_fa) && mb_strpos($fa_title, $artist_fa) !== false) $fa_title = $first;
            }
        }
        if (!empty($artist_fa) && mb_strpos($fa_title, $artist_fa) === 0) {
            $remaining = trim(mb_substr($fa_title, mb_strlen($artist_fa)));
            $remaining = ltrim($remaining, " -–—");
            if (!empty($remaining)) $fa_title = $remaining;
        }
        $fa_title = trim($fa_title, " -–—");
        $fa_title = preg_replace('/\s+/', ' ', $fa_title);
        return $fa_title;
    }
    
    private function slug_to_en($slug) {
        $slug = strtolower(trim($slug));
        $slug = str_replace('-', ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug);
        $slug = trim($slug);
        $words = explode(' ', $slug);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }
    
    private function extract_lyrics($dom, $xpath, $html) {
        $lyrics = '';
        $nodes = $xpath->query('//*[contains(text(), "متن آهنگ") or contains(text(), "متن ترانه")]/following-sibling::p');
        $collected = array();
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if (empty($text)) continue;
            if (mb_strpos($text, 'دانلود') !== false && mb_strlen($text) < 50) break;
            if (preg_match('/[\x{0600}-\x{06FF}]{10,}/u', $text)) $collected[] = $text;
            if (count($collected) > 30) break;
        }
        if (!empty($collected)) $lyrics = implode("\n\n", $collected);
        return $this->clean_lyrics($lyrics);
    }
    
    private function clean_lyrics($text) {
        $text = trim($text);
        if (empty($text)) return '';
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $lines = explode("\n", $text);
        $clean = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { if (!empty($clean) && end($clean) !== '') $clean[] = ''; continue; }
            $clean[] = preg_replace('/[ \t]+/', ' ', $line);
        }
        $text = implode("\n", $clean);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > 10000) $text = mb_substr($text, 0, 10000);
        return $text;
    }
    
    private function detect_type_from_slug($slug) {
        $slug_lower = strtolower($slug);
        if (strpos($slug_lower, 'remix') !== false) return 'remix';
        return 'single';
    }
    
    private function update_status($success, $error = null) {
        $status = array('time' => current_time('mysql'),'success' => $success ? current_time('mysql') : null,'error' => $error,);
        $existing = get_option('tscout_provider_status_ganja2music', array());
        if (isset($existing['success']) && !$success) $status['success'] = $existing['success'];
        update_option('tscout_provider_status_ganja2music', $status, false);
    }
}
