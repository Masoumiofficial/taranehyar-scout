<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;

/**
 * NicMusic Provider - نیک موزیک
 * https://nicmusic.net/
 * منبع رسمی و تمیز با ساختار دقیقاً مطابق قالب رز موزیک
 */
class NicMusicProvider implements ProviderInterface {
    
    const BASE_URL = 'https://nicmusic.net';
    const SEARCH_PATH = '/?s=';
    
    public function get_id() { return 'nicmusic'; }
    public function get_name() { return 'NicMusic'; }
    public function get_label() { return 'نیک موزیک'; }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (!isset($policies['enabled'])) return true;
        return (bool) $policies['enabled'];
    }
    
    public function get_status() {
        $last_check = get_option('tscout_provider_status_nicmusic', array());
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
            'supports_types' => array('single', 'remix', 'album'),
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
        
        Logger::info('NicMusic search: ' . $artist_en, array('provider' => $this->get_id()));
        
        $search_url = self::BASE_URL . self::SEARCH_PATH . urlencode($artist_en);
        $valid = UrlValidator::validate($search_url);
        if (is_wp_error($valid)) return $valid;
        
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
        Logger::info('NicMusic search completed', array('provider' => $this->get_id(), 'found' => count($tracks)));
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
            if ($elapsed < $rate_limit) sleep($rate_limit - $elapsed);
        }
        $response = wp_remote_get($url, $args);
        set_transient('tscout_last_request_' . $this->get_id(), time(), 60);
        if (is_wp_error($response)) {
            Logger::error('NicMusic fetch failed: ' . $url, array('error' => $response->get_error_message()));
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
        // تشخیص صفحه 404 نتایج جستجو: نیک موزیک وقتی چیزی پیدا نمی‌کند متن "متاسفانه موزیک یا خواننده ای مورد نظر پیدا نشد" را نشان می‌دهد
        if (mb_strpos($html, 'متاسفانه موزیک یا خواننده ای مورد نظر پیدا نشد') !== false) {
            // جستجو ناموفق - هیچ نتیجه مرتبطی وجود ندارد، آرایه خالی برگردان تا فالو‌بک به mybia2music/ganja2music انجام شود
            return array();
        }
        // همچنین اگر عنوان شامل "404" و "نتايج جستجو" باشد و هیچ "دانلود آهنگ جدید" مرتبط نباشد
        $has_results_marker = (mb_strpos($html, 'دانلود آهنگ جدید') !== false);

        $tracks = array();
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $html_enc = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html_enc, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return new \WP_Error('parse_error', 'خطا در تجزیه نتایج نیک موزیک');
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        // اولویت: لینک‌های داخل نتایج اصلی - article یا h2/h3 عنوان
        // ابتدا سعی کن لینک‌های داخل <article> یا با کلاس entry را پیدا کنی
        $query_strings = array(
            '//article//a[contains(@href, "nicmusic.net/") and not(contains(@href, "/category/")) and not(contains(@href, "/tag/")) and not(contains(@href, "?s=")) and not(contains(@href, "/page/")) and @href]',
            '//h2//a[contains(@href, "nicmusic.net/") and @href]',
            '//h3//a[contains(@href, "nicmusic.net/") and @href]',
            '//a[contains(@href, "nicmusic.net/") and not(contains(@href, "/category/")) and not(contains(@href, "/tag/")) and not(contains(@href, "?s=")) and not(contains(@href, "/page/")) and @href]'
        );
        $links = null;
        foreach ($query_strings as $q) {
            $found = $xpath->query($q);
            if ($found && $found->length > 0) {
                $links = $found;
                break;
            }
        }
        if (!$links || $links->length === 0) {
            return array();
        }

        $seen = array();
        $count = 0;

        foreach ($links as $link) {
            if ($count >= $limit) break;
            $href = $link->getAttribute('href');
            if (strpos($href, '//') === 0) $href = 'https:' . $href;
            elseif (strpos($href, '/') === 0) $href = self::BASE_URL . $href;

            if ($href === self::BASE_URL . '/' || $href === self::BASE_URL) continue;
            if (strpos($href, '/top-100') !== false) continue;
            if (strpos($href, '/%da%af%d9%84%da%86%db%8c%d9%86') !== false) continue;
            if (isset($seen[$href])) continue;
            $seen[$href] = true;

            $path = parse_url($href, PHP_URL_PATH);
            $slug = trim($path, '/');
            if (empty($slug) || mb_strlen($slug) < 2) continue;

            $external_id = md5($slug);

            $title = trim($link->getAttribute('title'));
            if (empty($title)) $title = trim($link->textContent);
            if (empty($title) || mb_strlen($title) < 2) continue;
            // فیلتر اضافی: عنوان باید حداقل شامل "دانلود" یا طول مناسب باشد تا لینک‌های منو حذف شوند
            if (mb_strlen($title) > 200) continue;

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

        // اگر صفحه حاوی marker نتایج است ولی هیچ لینک معتبری پیدا نشد، آرایه خالی برگردان
        if (empty($tracks) && !$has_results_marker) {
            return array();
        }
        
        // Enrich با جزئیات
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
        
        // استخراج هنرمند و آهنگ از الگوی نیک موزیک:
        // [دانلود آهنگ جدید] **محسن یگانه** به نام **دیوار**
        // Download New Music By **Mohsen Yeganeh** Called **Divar**
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain);
        
        $artist_fa = '';
        $track_fa = '';
        $artist_en = '';
        $track_en = '';
        
        // الگوی فارسی: **محسن یگانه** به نام **دیوار**
        if (preg_match('/\*\*([^\*]+)\*\*\s*به نام\s*\*\*([^\*]+)\*\*/u', $html, $m)) {
            $artist_fa = trim($m[1]);
            $track_fa = trim($m[2]);
        } elseif (preg_match('/دانلود آهنگ جدید\s*\*\*([^\*]+)\*\*\s*به نام\s*\*\*([^\*]+)\*\*/u', $html, $m)) {
            $artist_fa = trim($m[1]);
            $track_fa = trim($m[2]);
        } else {
            // fallback: جستجو در متن ساده بدون **
            if (preg_match('/دانلود آهنگ جدید\s+([^\n]+?)\s+به نام\s+([^\n]+?)\s+با کیفیت/u', $plain, $m)) {
                $artist_fa = trim($m[1]);
                $track_fa = trim($m[2]);
            }
        }
        
        // الگوی انگلیسی: By **Mohsen Yeganeh** Called **Divar**
        if (preg_match('/By\s*\*\*([^\*]+)\*\*\s*Called\s*\*\*([^\*]+)\*\*/i', $html, $m)) {
            $artist_en = trim($m[1]);
            $track_en = trim($m[2]);
        } elseif (preg_match('/Download New Music By\s+([^\n]+?)\s+Called\s+([^\n]+)/i', $plain, $m)) {
            $artist_en = trim($m[1]);
            $track_en = trim($m[2]);
        }
        
        // اگر هنوز خالی است، از عنوان صفحه استخراج کن
        if (empty($artist_fa) || empty($track_fa)) {
            if (isset($details['page_title'])) {
                $pt = $details['page_title'];
                // "محسن یگانه دیوار : دانلود ورژن جدید آهنگ محسن یگانه به نام دیوار"
                if (preg_match('/^(.+?)\s+دیوار\s*:/u', $pt, $mm)) {
                    // خیلی خاص برای دیوار
                }
                // الگو: "محسن یگانه دیوار" یا "محسن یگانه بترس"
                // سعی کن اولین 2 کلمه را به عنوان هنرمند و بقیه را ترک در نظر بگیری اگر فارسی است
                if (preg_match('/^([\x{0600}-\x{06FF}\s]+)\s+([\x{0600}-\x{06FF}\s]+)\s*:/u', $pt, $mm)) {
                    $maybe_artist = trim($mm[1]);
                    $maybe_track = trim($mm[2]);
                    if (empty($artist_fa) && mb_strlen($maybe_artist) < 30) $artist_fa = $maybe_artist;
                    if (empty($track_fa) && mb_strlen($maybe_track) < 60) $track_fa = $maybe_track;
                }
            }
        }
        
        // پاکسازی
        if (!empty($artist_fa)) $artist_fa = $this->clean_fa($artist_fa);
        if (!empty($track_fa)) $track_fa = $this->clean_fa($track_fa);
        if (!empty($artist_en)) $artist_en = $this->clean_en($artist_en);
        if (!empty($track_en)) $track_en = $this->clean_en($track_en);
        
        // اگر هنوز خالی است، از اسلاگ استفاده کن
        $slug = '';
        if (preg_match('#nicmusic\.net/([^/]+)/?#', $source_url, $sm)) {
            $slug = urldecode($sm[1]);
        }
        if (empty($track_fa) && !empty($slug)) {
            // slug مثل "divar-5" -> "دیوار" نمی‌توان از انگلیسی به فارسی تبدیل کرد، ولی لااقل انگلیسی را نگه دار
            $track_fa = $track_fa ?: $slug;
        }
        if (empty($track_en) && !empty($slug)) {
            $track_en = $this->slug_to_en($slug);
        }
        
        if (!empty($artist_fa)) $details['artist_fa'] = $artist_fa;
        if (!empty($track_fa)) $details['title_fa'] = $track_fa;
        if (!empty($artist_en)) $details['artist_en'] = $artist_en;
        if (!empty($track_en)) $details['title_en'] = $track_en;
        
        // کاور: اولین عکس با /wp-content/uploads/
        $cover = '';
        $imgs = $xpath->query('//img[contains(@src, "/wp-content/uploads/")]/@src');
        if ($imgs->length > 0) {
            foreach ($imgs as $img) {
                $src = $img->nodeValue;
                if (strpos($src, '150x150') !== false) continue; // thumbnail کوچک
                if (filter_var($src, FILTER_VALIDATE_URL)) { $cover = $src; break; }
            }
            // اگر فقط thumbnail بود، همان را بگیر و سایز بزرگتر بساز
            if (empty($cover) && $imgs->length > 0) {
                $src = $imgs->item(0)->nodeValue;
                $src = str_replace('-150x150', '', $src);
                $cover = $src;
            }
        }
        $details['cover_url'] = $cover;
        
        // MP3: لینک‌های musitraf.com یا .MP3
        $mp3_128 = ''; $mp3_320 = '';
        $mp3_nodes = $xpath->query('//a[contains(@href, ".MP3") or contains(@href, ".mp3")]/@href');
        foreach ($mp3_nodes as $node) {
            $url = trim($node->nodeValue);
            if (empty($url)) continue;
            if (strpos($url, '//') === 0) $url = 'https:' . $url;
            // تشخیص 128
            if (stripos($url, '128') !== false) {
                if (empty($mp3_128)) $mp3_128 = $url;
            } else {
                if (empty($mp3_320)) $mp3_320 = $url;
            }
        }
        // همچنین لینک‌های musitraf
        if (preg_match_all('#https?://[^\s"\'<>]*musitraf\.com[^\s"\'<>]*\.MP3#i', $html, $matches)) {
            foreach ($matches[0] as $mp3_url) {
                $mp3_url = html_entity_decode($mp3_url);
                if (stripos($mp3_url, '128') !== false) {
                    if (empty($mp3_128)) $mp3_128 = $mp3_url;
                } else {
                    if (empty($mp3_320)) $mp3_320 = $mp3_url;
                }
            }
        }
        $details['audio_url_128'] = $mp3_128;
        $details['audio_url_320'] = $mp3_320;
        
        // متن ترانه
        $lyrics = $this->extract_lyrics($dom, $xpath, $html);
        $details['lyrics'] = $lyrics;
        $details['music_text'] = $lyrics;
        
        // ژانر؟ نیک موزیک ژانر ندارد، ولی می‌توان پاپ فرض کرد
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
    
    private function slug_to_en($slug) {
        $slug = urldecode($slug);
        $slug = preg_replace('/-\d+$/', '', $slug); // حذف -5 انتهای divar-5
        $slug = strtolower($slug);
        $slug = str_replace('-', ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug);
        $slug = trim($slug);
        $words = explode(' ', $slug);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }
    
    private function extract_lyrics($dom, $xpath, $html) {
        $lyrics = '';
        // نیک موزیک: متن بعد از "متن آهنگ محسن یگانه دیوار"
        $h2_nodes = $xpath->query('//*[contains(text(), "متن آهنگ")]/following-sibling::*');
        $collected = array();
        foreach ($h2_nodes as $node) {
            $text = trim($node->textContent);
            if (empty($text)) continue;
            if (mb_strpos($text, 'لینک های دانلود') !== false) break;
            if (mb_strpos($text, 'دانلود آهنگ با کیفیت') !== false) break;
            if (preg_match('/[\x{0600}-\x{06FF}]{10,}/u', $text) && mb_strlen($text) > 10) {
                $collected[] = $text;
            }
            if (count($collected) > 40) break;
        }
        if (!empty($collected)) {
            $lyrics = implode("\n\n", $collected);
        }
        
        // fallback: regex بین "متن آهنگ" و "لینک های دانلود"
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            if (preg_match('/متن آهنگ.*?<\/h2>\s*(.+?)\s*لینک های دانلود/su', $html, $m)) {
                $inner = $m[1];
                $inner = preg_replace('/<br\s*\/?>/i', "\n", $inner);
                $inner = strip_tags($inner);
                $inner = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $lyrics = trim($inner);
            }
        }
        
        $lyrics = $this->clean_lyrics($lyrics);
        return $lyrics;
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
            if (mb_strpos($line, 'دانلود آهنگ') !== false && mb_strlen($line) < 50) continue;
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
        $existing = get_option('tscout_provider_status_nicmusic', array());
        if (isset($existing['success']) && !$success) $status['success'] = $existing['success'];
        update_option('tscout_provider_status_nicmusic', $status, false);
    }
}
