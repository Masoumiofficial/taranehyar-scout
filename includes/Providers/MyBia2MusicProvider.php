<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;

class MyBia2MusicProvider implements ProviderInterface {
    
    const BASE_URL = 'https://www.mybia2music.com';
    const SEARCH_PATH = '/search/';
    
    public function get_id() { return 'mybia2music'; }
    public function get_name() { return 'MyBia2Music'; }
    public function get_label() { return 'بیا2موزیک'; }
    
    public function is_available() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        if (!isset($policies['enabled'])) return true;
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
    
    public function validate_config() { return true; }
    
    public function search_artist($artist_en, $options = array()) {
        $artist_en = trim((string) $artist_en);
        if (empty($artist_en)) return new \WP_Error('empty_artist', 'نام هنرمند خالی است.');
        $limit = isset($options['limit']) ? intval($options['limit']) : 50;
        $limit = max(1, min(100, $limit));
        $types = isset($options['types']) ? (array) $options['types'] : array();
        Logger::info('MyBia2Music search: ' . $artist_en, array('provider' => $this->get_id()));
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
        Logger::info('MyBia2Music search completed', array('provider' => $this->get_id(), 'found' => count($tracks)));
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
            'user-agent' => 'TaranehyarScout/1.1 (+https://etehadwp.com/) - MyBia2Music',
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
            Logger::error('MyBia2Music fetch failed: ' . $url, array('error' => $response->get_error_message()));
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
            return new \WP_Error('parse_error', 'خطا در تجزیه نتایج بیا2موزیک');
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        
        // فقط آهنگ‌ها: /114736380/roozbeh-bemani-boose-akhar - عدد ID + اسلاگ
        // فیلتر playlist, artists, genre, mood, publisher
        $links = $xpath->query('//a[contains(@href, "mybia2music.com/") and @href]');
        $seen = array();
        $count = 0;
        
        foreach ($links as $link) {
            if ($count >= $limit) break;
            $href = $link->getAttribute('href');
            if (strpos($href, '//') === 0) $href = 'https:' . $href;
            elseif (strpos($href, '/') === 0) $href = self::BASE_URL . $href;
            
            // فقط URL با الگوی /ID/slug
            if (!preg_match('#https?://(?:www\.)?mybia2music\.com/(\d+)/([a-z0-9\-]+)#i', $href, $m)) continue;
            $external_id = $m[1];
            $slug = $m[2];
            
            if (isset($seen[$href])) continue;
            $seen[$href] = true;
            
            // فیلترهای اضافی
            $skip = array('/playlist/', '/artists/', '/genre/', '/mood/', '/publisher/', '/playlists');
            $should_skip = false;
            foreach ($skip as $s) {
                if (strpos($href, $s) !== false) { $should_skip = true; break; }
            }
            if ($should_skip) continue;
            
            $title = trim($link->getAttribute('title'));
            if (empty($title)) $title = trim($link->textContent);
            if (empty($title) || mb_strlen($title) < 2) continue;
            if (mb_strlen($title) > 200) continue;
            
            // استخراج artist_fa و track_fa از عنوان فارسی "دانلود آهنگ مجید اصلاحی قدم بزنیم"
            $artist_fa_from_title = '';
            $track_fa_from_title = '';
            $clean_title_for_parse = trim($title);
            $clean_title_for_parse = preg_replace('/^دانلود آهنگ جدید\s*/u', '', $clean_title_for_parse);
            $clean_title_for_parse = preg_replace('/^دانلود آهنگ\s*/u', '', $clean_title_for_parse);
            $clean_title_for_parse = trim($clean_title_for_parse);
            if (!empty($clean_title_for_parse) && preg_match('/[\x{0600}-\x{06FF}]/u', $clean_title_for_parse)) {
                $words = preg_split('/\s+/u', $clean_title_for_parse);
                if (count($words) >= 3) {
                    // تشخیص 2 یا 3 کلمه‌ای بودن نام خواننده
                    $is_three_word = false;
                    if (count($words) >= 5) $is_three_word = true; // وحید حاجی تبار شب شعر = 5 کلمه
                    if (isset($words[1]) && $words[1] === 'حاجی') $is_three_word = true;
                    if (isset($words[1]) && mb_strpos($words[1], 'الله') !== false) $is_three_word = true;
                    // اگر اسلاگ هنرمند 2 بخشی ولی فارسی 3 بخشی است، مثل vahid-hajitabar
                    if (preg_match('/^[a-z]+-hajitabar-/i', $slug)) $is_three_word = true;
                    
                    if ($is_three_word && count($words) >= 4) {
                        $artist_fa_from_title = $words[0] . ' ' . $words[1] . ' ' . $words[2];
                        $track_fa_from_title = implode(' ', array_slice($words, 3));
                    } else {
                        $artist_fa_from_title = $words[0] . ' ' . $words[1];
                        $track_fa_from_title = implode(' ', array_slice($words, 2));
                    }
                    // پاکسازی
                    $artist_fa_from_title = trim($artist_fa_from_title);
                    $track_fa_from_title = trim($track_fa_from_title);
                    if (mb_strlen($track_fa_from_title) < 2) $track_fa_from_title = '';
                }
            }
            // استخراج artist_en و track_en از اسلاگ
            $artist_en_from_slug = '';
            $track_en_from_slug = '';
            $slug_parts = explode('-', $slug);
            if (count($slug_parts) >= 3) {
                // حدس: 2 بخش اول هنرمند، بقیه آهنگ (برای majid-eslahi-ghadam-bezanim)
                // اگر 4 بخش یا بیشتر، 2+بقیه
                // اگر اسلاگ با hajitabar شروع، 2 بخش هنرمند
                $artist_slug_guess = implode('-', array_slice($slug_parts, 0, 2));
                $track_slug_guess = implode('-', array_slice($slug_parts, 2));
                // اگر track_slug_guess شامل hajitabar بود، یعنی هنرمند 2 بخشی نیست؟ ولی برای vahid-hajitabar-shabe-sher، artist 2 بخشی درست است
                $artist_en_from_slug = $this->slug_to_en_name($artist_slug_guess);
                $track_en_from_slug = $this->slug_to_en_name($track_slug_guess);
            }
            
            $track = array(
                'external_id' => $external_id,
                'provider' => $this->get_id(),
                'source_url' => $href,
                'slug' => $slug,
                'title' => $title,
                'title_fa' => !empty($track_fa_from_title) ? $track_fa_from_title : $clean_title_for_parse,
                'artist_fa' => $artist_fa_from_title,
                'artist_en' => !empty($artist_en_from_slug) ? $artist_en_from_slug : $artist_en,
                'title_en' => $track_en_from_slug,
                'track_en_from_slug' => $track_en_from_slug,
                'artist_slug_en' => isset($artist_slug_guess) ? $artist_slug_guess : '',
                'type' => $this->detect_type_from_slug($slug),
                'cover_url' => $this->extract_cover_from_link($link, $xpath),
            );
            $tracks[] = $track;
            $count++;
        }
        
        // Enrich همه نتایج تا limit با جزئیات کامل (برای شناسایی موزیک‌ها - مجید اصلاحی 40+ نتیجه)
        // قبلاً 10 بود، الان همه تا limit
        $enriched = array();
        $enrich_limit = min($limit, count($tracks));
        // برای جلوگیری از بلاک، حداکثر 40 تا enrich می‌کنیم
        $enrich_limit = min($enrich_limit, 40);
        for ($i = 0; $i < count($tracks); $i++) {
            if ($i < $enrich_limit) {
                $details = $this->get_track_details($tracks[$i]['source_url']);
                if (!is_wp_error($details)) {
                    if (empty($details['artist_en']) && !empty($artist_en)) $details['artist_en'] = $artist_en;
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
        
        // اسلاگ آهنگ از URL - اول استخراج می‌کنیم تا برای تشخیص هنرمند اصلی استفاده کنیم
        $track_slug_full = '';
        if (preg_match('#/(\d+)/([a-z0-9\-]+)#i', $source_url, $sm)) {
            $track_slug_full = strtolower($sm[2]);
            $details['track_slug_full'] = $track_slug_full;
        }
        
        // هنرمند فارسی از /artists/ - با اولویت اسلاگی که پیشوند ترک اسلاگ است
        $artist_fa = '';
        $artist_slug_en = '';
        $artist_links = $xpath->query('//a[contains(@href, "/artists/")]');
        $candidates = array();
        if ($artist_links->length > 0) {
            foreach ($artist_links as $alink) {
                $text = trim($alink->textContent);
                $href = $alink->getAttribute('href');
                if (preg_match('#/artists/([a-z0-9\-]+)#i', $href, $m)) {
                    $slug_candidate = strtolower($m[1]);
                    if (!empty($text) && mb_strlen($text) > 1 && mb_strlen($text) < 100) {
                        // فقط متن‌های فارسی
                        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
                            $candidates[] = array('fa' => $text, 'slug' => $slug_candidate, 'href' => $href);
                        }
                    }
                }
            }
        }
        // انتخاب هنرمند اصلی: اسلاگی که پیشوند track_slug_full است (مثل vahid-hajitabar در vahid-hajitabar-shabe-sher)
        if (!empty($candidates) && !empty($track_slug_full)) {
            foreach ($candidates as $cand) {
                if (strpos($track_slug_full, $cand['slug'] . '-') === 0 || $track_slug_full === $cand['slug']) {
                    $artist_fa = $cand['fa'];
                    $artist_slug_en = $cand['slug'];
                    break;
                }
            }
        }
        // اگر هنوز پیدا نشد، از بین کاندیدها، طولانی‌ترین نام فارسی که در عنوان صفحه هم هست را انتخاب کن
        if (empty($artist_fa) && !empty($candidates)) {
            // سعی کن از breadcrumb: بعد از بیاتوموزیک
            // در HTML: [بیاتوموزیک][وحید حاجی تبار] - وحید حاجی تبار اصلی است
            // کاندید آخر معمولاً هنرمند اصلی است (چون آهنگساز و تنظیم‌کننده اول هستند)
            // پس از آخر به اول می‌گردیم
            $candidates_reversed = array_reverse($candidates);
            foreach ($candidates_reversed as $cand) {
                // اگر نام کاندید در page_title فارسی وجود دارد
                if (isset($details['page_title']) && mb_strpos($details['page_title'], $cand['fa']) !== false) {
                    $artist_fa = $cand['fa'];
                    $artist_slug_en = $cand['slug'];
                    break;
                }
            }
            if (empty($artist_fa)) {
                // آخرین کاندید را به عنوان هنرمند اصلی بگیر
                $last = end($candidates_reversed);
                if ($last) {
                    $artist_fa = $last['fa'];
                    $artist_slug_en = $last['slug'];
                }
            }
        }
        // fallback نهایی: اگر هنوز خالی، از page_title استخراج کن
        if (empty($artist_fa) && isset($details['page_title'])) {
            $pt = $details['page_title'];
            // حذف پیشوند
            $pt_clean = preg_replace('/^دانلود آهنگ جدید\s*/u', '', $pt);
            $pt_clean = preg_replace('/^دانلود آهنگ\s*/u', '', $pt_clean);
            $pt_clean = trim($pt_clean);
            // جدا کردن بخش فارسی تا اولین حرف لاتین
            if (preg_match('/^([\x{0600}-\x{06FF}\s]+)/u', $pt_clean, $mm)) {
                $fa_part = trim($mm[1]); // مثلا "وحید حاجی تبار شب شعر" یا "محسن یگانه تقویم مچاله"
                $words = preg_split('/\s+/u', $fa_part);
                if (count($words) >= 2) {
                    // سعی کن 3 کلمه اول را به عنوان نام خواننده امتحان کنی، سپس 2 کلمه
                    $three_words = count($words) >= 3 ? $words[0] . ' ' . $words[1] . ' ' . $words[2] : '';
                    $two_words = $words[0] . ' ' . $words[1];
                    // اگر candidates داریم، بررسی کن کدام یک در ابتدای fa_part است
                    $found = false;
                    foreach ($candidates as $cand) {
                        if (mb_strpos($fa_part, $cand['fa']) === 0) {
                            $artist_fa = $cand['fa'];
                            $artist_slug_en = $cand['slug'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        // اگر 3 کلمه اول شبیه نام (هر کلمه فارسی) و طول مناسب، آن را به عنوان هنرمند بگیر
                        if (!empty($three_words) && mb_strlen($three_words) <= 30 && count($words) >= 4) {
                            // اگر کل عبارت 4 کلمه یا بیشتر است، احتمالاً 2 یا 3 کلمه اول هنرمند است
                            // برای "وحید حاجی تبار شب شعر" -> 4 کلمه، هنرمند 3 کلمه
                            // برای "محسن یگانه تقویم مچاله" -> 4 کلمه، هنرمند 2 کلمه
                            // حدس: اگر کلمه سوم "تبار" یا "یگانه" یا ... باشد، 3 کلمه‌ای است
                            // ساده: اگر تعداد کلمات 4 باشد، 2 حالت را امتحان کن و ببین کدام در عنوان انگلیسی هم هست؟
                            // فعلاً: اگر کلمه سوم طولش >2 و کلمه چهارم طولش >2، 2 کلمه اول هنرمند
                            // ولی برای وحید حاجی تبار، کلمه سوم "تبار" است و کلمه چهارم "شب" است
                            // برای تشخیص، از تعداد کلمات استفاده می‌کنیم: اگر 5 کلمه، احتمال 3 کلمه هنرمند
                            if (count($words) == 4) {
                                // 2 کلمه هنرمند + 2 کلمه آهنگ یا 3+1
                                // بررسی کن آیا 3 کلمه اول در لیست اسامی رایج 3 کلمه‌ای است؟
                                // لیست ساده از نام‌های 3 کلمه‌ای معروف
                                $three_word_names = array('وحید حاجی تبار', 'روزبه بمانی', 'محسن چاوشی'); // روزبه بمانی 2 کلمه است ولی...
                                // اگر 3 کلمه اول شامل "حاجی" باشد، 3 کلمه‌ای است
                                if (mb_strpos($three_words, 'حاجی') !== false || mb_strpos($three_words, 'الله') !== false) {
                                    $artist_fa = $three_words;
                                } else {
                                    $artist_fa = $two_words;
                                }
                            } elseif (count($words) >= 5) {
                                $artist_fa = $three_words;
                            } else {
                                $artist_fa = $two_words;
                            }
                        } else {
                            $artist_fa = $two_words;
                        }
                    }
                }
            }
        }
        
        if (!empty($artist_fa)) $details['artist_fa'] = $this->clean_fa($artist_fa);
        if (!empty($artist_slug_en)) {
            $details['artist_slug_en'] = $artist_slug_en;
            $details['artist_en'] = $this->slug_to_en_name($artist_slug_en);
        }
        
        $track_en_from_slug = '';
        $track_slug_en = $track_slug_full;
        if (!empty($artist_slug_en) && !empty($track_slug_full) && strpos($track_slug_full, $artist_slug_en . '-') === 0) {
            $track_slug_en = substr($track_slug_full, strlen($artist_slug_en) + 1);
        }
        if (!empty($track_slug_en)) {
            $track_en_from_slug = $this->slug_to_en_name($track_slug_en);
            $details['title_en'] = $track_en_from_slug;
            $details['track_en_from_slug'] = $track_en_from_slug;
        }
        
        // کاور - اولویت: og:image, سپس sv*.mybia2music.com jpg, سپس thumbs _500
        $cover = '';
        $og_image = $xpath->query('//meta[@property="og:image"]/@content');
        if ($og_image->length > 0) $cover = $og_image->item(0)->nodeValue;
        // sv2.mybia2music.com/.../Roozbeh Bemani - Boose Akhar.jpg
        if (preg_match('#https?://sv\d*\.mybia2music\.com/[^\s"\'<>]+\.jpg#i', $html, $m)) {
            $cover_candidate = html_entity_decode($m[0]);
            // اگر کاور اصلی پیدا شد، اولویت با آن است (نه _300)
            if (strpos($cover_candidate, '_300.jpg') === false || empty($cover)) {
                $cover = $cover_candidate;
            }
        }
        if (empty($cover)) {
            $images = $xpath->query('//img[contains(@src, "mybia2music.com/assets/thumbs/")]/@src');
            foreach ($images as $img) {
                $src = $img->nodeValue;
                if (strpos($src, 'data:image') === 0) continue;
                // تبدیل _300 به _500
                $src_500 = str_replace('_300.jpg', '_500.jpg', $src);
                $src_500 = str_replace('_300', '_500', $src_500);
                if (filter_var($src_500, FILTER_VALIDATE_URL)) { $cover = $src_500; break; }
            }
        }
        $details['cover_url'] = $cover;
        
        // MP3 - sv2.mybia2music.com/.../Boose Akhar.mp3 و [128]
        $mp3_128 = ''; $mp3_320 = '';
        if (preg_match_all('#https?://sv\d*\.mybia2music\.com/[^\s"\'<>]+\.mp3#i', $html, $matches)) {
            foreach ($matches[0] as $mp3_url) {
                $mp3_url = html_entity_decode($mp3_url);
                if (strpos($mp3_url, '[128]') !== false || strpos($mp3_url, '%5B128%5D') !== false || stripos($mp3_url, '128') !== false) {
                    // اگر قبلاً 128 نداریم یا این دقیقاً [128] دارد
                    if (empty($mp3_128) || strpos($mp3_url, '[128]') !== false) $mp3_128 = $mp3_url;
                } else {
                    if (empty($mp3_320)) $mp3_320 = $mp3_url;
                }
            }
        }
        // fallback از لینک‌های دانلود
        $dl_nodes = $xpath->query('//a[contains(@href, ".mp3")]/@href');
        foreach ($dl_nodes as $dl) {
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
        
        // ژانر و حال و هوا
        $genre = ''; $genre_nodes = $xpath->query('//a[contains(@href, "/genre/")]');
        if ($genre_nodes->length > 0) $genre = trim($genre_nodes->item(0)->textContent);
        $details['genre'] = $genre;
        $mood = ''; $mood_nodes = $xpath->query('//a[contains(@href, "/mood/")]');
        if ($mood_nodes->length > 0) $mood = trim($mood_nodes->item(0)->textContent);
        $details['mood'] = $mood;
        
        // اعتبارات دقیق
        $plain_text = html_entity_decode(strip_tags($html));
        $plain_text = preg_replace('/\s+/', ' ', $plain_text);
        $credits = array();
        if (preg_match('/ترانه\s*سرا\s*:\s*([^\n]+?)\s*(?:آهنگساز|تنظیم|میکس|سبک|حال|نوازنده|$)/u', $plain_text, $m)) $credits['lyricist'] = trim($m[1]);
        if (preg_match('/آهنگساز\s*:\s*([^\n]+?)\s*(?:تنظیم|میکس|سبک|حال|نوازنده|$)/u', $plain_text, $m)) $credits['composer'] = trim($m[1]);
        if (preg_match('/تنظیم\s*کننده\s*:\s*([^\n]+?)\s*(?:میکس|سبک|حال|نوازنده|$)/u', $plain_text, $m)) $credits['arranger'] = trim($m[1]);
        if (preg_match('/میکس\s*و\s*مستر\s*:\s*([^\n]+?)\s*(?:سبک|حال|نوازنده|$)/u', $plain_text, $m)) $credits['mix_master'] = trim($m[1]);
        if (preg_match('/نوازنده\s*(?:گیتار)?\s*:\s*([^\n]+?)\s*(?:سبک|حال|ناشر|$)/u', $plain_text, $m)) $credits['musician'] = trim($m[1]);
        
        foreach ($credits as $k => $v) {
            $v = preg_replace('/\s+/', ' ', $v);
            $v = trim($v, " \t\n\r\0\x0B-–—");
            $v = str_replace('بیاتوموزیک', '', $v);
            $v = trim($v);
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
            $details['artist_fa'] = $artist_fa;
        }
        
        // عنوان فارسی و انگلیسی از page_title: "دانلود آهنگ روزبه بمانی بوسه آخر Roozbeh Bemani Boose Akhar"
        $title_fa = '';
        $title_en = $track_en_from_slug;
        
        if (isset($details['page_title'])) {
            $page_title = trim($details['page_title']);
            // جدا کردن بخش فارسی و انگلیسی
            if (preg_match('/^(.+?[\x{0600}-\x{06FF}].*?)\s+([A-Za-z][A-Za-z0-9\s\-]+)$/u', $page_title, $tm)) {
                $fa_part = trim($tm[1]);
                $en_part = trim($tm[2]);
                $fa_part = $this->clean_fa_title($fa_part, $artist_fa);
                if (!empty($fa_part)) $title_fa = $fa_part;
                $en_part_clean = $this->clean_en_title($en_part, $details['artist_en'] ?? '');
                if (!empty($en_part_clean)) $title_en = $en_part_clean;
            } else {
                $fa_clean = $this->clean_fa_title($page_title, $artist_fa);
                if (!empty($fa_clean)) $title_fa = $fa_clean;
            }
        }
        
        if (empty($title_fa)) {
            $h1_nodes = $xpath->query('//h1');
            if ($h1_nodes->length > 0) {
                $h1_text = trim($h1_nodes->item(0)->textContent);
                $h1_clean = $this->clean_fa_title($h1_text, $artist_fa);
                if (!empty($h1_clean)) $title_fa = $h1_clean;
            }
        }
        
        // اگر title_fa هنوز شامل بوسه آخر از روزبه بمانی است، تمیز کن
        if (!empty($title_fa) && strpos($title_fa, ' از ') !== false && !empty($artist_fa)) {
            if (mb_strpos($title_fa, $artist_fa) !== false) {
                $parts = explode(' از ', $title_fa);
                $first = trim($parts[0]);
                if (!empty($first) && mb_strlen($first) < 100) $title_fa = $first;
            }
        }
        
        if (!empty($title_fa)) $details['title_fa'] = $title_fa;
        if (!empty($title_en)) $details['title_en'] = $title_en;
        
        if (!empty($details['title_fa']) && !empty($artist_fa)) {
            $details['title_fa'] = $this->clean_fa_title($details['title_fa'], $artist_fa);
        }
        
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
        // "بوسه آخر از روزبه بمانی"
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
            if (mb_strpos($fa_title, $artist_fa . ' -') === 0 || mb_strpos($fa_title, $artist_fa . ' –') === 0) {
                $fa_title = trim(str_replace($artist_fa, '', $fa_title));
                $fa_title = ltrim($fa_title, " -–—");
            }
            if (mb_strpos($fa_title, $artist_fa . ' ') === 0) {
                $remaining = trim(mb_substr($fa_title, mb_strlen($artist_fa)));
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
    
    private function clean_en_title($en_title, $artist_en) {
        $en_title = trim($en_title);
        if (empty($en_title)) return '';
        if (!empty($artist_en)) {
            if (stripos($en_title, $artist_en) === 0) {
                $remaining = trim(substr($en_title, strlen($artist_en)));
                $remaining = ltrim($remaining, " -–—");
                if (!empty($remaining)) $en_title = $remaining;
            }
        }
        $en_title = trim($en_title, " -–—");
        return $en_title;
    }
    
    private function slug_to_en_name($slug) {
        $slug = strtolower(trim($slug));
        $slug = str_replace('-', ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug);
        $slug = trim($slug);
        $words = explode(' ', $slug);
        $words = array_map(function($w) { return ucfirst($w); }, $words);
        return implode(' ', $words);
    }
    
    private function extract_lyrics($dom, $xpath, $html) {
        $lyrics = '';
        // بیا2موزیک: متن در #lyric یا .lyric یا بعد از h2 "متن آهنگ"
        $lyric_nodes = $xpath->query('//*[@id="lyric" or contains(@id, "lyric")]');
        if ($lyric_nodes->length > 0) {
            foreach ($lyric_nodes as $ln) {
                $inner = $dom->saveHTML($ln);
                $text = $this->html_to_lyrics_text($inner);
                if (mb_strlen($text) > 20) { $lyrics = $text; break; }
            }
        }
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            $lyric_nodes = $xpath->query('//*[contains(@class, "lyric") or contains(@class, "lyrics")]');
            if ($lyric_nodes->length > 0) {
                foreach ($lyric_nodes as $ln) {
                    $inner = $dom->saveHTML($ln);
                    $text = $this->html_to_lyrics_text($inner);
                    if (mb_strlen($text) > 20) { $lyrics = $text; break; }
                }
            }
        }
        // جستجو بر اساس "متن آهنگ بوسه آخر از روزبه بمانی" سپس محتوا
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            if (preg_match('/متن آهنگ.*?<div[^>]*>(.+?)<\/div>.*?پخش آهنگ/su', $html, $m)) {
                $text = $this->html_to_lyrics_text($m[1]);
                if (mb_strlen($text) > 20) $lyrics = $text;
            }
        }
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            // از صفحه: بعد از h2 متن آهنگ تا پخش آهنگ
            $h2_nodes = $xpath->query('//h2[contains(text(), "متن آهنگ") or contains(text(), "متن ترانه")]/following-sibling::*');
            $collected = array();
            foreach ($h2_nodes as $node) {
                $text = trim($node->textContent);
                if (empty($text)) continue;
                if (mb_strpos($text, 'پخش آهنگ') !== false || mb_strpos($text, 'دانلود آهنگ با کیفیت') !== false) break;
                if (preg_match('/[\x{0600}-\x{06FF}]{10,}/u', $text) && mb_strlen($text) > 10) $collected[] = $text;
                if (count($collected) > 40) break;
            }
            if (!empty($collected)) $lyrics = implode("\n\n", $collected);
        }
        // آخرین تلاش: طولانی‌ترین پاراگراف فارسی
        if (empty($lyrics) || mb_strlen($lyrics) < 20) {
            $text_nodes = $xpath->query('//p | //div[contains(@class, "content")]');
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
        return $this->clean_lyrics_text($lyrics);
    }
    
    private function html_to_lyrics_text($html) {
        if (empty($html)) return '';
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
    }
    
    private function clean_lyrics_text($text) {
        $text = trim($text);
        if (empty($text)) return '';
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $clean_lines = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) { $clean_lines[] = ''; continue; }
            if (mb_strpos($line, 'دانلود آهنگ با کیفیت') !== false) continue;
            if (mb_strpos($line, 'پخش آهنگ') !== false && mb_strlen($line) < 20) continue;
            if (mb_strpos($line, 'بیاتوموزیک') !== false && mb_strlen($line) < 30) continue;
            $clean_lines[] = $line;
        }
        $text = implode("\n", $clean_lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $lines = explode("\n", $text);
        $lines = array_map(function($l) { return preg_replace('/[ \t]+/', ' ', trim($l)); }, $lines);
        $text = implode("\n", $lines);
        $text = trim($text, "\n ");
        if (mb_strlen($text) > 10000) $text = mb_substr($text, 0, 10000);
        return $text;
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
            if (empty($src)) $src = $imgs->item(0)->getAttribute('data-src');
            if (!empty($src) && strpos($src, 'data:image') !== 0) {
                // تبدیل _300 به _500
                $src = str_replace('_300.jpg', '_500.jpg', $src);
                return $src;
            }
        }
        return '';
    }
    
    private function update_status($success, $error = null) {
        $status = array('time' => current_time('mysql'),'success' => $success ? current_time('mysql') : null,'error' => $error,);
        $existing = get_option('tscout_provider_status_mybia2music', array());
        if (isset($existing['success']) && !$success) $status['success'] = $existing['success'];
        update_option('tscout_provider_status_mybia2music', $status, false);
    }
}
