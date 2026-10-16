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
        // Check if provider is enabled in settings
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies'][$this->get_id()]) ? $options['provider_policies'][$this->get_id()] : array();
        
        // Default enabled
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
            'rate_limit' => 2, // seconds
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
        
        // Build search URL
        $search_url = self::BASE_URL . self::SEARCH_PATH . urlencode($artist_en);
        
        // Validate URL
        $valid = UrlValidator::validate($search_url);
        if (is_wp_error($valid)) {
            return $valid;
        }
        
        // Fetch search results
        $response = $this->fetch_url($search_url);
        if (is_wp_error($response)) {
            $this->update_status(false, $response->get_error_message());
            return $response;
        }
        
        // Parse search results
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
        
        // Rate limiting
        $last_request = get_transient('tscout_last_request_' . $this->get_id());
        if ($last_request) {
            $elapsed = time() - $last_request;
            $rate_limit = 2; // seconds
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
        
        // Use DOMDocument to parse HTML
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Handle UTF-8
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return new \WP_Error('parse_error', 'خطا در تجزیه صفحه نتایج');
        }
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        
        // Find all links that look like track pages: /{numeric_id}/{slug}
        $links = $xpath->query('//a[contains(@href, "mybia2music.com/") and @href]');
        
        $seen_urls = array();
        $count = 0;
        
        foreach ($links as $link) {
            if ($count >= $limit) {
                break;
            }
            
            $href = $link->getAttribute('href');
            
            // Normalize URL
            if (strpos($href, '//') === 0) {
                $href = 'https:' . $href;
            } elseif (strpos($href, '/') === 0) {
                $href = self::BASE_URL . $href;
            }
            
            // Must match pattern: https://www.mybia2music.com/{id}/{slug}
            if (!preg_match('#https?://(?:www\.)?mybia2music\.com/(\d+)/([a-z0-9\-]+)#i', $href, $matches)) {
                continue;
            }
            
            $external_id = $matches[1];
            $slug = $matches[2];
            
            // Avoid duplicates
            if (isset($seen_urls[$href])) {
                continue;
            }
            $seen_urls[$href] = true;
            
            // Skip non-music pages (playlists, artists, genres, etc)
            $skip_patterns = array('/playlist/', '/artists/', '/genre/', '/mood/', '/playlists');
            $should_skip = false;
            foreach ($skip_patterns as $pattern) {
                if (strpos($href, $pattern) !== false) {
                    $should_skip = true;
                    break;
                }
            }
            if ($should_skip) {
                continue;
            }
            
            // Try to get title from link
            $title = trim($link->getAttribute('title'));
            if (empty($title)) {
                $title = trim($link->textContent);
            }
            
            // Basic track info from search result
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
            
            // Filter by type if requested
            if (!empty($types) && !in_array($track['type'], $types, true)) {
                // Still include if type is unknown or if filtering is not strict
                // For now, include all
            }
            
            $tracks[] = $track;
            $count++;
        }
        
        // If we have tracks, try to enrich them with detailed info (limited to first few to avoid rate limiting)
        $enriched = array();
        $enrich_limit = min(10, count($tracks)); // Only enrich first 10 to avoid heavy load
        
        for ($i = 0; $i < count($tracks); $i++) {
            if ($i < $enrich_limit) {
                $details = $this->get_track_details($tracks[$i]['source_url']);
                if (!is_wp_error($details)) {
                    $enriched[] = array_merge($tracks[$i], $details);
                } else {
                    $enriched[] = $tracks[$i];
                }
                
                // Small delay to respect rate limiting
                if ($i < $enrich_limit - 1) {
                    usleep(500000); // 0.5 second
                }
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
        
        // Extract title
        $title_nodes = $xpath->query('//title');
        if ($title_nodes->length > 0) {
            $details['page_title'] = trim($title_nodes->item(0)->textContent);
        }
        
        // Extract cover image - look for og:image or main image
        $cover = '';
        $og_image = $xpath->query('//meta[@property="og:image"]/@content');
        if ($og_image->length > 0) {
            $cover = $og_image->item(0)->nodeValue;
        } else {
            // Look for images in the page that contain the track ID
            $images = $xpath->query('//img[contains(@src, "mybia2music.com")]/@src');
            foreach ($images as $img) {
                $src = $img->nodeValue;
                if (strpos($src, 'thumbs') !== false || strpos($src, 'assets') !== false) {
                    // Skip thumbnails, try to find original
                    continue;
                }
                if (filter_var($src, FILTER_VALIDATE_URL)) {
                    $cover = $src;
                    break;
                }
            }
            // Fallback to any mybia2music image
            if (empty($cover)) {
                $images = $xpath->query('//img[contains(@src, "mybia2music")]/@src');
                if ($images->length > 0) {
                    // Try to get larger version
                    $src = $images->item(0)->nodeValue;
                    if (strpos($src, '_300.jpg') !== false) {
                        $src = str_replace('_300.jpg', '_500.jpg', $src);
                    } elseif (strpos($src, '_800.jpg') !== false) {
                        // Keep as is
                    }
                    $cover = $src;
                }
            }
        }
        
        // Look for direct cover link in the HTML pattern observed: sv2.mybia2music.com/.../Artist - Title.jpg
        if (preg_match('#https?://sv\d*\.mybia2music\.com/[^\s"\']+\.jpg#i', $html, $m)) {
            $cover = $m[0];
        }
        
        $details['cover_url'] = $cover;
        
        // Extract MP3 links
        $mp3_128 = '';
        $mp3_320 = '';
        
        // Pattern: links with [128] and direct mp3
        if (preg_match_all('#https?://sv\d*\.mybia2music\.com/[^\s"\']+\.mp3#i', $html, $matches)) {
            foreach ($matches[0] as $mp3_url) {
                // Clean URL
                $mp3_url = html_entity_decode($mp3_url);
                if (strpos($mp3_url, '[128]') !== false || strpos($mp3_url, '%5B128%5D') !== false) {
                    $mp3_128 = $mp3_url;
                } else {
                    $mp3_320 = $mp3_url;
                }
            }
        }
        
        // Also look for <a> tags with download text
        $download_links = $xpath->query('//a[contains(@href, ".mp3")]/@href');
        foreach ($download_links as $dl) {
            $url = $dl->nodeValue;
            if (strpos($url, '[128]') !== false || stripos($url, '128') !== false) {
                if (empty($mp3_128)) $mp3_128 = $url;
            } else {
                if (empty($mp3_320)) $mp3_320 = $url;
            }
        }
        
        $details['audio_url_128'] = $mp3_128;
        $details['audio_url_320'] = $mp3_320;
        
        // Extract genre, mood
        $genre = '';
        $genre_nodes = $xpath->query('//a[contains(@href, "/genre/")]');
        if ($genre_nodes->length > 0) {
            $genre = trim($genre_nodes->item(0)->textContent);
        }
        $details['genre'] = $genre;
        
        $mood = '';
        $mood_nodes = $xpath->query('//a[contains(@href, "/mood/")]');
        if ($mood_nodes->length > 0) {
            $mood = trim($mood_nodes->item(0)->textContent);
        }
        $details['mood'] = $mood;
        
        // Extract artist Persian name
        $artist_fa = '';
        // Look for h1 or title patterns
        $h1_nodes = $xpath->query('//h1');
        if ($h1_nodes->length > 0) {
            $h1_text = trim($h1_nodes->item(0)->textContent);
            // Pattern: "محسن یگانه تقویم مچاله"
            // We need to separate artist and track
            // For now, keep full
            $details['title_fa'] = $h1_text;
        }
        
        // Try to parse title: "محسن یگانه تقویم مچاله Mohsen Yeganeh Taghvime Mochaleh"
        if (isset($details['page_title'])) {
            $page_title = $details['page_title'];
            // Often format: "محسن یگانه تقویم مچاله Mohsen Yeganeh Taghvime Mochaleh"
            // Split by English part
            if (preg_match('/^(.+?)\s+([A-Za-z].+)$/u', $page_title, $tm)) {
                $details['title_fa'] = trim($tm[1]);
                $details['title_en'] = trim($tm[2]);
                // Try to extract artist from Persian title
                // This is heuristic
            }
        }
        
        // Extract lyrics if present
        $lyrics = '';
        $lyric_nodes = $xpath->query('//*[contains(@id, "lyric") or contains(@class, "lyric")]');
        if ($lyric_nodes->length > 0) {
            $lyrics = trim($lyric_nodes->item(0)->textContent);
            $lyrics = mb_substr($lyrics, 0, 5000); // Limit
        }
        $details['lyrics'] = $lyrics;
        
        return $details;
    }
    
    private function detect_type_from_slug($slug) {
        $slug_lower = strtolower($slug);
        
        if (strpos($slug_lower, 'remix') !== false) {
            return 'remix';
        }
        if (strpos($slug_lower, 'live') !== false || strpos($slug_lower, 'concert') !== false) {
            return 'live';
        }
        if (strpos($slug_lower, 'album') !== false) {
            return 'album';
        }
        if (strpos($slug_lower, 'video') !== false || strpos($slug_lower, 'music-video') !== false) {
            return 'video';
        }
        if (strpos($slug_lower, 'ft-') !== false || strpos($slug_lower, 'feat') !== false) {
            return 'collab';
        }
        
        return 'single';
    }
    
    private function extract_cover_from_link($link_node, $xpath) {
        // Look for img inside the link
        $imgs = $xpath->query('.//img', $link_node);
        if ($imgs->length > 0) {
            $src = $imgs->item(0)->getAttribute('src');
            if (!empty($src)) {
                // Try to get larger version
                if (strpos($src, 'data:image') === 0) {
                    // Lazy loaded, check data-src
                    $data_src = $imgs->item(0)->getAttribute('data-src');
                    if (!empty($data_src)) {
                        $src = $data_src;
                    }
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
        
        // Keep last success time
        $existing = get_option('tscout_provider_status_mybia2music', array());
        if (isset($existing['success']) && $success) {
            // Keep existing success if new one is also success? Update to latest
        } elseif (isset($existing['success']) && !$success) {
            $status['success'] = $existing['success'];
        }
        
        update_option('tscout_provider_status_mybia2music', $status, false);
    }
}
