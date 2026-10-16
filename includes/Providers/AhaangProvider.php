<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;

/**
 * Ahaang Provider - رسانه آهنگ
 * https://ahaang.com/
 * ساختار بسیار تمیز، مشابه بیا2موزیک با متادیتای کامل
 * تست: روزبه بمانی بوسه آخر
 */
class AhaangProvider implements ProviderInterface {
    
    const BASE_URL = 'https://ahaang.com';
    const SEARCH_PATH = '/?s=';
    const DL_BASE = 'https://dl.ahaang.com';
    
    public function get_id() { return 'ahaang'; }
    public function get_name() { return 'Ahaang'; }
    public function get_label() { return 'رسانه آهنگ'; }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (!isset($policies['enabled'])) return true;
        return (bool) $policies['enabled'];
    }
    
    public function get_status() {
        $last_check = get_option('tscout_provider_status_ahaang', array());
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
        
        Logger::info('Ahaang search: ' . $artist_en, array('provider' => $this->get_id()));
        
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
        Logger::info('Ahaang search completed', array('provider' => $this->get_id(), 'found' => count($tracks)));
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
            'redirection' => 3,
            'user-agent' => 'TaranehyarScout/1.0 (+https://etehadwp.com/) - Ahaang',
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
            Logger::error('Ahaang fetch failed: ' . $url, array('error' => $response->get_error_message()));
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
            return new \WP_Error('parse_error', 'خطا در تجزیه نتایج آهنگ');
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        // آهنگ: جستجو در بخش تک آهنگ ها - لینک‌هایی که /%d8%... شامل نام آهنگ هستند و نه /artist/ و نه /album/ و نه /category/
        // بهترین روش: تمام لینک‌های داخلی ahaang.com که شامل wp-content نیستند و شامل title دانلود آهنگ هستند
        $links = $xpath->query('//a[contains(@href, "ahaang.com/") and not(contains(@href, "/artist/")) and not(contains(@href, "/album/")) and not(contains(@href, "/category/")) and not(contains(@href, "/tag/")) and not(contains(@href, "/style/")) and not(contains(@href, "?s=")) and not(contains(@href, "/page/")) and @href]');
        
        $seen = array();
        $count = 0;
        
        foreach ($links as $link) {
            if ($count >= $limit) break;
            $href = $link->getAttribute('href');
            if (strpos($href, '//') === 0) $href = 'https:' . $href;
            elseif (strpos($href, '/') === 0) $href = self::BASE_URL . $href;
            
            if ($href === self::BASE_URL . '/' || $href === self::BASE_URL) continue;
            if (strpos($href, '/wp-content/') !== false) continue;
            if (strpos($href, '/wp-admin/') !== false) continue;
            if (strpos($href, '/artist/') !== false) continue;
            if (strpos($href, '/album/') !== false) continue;
            if (strpos($href, '/lyric/') !== false) continue;
            if (isset($seen[$href])) continue;
            $seen[$href] = true;
            
            // فقط پست‌های آهنگ: URL باید شامل کاراکترهای فارسی urlencode شده یا حداقل 3 حرف داشته باشد
            // و نباید شامل query string باشد
            $path = parse_url($href, PHP_URL_PATH);
            $slug = trim($path, '/');
            if (empty($slug) || mb_strlen($slug) < 3) continue;
            // فیلتر: اسلاگ نباید شامل 'category' 'tag' 'style' باشد
            if (strpos($slug, 'category') !== false || strpos($slug, 'tag') !== false) continue;
            
            // استخراج عنوان
            $title = trim($link->getAttribute('title'));
            if (empty($title)) $title = trim($link->textContent);
            if (empty($title) || mb_strlen($title) < 2) continue;
            if (mb_strlen($title) > 200) continue;
            
            // فقط آهنگ‌ها: عنوان باید شامل حداقل یک کلمه فارسی باشد یا شامل "دانلود آهنگ"
            // برای جستجوی انگلیسی، ممکن است عنوان فارسی باشد، پس همه را قبول می‌کنیم اما فیلتر منو را حذف می‌کنیم
            $lower_title = mb_strtolower($title);
            if (in_array($lower_title, array('مشاهده همه', 'مشاهده پروفایل', 'دانلود', 'صفحه اصلی'))) continue;
            
            // external_id از URL: hash یا کد آهنگ
            $external_id = md5($slug);
            // اگر در صفحه کد آهنگ وجود دارد، بعداً از جزئیات پر می‌شود
            
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
        
        // Enrich با جزئیات برای 10 تای اول
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
        
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', $plain);
        
        $artist_fa = '';
        $track_fa = '';
        $artist_en = '';
        $track_en = '';
        
        // استخراج از H2: "روزبه بمانی | بوسه آخر"
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $html, $hm)) {
            $h2_text = strip_tags($hm[1]);
            $h2_text = trim(html_entity_decode($h2_text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($h2_text, '|') !== false) {
                $parts = explode('|', $h2_text);
                if (count($parts) == 2) {
                    $artist_fa = trim($parts[0]);
                    $track_fa = trim($parts[1]);
                }
            }
        }
        
        // از title: "دانلود آهنگ روزبه بمانی بوسه آخر"
        if (empty($artist_fa) || empty($track_fa)) {
            if (isset($details['page_title'])) {
                $pt = $details['page_title'];
                if (preg_match('/دانلود آهنگ\s+(.+?)\s+بوسه آخر/u', $pt, $m)) {
                    // حالت خاص
                }
                // الگو: دانلود آهنگ X Y - X هنرمند، Y آهنگ
                // در ahaang معمولاً "دانلود آهنگ روزبه بمانی بوسه آخر" - یعنی هنرمند 2 کلمه، بقیه آهنگ
                if (preg_match('/دانلود آهنگ\s+(.+)/u', $pt, $m)) {
                    $rest = trim($m[1]);
                    // اگر artist_fa از H2 داریم، از آن برای جدا کردن track استفاده کن
                    if (!empty($artist_fa) && mb_strpos($rest, $artist_fa) === 0) {
                        $track_candidate = trim(mb_substr($rest, mb_strlen($artist_fa)));
                        if (!empty($track_candidate)) $track_fa = $track_candidate;
                    } else {
                        // حدس: 2 کلمه اول هنرمند، بقیه آهنگ
                        $words = preg_split('/\s+/u', $rest);
                        if (count($words) >= 3) {
                            $artist_candidate = $words[0] . ' ' . $words[1];
                            $track_candidate = implode(' ', array_slice($words, 2));
                            if (empty($artist_fa)) $artist_fa = $artist_candidate;
                            if (empty($track_fa)) $track_fa = $track_candidate;
                        }
                    }
                }
            }
        }
        
        // از لیست جزئیات: عنوان اثر، خواننده
        if (preg_match('/عنوان اثر\s+([^\n]+?)\s+موضوع/u', $plain, $m)) {
            $track_from_detail = trim($m[1]);
            if (!empty($track_from_detail) && empty($track_fa)) $track_fa = $track_from_detail;
            elseif (!empty($track_from_detail)) {
                // اگر track_fa فعلی طولانی است، از این دقیق‌تر استفاده کن
                if (mb_strlen($track_from_detail) < mb_strlen($track_fa)) $track_fa = $track_from_detail;
            }
        }
        if (preg_match('/خواننده\s+([^\n]+?)\s+آهنگساز/u', $plain, $m)) {
            $artist_from_detail = trim($m[1]);
            if (!empty($artist_from_detail)) {
                // ممکن است شامل "،" برای چند خواننده باشد - اولی را بگیر
                $artist_parts = explode('،', $artist_from_detail);
                $artist_from_detail = trim($artist_parts[0]);
                if (empty($artist_fa) || mb_strlen($artist_from_detail) < mb_strlen($artist_fa)) {
                    $artist_fa = $artist_from_detail;
                }
            }
        }
        
        // EN: Download New Music Roozbeh Bemani – Boose Akhar
        if (preg_match('/Download New Music\s+([A-Za-z\s]+)\s+[–-]\s+([A-Za-z\s]+)/', $plain, $m)) {
            $artist_en = trim($m[1]);
            $track_en = trim($m[2]);
        } elseif (preg_match('/([A-Za-z\s]+)\s+[–-]\s+([A-Za-z\s]+)/', $plain, $m)) {
            $maybe_artist = trim($m[1]);
            $maybe_track = trim($m[2]);
            if (preg_match('/^[A-Za-z\s]{3,50}$/', $maybe_artist) && preg_match('/^[A-Za-z\s]{2,60}$/', $maybe_track)) {
                if (empty($artist_en)) $artist_en = $maybe_artist;
                if (empty($track_en)) $track_en = $maybe_track;
            }
        }
        
        // اگر هنوز EN خالی است، از اسلاگ فارسی نمی‌توان EN ساخت، ولی از dl link می‌توان
        $dl_links = $xpath->query('//a[contains(@href, "dl.ahaang.com")]/@href');
        foreach ($dl_links as $dl) {
            $url = $dl->nodeValue;
            // URL مثل https://dl.ahaang.com/05/07/21/Roozbeh%20Bemani%20-%20Boose%20Akhar.mp3
            if (preg_match('#/([^/]+)\s*-\s*([^/]+)\.mp3#i', urldecode($url), $mm)) {
                if (empty($artist_en)) $artist_en = trim($mm[1]);
                if (empty($track_en)) $track_en = trim(str_replace(array('(128)', '[128]'), '', $mm[2]));
            }
        }
        
        // اعتبارات
        $credits = array();
        if (preg_match('/آهنگساز\s+([^\n]+?)\s+تنظیم/u', $plain, $m)) $credits['composer'] = trim($m[1]);
        elseif (preg_match('/آهنگساز\s+:\s*([^\n]+?)\s+(?:تنظیم|ترانه)/u', $plain, $m)) $credits['composer'] = trim($m[1]);
        if (preg_match('/تنظیم\s+([^\n]+?)\s+ترانه/u', $plain, $m)) $credits['arranger'] = trim($m[1]);
        elseif (preg_match('/تنظیم\s+:\s*([^\n]+?)\s+(?:ترانه|نوازنده)/u', $plain, $m)) $credits['arranger'] = trim($m[1]);
        if (preg_match('/ترانه سرا\s+([^\n]+?)\s+نوازنده/u', $plain, $m)) $credits['lyricist'] = trim($m[1]);
        elseif (preg_match('/ترانه سرا\s+:\s*([^\n]+?)\s+(?:نوازنده|تولید)/u', $plain, $m)) $credits['lyricist'] = trim($m[1]);
        if (preg_match('/نوازنده\s+([^\n]+?)\s+تولید/u', $plain, $m)) $credits['musician'] = trim($m[1]);
        if (preg_match('/تولید\s+([^\n]+?)\s+سبک/u', $plain, $m)) $credits['producer'] = trim($m[1]);
        if (preg_match('/سبک\s+([^\n]+?)\s+کد آهنگ/u', $plain, $m)) $credits['genre'] = trim($m[1]);
        
        foreach ($credits as $k => $v) {
            $v = trim($v, " \t\n\r\0\x0B-–—:");
            $v = preg_replace('/\s+/', ' ', $v);
            if (mb_strlen($v) > 100) $v = mb_substr($v, 0, 100);
            $credits[$k] = $v;
        }
        
        $details['credits'] = $credits;
        $details['lyricist'] = $credits['lyricist'] ?? '';
        $details['composer'] = $credits['composer'] ?? '';
        $details['arranger'] = $credits['arranger'] ?? '';
        $details['genre'] = $credits['genre'] ?? 'پاپ';
        $details['producer'] = $credits['producer'] ?? '';
        
        // کاور
        $cover = '';
        $og_image = $xpath->query('//meta[@property="og:image"]/@content');
        if ($og_image->length > 0) $cover = $og_image->item(0)->nodeValue;
        if (empty($cover)) {
            $imgs = $xpath->query('//img[contains(@src, "wp-content/uploads")]/@src');
            foreach ($imgs as $img) {
                $src = $img->nodeValue;
                if (strpos($src, 'data:image') === 0) continue;
                // ترجیحاً 300x300 یا اصلی
                if (filter_var($src, FILTER_VALIDATE_URL)) { $cover = $src; break; }
            }
        }
        $details['cover_url'] = $cover;
        
        // MP3 از dl.ahaang.com
        $mp3_128 = ''; $mp3_320 = '';
        $mp3_nodes = $xpath->query('//a[contains(@href, "dl.ahaang.com") and contains(@href, ".mp3")]/@href');
        foreach ($mp3_nodes as $node) {
            $url = trim($node->nodeValue);
            if (empty($url)) continue;
            if (strpos($url, '//') === 0) $url = 'https:' . $url;
            if (stripos($url, '128') !== false || stripos($url, '(128)') !== false) {
                if (empty($mp3_128)) $mp3_128 = $url;
            } else {
                if (empty($mp3_320)) $mp3_320 = $url;
            }
        }
        $details['audio_url_128'] = $mp3_128;
        $details['audio_url_320'] = $mp3_320;
        
        // کد آهنگ و تاریخ
        if (preg_match('/کد آهنگ\s+(\d+)/u', $plain, $m)) {
            $details['external_id'] = $m[1];
            $details['code'] = $m[1];
        }
        if (preg_match('/تاریخ انتشار\s+([^\n]+)/u', $plain, $m)) {
            $details['release_date'] = trim($m[1]);
        }
        
        // عنوان فارسی و انگلیسی نهایی
        if (!empty($artist_fa)) $details['artist_fa'] = $this->clean_fa($artist_fa);
        if (!empty($track_fa)) $details['title_fa'] = $this->clean_fa($track_fa);
        if (!empty($artist_en)) $details['artist_en'] = $this->clean_en($artist_en);
        if (!empty($track_en)) $details['title_en'] = $this->clean_en($track_en);
        
        if (!empty($details['title_fa']) && !empty($details['artist_fa'])) {
            $details['title_fa'] = $this->clean_fa_title($details['title_fa'], $details['artist_fa']);
        }
        
        // متن ترانه - ahaang معمولاً متن کامل دارد
        $lyrics = $this->extract_lyrics($dom, $xpath, $html);
        $details['lyrics'] = $lyrics;
        $details['music_text'] = $lyrics;
        
        return $details;
    }
    
    private function clean_fa($text) {
        $text = trim($text);
        $text = preg_replace('/^دانلود آهنگ جدید\s*/u', '', $text);
        $text = preg_replace('/^دانلود آهنگ\s*/u', '', $text);
        $text = trim($text, " :–—-|");
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }
    
    private function clean_en($text) {
        $text = trim($text);
        $text = trim($text, " :–—-|");
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
            $remaining = ltrim($remaining, " -–—|");
            if (!empty($remaining)) $fa_title = $remaining;
        }
        $fa_title = trim($fa_title, " -–—|");
        $fa_title = preg_replace('/\s+/', ' ', $fa_title);
        return $fa_title;
    }
    
    private function extract_lyrics($dom, $xpath, $html) {
        $lyrics = '';
        // ahaang: متن آهنگ در صفحه اصلی یا در /lyric/...
        // در صفحه اصلی، بعد از "متن آهنگ بوسه آخر" یا داخل <p> های متوالی
        $nodes = $xpath->query('//*[contains(text(), "متن آهنگ")]/following-sibling::p');
        $collected = array();
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if (empty($text)) continue;
            if (mb_strpos($text, 'دانلود') !== false && mb_strlen($text) < 50) break;
            if (mb_strpos($text, 'جدیدترین آثار') !== false) break;
            if (preg_match('/[\x{0600}-\x{06FF}]{5,}/u', $text) || strpos($text, '♪') !== false) {
                $collected[] = $text;
            }
            if (count($collected) > 50) break;
        }
        if (!empty($collected)) $lyrics = implode("\n\n", $collected);
        
        // fallback: regex بین متن آهنگ و جدیدترین آثار
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            if (preg_match('/متن آهنگ.*?<\/a>\s*(.+?)\s*جدیدترین آثار/su', $html, $m)) {
                $inner = $m[1];
                $inner = preg_replace('/<br\s*\/?>/i', "\n", $inner);
                $inner = strip_tags($inner);
                $inner = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $lyrics = trim($inner);
            }
        }
        
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
            if (mb_strpos($line, 'دانلود آهنگ با کیفیت') !== false) continue;
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
        if (strpos($slug_lower, 'live') !== false) return 'live';
        return 'single';
    }
    
    private function update_status($success, $error = null) {
        $status = array('time' => current_time('mysql'),'success' => $success ? current_time('mysql') : null,'error' => $error,);
        $existing = get_option('tscout_provider_status_ahaang', array());
        if (isset($existing['success']) && !$success) $status['success'] = $existing['success'];
        update_option('tscout_provider_status_ahaang', $status, false);
    }
}
