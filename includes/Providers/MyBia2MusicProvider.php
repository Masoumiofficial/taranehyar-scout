<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;

class MyBia2MusicProvider implements ProviderInterface {
    
    const BASE_URL = 'https://www.mybia2music.com';
    const SEARCH_PATH = '/search/';
    
    public function get_id() {
        return 'mybia2music';
    }
    
    public function get_name() {
        return 'MyBia2Music';
    }
    
    public function get_label() {
        return 'بیا2موزیک';
    }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (!isset($policies['enabled'])) {
            return true;
        }
        return (bool) $policies['enabled'];
    }
    
    public function get_status() {
        $last_check = get_option('tscout_provider_status_mybia2music', array());
        return array(
            'id' => $this->get_id(),
            'name' => $this->get_name(),
            'label' => $this->get_label(),
            'available' => $this->is_available(),
            'last_check' => isset($last_check['time']) ? $last_check['time'] : null,
            'last_success' => isset($last_check['success']) ? $last_check['success'] : null,
            'last_error' => isset($last_check['error']) ? $last_check['error'] : null,
            'rate_limit' => 2,
            'requires_auth' => false,
            'supports_search' => true,
            'supports_types' => array('single', 'album', 'remix', 'video', 'live'),
            'base_url' => self::BASE_URL,
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
        
        $limit = isset($options['limit']) ? intval($options['limit']) : 50;
        $limit = max(1, min(100, $limit));
        $types = isset($options['types']) ? (array) $options['types'] : array();
        
        Logger::info('Starting search for artist: ' . $artist_en, array('provider' => $this->get_id(), 'artist' => $artist_en, 'limit' => $limit));
        
        $search_url = self::BASE_URL . self::SEARCH_PATH . urlencode($artist_en);
        $valid = UrlValidator::validate($search_url);
        if (is_wp_error($valid)) {
            return $valid;
        }
        
        $response = $this->fetch_url($search_url);
        if (is_wp_error($response)) {
            $this->update_status(false, $response->get_error_message());
            return $response;
        }
        
        $tracks = $this->parse_search_results($response, $artist_en, $limit, $types);
        if (is_wp_error($tracks)) {
            $this->update_status(false, $tracks->get_error_message());
            return $tracks;
        }
        
        $this->update_status(true);
        Logger::info('Search completed', array('provider' => $this->get_id(), 'artist' => $artist_en, 'found' => count($tracks)));
        return $tracks;
    }
    
    public function get_track_details($source_url) {
        $source_url = trim((string) $source_url);
        $valid = UrlValidator::validate($source_url);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (!UrlValidator::is_allowed_provider_domain($source_url, $this->get_id())) {
            return new \WP_Error('invalid_domain', 'دامنه مجاز نیست.');
        }
        $response = $this->fetch_url($source_url);
        if (is_wp_error($response)) {
            return $response;
        }
        return $this->parse_track_page($response, $source_url);
    }
    
    private function fetch_url($url) {
        $options = get_option('tscout_options', array());
        $timeout = isset($options['request_timeout']) ? intval($options['request_timeout']) : 30;
        $timeout = max(10, min(60, $timeout));
        
        $args = array(
            'timeout' => $timeout,
            'redirection' => 2,
            'user-agent' => 'TaranehyarScout/1.0 (+https://etehadwp.com/)',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fa,en;q=0.5',
            ),
            'sslverify' => true,
        );
        
        $last_request = get_transient('tscout_last_request_' . $this->get_id());
        if ($last_request) {
            $elapsed = time() - $last_request;
            $rate_limit = 2;
            if ($elapsed < $rate_limit) {
                sleep($rate_limit - $elapsed);
            }
        }
        
        $response = wp_remote_get($url, $args);
        set_transient('tscout_last_request_' . $this->get_id(), time(), 60);
        
        if (is_wp_error($response)) {
            Logger::error('Failed to fetch URL: ' . $url, array('provider' => $this->get_id(), 'error' => $response->get_error_message()));
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            Logger::warning('Non-200 response', array('provider' => $this->get_id(), 'url' => $url, 'code' => $code));
            return new \WP_Error('http_error', "خطای HTTP {$code} برای آدرس {$url}");
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new \WP_Error('empty_body', 'پاسخ خالی از سرور');
        }
        
        return $body;
    }
    
    private function parse_search_results($html, $artist_en, $limit, $types) {
        $tracks = array();
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return new \WP_Error('parse_error', 'خطا در تجزیه صفحه نتایج');
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[contains(@href, "mybia2music.com/") and @href]');
        $seen_urls = array();
        $count = 0;
        foreach ($links as $link) {
            if ($count >= $limit) break;
            $href = $link->getAttribute('href');
            if (strpos($href, '//') === 0) {
                $href = 'https:' . $href;
            } elseif (strpos($href, '/') === 0) {
                $href = self::BASE_URL . $href;
            }
            if (!preg_match('#https?://(?:www\.)?mybia2music\.com/(\d+)/([a-z0-9\-]+)#i', $href, $matches)) continue;
            $external_id = $matches[1];
            $slug = $matches[2];
            if (isset($seen_urls[$href])) continue;
            $seen_urls[$href] = true;
            $skip_patterns = array('/playlist/', '/artists/', '/genre/', '/mood/', '/playlists');
            $should_skip = false;
            foreach ($skip_patterns as $pattern) {
                if (strpos($href, $pattern) !== false) { $should_skip = true; break; }
            }
            if ($should_skip) continue;
            $title = trim($link->getAttribute('title'));
            if (empty($title)) $title = trim($link->textContent);
            $track = array(
                'external_id' => $external_id,
                'provider' => $this->get_id(),
                'source_url' => $href,
                'slug' => $slug,
                'title' => $title,
                'artist_en' => $artist_en,
                'type' => $this->detect_type_from_slug($slug),
                'cover_url' => $this->extract_cover_from_link($link, $xpath),
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
                if ($i < $enrich_limit - 1) usleep(500000);
            } else {
                $enriched[] = $tracks[$i];
            }
        }
        return $enriched;
    }
    
    private function parse_track_page($html, $source_url) {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $html_encoded = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html_encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return array();
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $details = array();
        
        $title_nodes = $xpath->query('//title');
        if ($title_nodes->length > 0) {
            $details['page_title'] = trim($title_nodes->item(0)->textContent);
        }
        
        // استخراج نام فارسی خواننده از لینک هنرمند
        $artist_fa = '';
        $artist_links = $xpath->query('//a[contains(@href, "/artists/")]');
        if ($artist_links->length > 0) {
            foreach ($artist_links as $alink) {
                $text = trim($alink->textContent);
                if (!empty($text) && mb_strlen($text) > 1 && mb_strlen($text) < 100) {
                    $href = $alink->getAttribute('href');
                    if (preg_match('#/artists/[^/]+$#', $href)) {
                        $artist_fa = $text;
                        break;
                    }
                }
            }
        }
        if (!empty($artist_fa)) {
            $details['artist_fa'] = $artist_fa;
        }
        
        // کاور
        $cover = '';
        $og_image = $xpath->query('//meta[@property="og:image"]/@content');
        if ($og_image->length > 0) {
            $cover = $og_image->item(0)->nodeValue;
        }
        if (preg_match('#https?://sv\d*\.mybia2music\.com/[^\s"\'<>]+\.jpg#i', $html, $m)) {
            $cover = html_entity_decode($m[0]);
        }
        if (empty($cover)) {
            $images = $xpath->query('//img[contains(@src, "mybia2music.com")]/@src');
            foreach ($images as $img) {
                $src = $img->nodeValue;
                if (strpos($src, 'data:image') === 0) continue;
                if (filter_var($src, FILTER_VALIDATE_URL) && strpos($src, 'thumbs') === false) {
                    $cover = $src; break;
                }
            }
            if (empty($cover)) {
                $images = $xpath->query('//img[contains(@src, "mybia2music")]/@src');
                if ($images->length > 0) {
                    $src = $images->item(0)->nodeValue;
                    if (strpos($src, '_300.jpg') !== false) $src = str_replace('_300.jpg', '_500.jpg', $src);
                    $cover = $src;
                }
            }
        }
        $details['cover_url'] = $cover;
        
        // MP3
        $mp3_128 = ''; $mp3_320 = '';
        if (preg_match_all('#https?://sv\d*\.mybia2music\.com/[^\s"\'<>]+\.mp3#i', $html, $matches)) {
            foreach ($matches[0] as $mp3_url) {
                $mp3_url = html_entity_decode($mp3_url);
                if (strpos($mp3_url, '[128]') !== false || strpos($mp3_url, '%5B128%5D') !== false || stripos($mp3_url, '128') !== false) {
                    if (empty($mp3_128) || strpos($mp3_url, '[128]') !== false) $mp3_128 = $mp3_url;
                } else {
                    if (empty($mp3_320)) $mp3_320 = $mp3_url;
                }
            }
        }
        $download_links = $xpath->query('//a[contains(@href, ".mp3")]/@href');
        foreach ($download_links as $dl) {
            $url = trim($dl->nodeValue);
            if (empty($url)) continue;
            if (strpos($url, '//') === 0) $url = 'https:' . $url;
            if (strpos($url, '[128]') !== false || stripos($url, '128') !== false) {
                if (empty($mp3_128)) $mp3_128 = $url;
            } else {
                if (empty($mp3_320)) $mp3_320 = $url;
            }
        }
        $details['audio_url_128'] = $mp3_128;
        $details['audio_url_320'] = $mp3_320;
        
        $genre = ''; $genre_nodes = $xpath->query('//a[contains(@href, "/genre/")]');
        if ($genre_nodes->length > 0) $genre = trim($genre_nodes->item(0)->textContent);
        $details['genre'] = $genre;
        $mood = ''; $mood_nodes = $xpath->query('//a[contains(@href, "/mood/")]');
        if ($mood_nodes->length > 0) $mood = trim($mood_nodes->item(0)->textContent);
        $details['mood'] = $mood;
        
        // عنوان فارسی - h1 معمولاً "محسن یگانه تو خوب"
        $title_fa_full = '';
        $h1_nodes = $xpath->query('//h1');
        if ($h1_nodes->length > 0) $title_fa_full = trim($h1_nodes->item(0)->textContent);
        
        $title_fa = $title_fa_full;
        if (!empty($artist_fa) && !empty($title_fa_full)) {
            if (mb_strpos($title_fa_full, $artist_fa) === 0) {
                $track = trim(mb_substr($title_fa_full, mb_strlen($artist_fa)));
                if (!empty($track)) $title_fa = $track;
            }
        }
        
        if (isset($details['page_title'])) {
            $page_title = $details['page_title'];
            if (preg_match('/^(.+?)\s+([A-Za-z][A-Za-z0-9\s\-]+)$/u', $page_title, $tm)) {
                $fa_part = trim($tm[1]);
                $en_part = trim($tm[2]);
                if (!empty($artist_fa) && mb_strpos($fa_part, $artist_fa) === 0) {
                    $fa_track = trim(mb_substr($fa_part, mb_strlen($artist_fa)));
                    $details['title_fa'] = !empty($fa_track) ? $fa_track : $fa_part;
                } else {
                    if (empty($title_fa) || $title_fa === $title_fa_full) $details['title_fa'] = $fa_part;
                }
                $details['title_en'] = $en_part;
            }
        }
        
        if (empty($details['title_fa']) && !empty($title_fa)) $details['title_fa'] = $title_fa;
        if (empty($details['title_fa']) && !empty($title_fa_full)) $details['title_fa'] = $title_fa_full;
        
        // متن ترانه - بهبود یافته
        $lyrics = '';
        $lyric_nodes = $xpath->query('//*[contains(@id, "lyric") or contains(@class, "lyric") or contains(@class, "lyrics")]');
        if ($lyric_nodes->length > 0) {
            foreach ($lyric_nodes as $ln) {
                $text = trim($ln->textContent);
                if (mb_strlen($text) > 20) { $lyrics = $text; break; }
            }
        }
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            $patterns = array(
                '/متن آهنگ.*?[\r\n]+(.+?)[\r\n]+.*?(?:پخش آهنگ|دانلود آهنگ با کیفیت)/su',
                '/متن ترانه.*?[\r\n]+(.+?)[\r\n]+.*?(?:پخش|دانلود)/su',
                '/<div[^>]*class="[^\"]*lyric[^\"]*"[^>]*>(.+?)<\/div>/su',
            );
            foreach ($patterns as $pat) {
                if (preg_match($pat, $html, $m)) {
                    $candidate = trim(strip_tags($m[1]));
                    $candidate = html_entity_decode($candidate);
                    $candidate = preg_replace('/\s+/', ' ', $candidate);
                    if (mb_strlen($candidate) > 20) { $lyrics = $candidate; break; }
                }
            }
        }
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            $text_nodes = $xpath->query('//p | //div[contains(@class, "content")] | //div[contains(@class, "text")]');
            $longest_fa = '';
            foreach ($text_nodes as $tn) {
                $text = trim($tn->textContent);
                if (mb_strlen($text) > 50 && preg_match('/[\x{0600}-\x{06FF}]{10,}/u', $text)) {
                    if (mb_strlen($text) > mb_strlen($longest_fa) && mb_strpos($text, 'دانلود آهنگ') === false && mb_strlen($text) < 5000) {
                        $longest_fa = $text;
                    }
                }
            }
            if (!empty($longest_fa)) $lyrics = $longest_fa;
        }
        $lyrics = trim($lyrics);
        if (mb_strlen($lyrics) > 10000) $lyrics = mb_substr($lyrics, 0, 10000);
        $details['lyrics'] = $lyrics;
        $details['music_text'] = $lyrics;
        
        return $details;
    }
    
    private function detect_type_from_slug($slug) {
        $slug_lower = strtolower($slug);
        if (strpos($slug_lower, 'remix') !== false) return 'remix';
        if (strpos($slug_lower, 'live') !== false || strpos($slug_lower, 'concert') !== false) return 'live';
        if (strpos($slug_lower, 'album') !== false) return 'album';
        if (strpos($slug_lower, 'video') !== false || strpos($slug_lower, 'music-video') !== false) return 'video';
        if (strpos($slug_lower, 'ft-') !== false || strpos($slug_lower, 'feat') !== false) return 'collab';
        return 'single';
    }
    
    private function extract_cover_from_link($link_node, $xpath) {
        $imgs = $xpath->query('.//img', $link_node);
        if ($imgs->length > 0) {
            $src = $imgs->item(0)->getAttribute('src');
            if (!empty($src)) {
                if (strpos($src, 'data:image') === 0) {
                    $data_src = $imgs->item(0)->getAttribute('data-src');
                    if (!empty($data_src)) $src = $data_src;
                }
                return $src;
            }
        }
        return '';
    }
    
    private function update_status($success, $error = null) {
        $status = array(
            'time' => current_time('mysql'),
            'success' => $success ? current_time('mysql') : null,
            'error' => $error,
        );
        $existing = get_option('tscout_provider_status_mybia2music', array());
        if (isset($existing['success']) && !$success) {
            $status['success'] = $existing['success'];
        }
        update_option('tscout_provider_status_mybia2music', $status, false);
    }
}
