<?php
namespace TaranehyarScout\Publishing;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Catalog\TaranehyarAdapter;
use TaranehyarScout\HostDL\HostDLAdapter;
use TaranehyarScout\Security\UrlValidator;
use TaranehyarScout\Logging\Logger;
use TaranehyarScout\Matching\Matcher;

class Publisher {
    
    private $adapter;
    private $hostdl;
    private $matcher;
    
    public function __construct() {
        $this->adapter = TaranehyarAdapter::get_instance();
        $this->hostdl = new HostDLAdapter();
        $this->matcher = new Matcher();
    }
    
    public function preview($discovery_id) {
        $discovery = $this->get_discovery($discovery_id);
        if (!$discovery) return new \WP_Error('not_found', 'اثر یافت نشد');
        if ($discovery['license_status'] === 'forbidden') return new \WP_Error('forbidden', 'استفاده از این اثر مجاز نیست');
        $match_result = $this->matcher->match($discovery);
        return array(
            'discovery' => $discovery,
            'match' => $match_result,
            'post_data' => $this->build_post_data($discovery),
            'file_status' => $this->check_file_status($discovery),
            'license_status' => $discovery['license_status'],
            'can_publish' => $this->can_publish($discovery, $match_result),
            'warnings' => $this->get_warnings($discovery, $match_result),
        );
    }
    
    public function publish($payload, $job = null) {
        $discovery_id = isset($payload['discovery_id']) ? intval($payload['discovery_id']) : 0;
        $approved_by = isset($payload['approved_by']) ? intval($payload['approved_by']) : get_current_user_id();
        $publish_status = isset($payload['publish_status']) ? sanitize_text_field($payload['publish_status']) : 'draft';
        if (!$discovery_id) return new \WP_Error('invalid_id', 'شناسه اثر نامعتبر است');
        $discovery = $this->get_discovery($discovery_id);
        if (!$discovery) return new \WP_Error('not_found', 'اثر یافت نشد');
        $state_machine = new StateMachine();
        $state_machine->transition_to(StateMachine::STATE_VALIDATING);
        $validation = $this->validate_discovery($discovery);
        if (is_wp_error($validation)) {
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $validation->get_error_message()));
            $this->log_publish($discovery_id, 'failed', $validation->get_error_message());
            return $validation;
        }
        $state_machine->transition_to(StateMachine::STATE_CHECKING_LICENSE);
        if ($discovery['license_status'] === 'forbidden') {
            $error = new \WP_Error('forbidden', 'استفاده از این اثر مجاز نیست');
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            $this->log_publish($discovery_id, 'failed', 'مجوز ندارد');
            return $error;
        }
        if ($discovery['license_status'] === 'needs_review') {
            $license_approved = isset($payload['license_approved']) ? (bool) $payload['license_approved'] : false;
            if (!$license_approved) {
                $error = new \WP_Error('license_needs_review', 'مجوز این اثر نیاز به بررسی دارد - تیک تایید مجوز را بزنید');
                $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
                return $error;
            }
        }
        $state_machine->transition_to(StateMachine::STATE_CHECKING_DUPLICATE);
        $match_result = $this->matcher->match($discovery);
        if ($match_result['is_duplicate'] && $match_result['similarity'] >= 95) {
            $error = new \WP_Error('duplicate', 'این اثر قبلاً منتشر شده است: ' . $match_result['reason']);
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            return $error;
        }
        $state_machine->transition_to(StateMachine::STATE_PREVIEW);
        $state_machine->transition_to(StateMachine::STATE_AWAITING_APPROVAL);
        $state_machine->transition_to(StateMachine::STATE_PREPARING_METADATA);
        $post_data = $this->build_post_data($discovery);
        $post_data['status'] = $publish_status;
        $file_transferred = false;
        $transfer_data = array();
        if ($this->should_transfer_file($discovery, $payload)) {
            $state_machine->transition_to(StateMachine::STATE_TRANSFERRING_FILE);
            $file_name = $this->build_file_name($discovery);
            $transfer_title = !empty($discovery['title_en']) ? $discovery['title_en'] : $discovery['title'];
            $transfer_artist = !empty($discovery['artist_en']) ? $discovery['artist_en'] : $discovery['artist_fa'];
            $transfer_data = $this->hostdl->build_music_transfer_data($discovery, $transfer_artist, $transfer_title, $file_name);
            if (!empty($transfer_data['link320'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link320'], array('mp3'));
                if (is_wp_error($valid)) { Logger::warning('File validation failed', array('url' => $transfer_data['link320'], 'error' => $valid->get_error_message())); $transfer_data['link320'] = ''; }
            }
            if (!empty($transfer_data['link128'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link128'], array('mp3'));
                if (is_wp_error($valid)) $transfer_data['link128'] = '';
            }
            if ((!empty($transfer_data['link320']) || !empty($transfer_data['link128'])) && $this->hostdl->is_configured()) {
                $transfer_result = $this->hostdl->transfer($transfer_data, 'music');
                if (is_wp_error($transfer_result)) {
                    Logger::warning('File transfer failed, will use external URLs', array('discovery_id' => $discovery_id, 'error' => $transfer_result->get_error_message()));
                } else {
                    $file_transferred = true;
                    $state_machine->transition_to(StateMachine::STATE_VALIDATING_FILE);
                    $host = $this->adapter->get_download_host();
                    $archive_path = $transfer_data['archive_path'];
                    $post_data['music320'] = $this->adapter->build_file_url($host, $archive_path, $file_name . '.mp3');
                    $post_data['music128'] = $this->adapter->build_file_url($host, $archive_path, $file_name . ' [128].mp3');
                }
            }
        }
        if (!$file_transferred) {
            $options = get_option('tscout_options', array());
            $allow_external = isset($options['allow_external_urls']) ? (bool) $options['allow_external_urls'] : true;
            if ($allow_external) {
                $post_data['music320'] = isset($discovery['audio_url_320']) ? $discovery['audio_url_320'] : '';
                $post_data['music128'] = isset($discovery['audio_url_128']) ? $discovery['audio_url_128'] : '';
            }
        }
        $state_machine->transition_to(StateMachine::STATE_CREATING_POST);
        $existing_post = $this->adapter->post_exists_by_title($post_data['title']);
        if ($existing_post) {
            $error = new \WP_Error('duplicate_title', 'پستی با این عنوان قبلاً وجود دارد: #' . $existing_post);
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            return $error;
        }
        $post_id = $this->adapter->create_post($post_data);
        if (is_wp_error($post_id)) {
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $post_id->get_error_message()));
            $this->log_publish($discovery_id, 'failed', $post_id->get_error_message());
            return $post_id;
        }
        $state_machine->transition_to(StateMachine::STATE_FINAL_CHECK);
        $final_post = get_post($post_id);
        if (!$final_post) {
            $error = new \WP_Error('post_not_found', 'پست ایجاد شد اما یافت نشد');
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            return $error;
        }
        $state_machine->transition_to(StateMachine::STATE_COMPLETED);
        global $wpdb;
        $wpdb->update(TSCOUT_TABLE_DISCOVERIES, array('matched_post_id' => $post_id,'match_status' => 'published','discovery_status' => 'published','updated_at' => current_time('mysql'),), array('id' => $discovery_id));
        $this->log_publish($discovery_id, 'completed', "پست #{$post_id} ایجاد شد", $post_id);
        Logger::info('Discovery published', array('discovery_id' => $discovery_id,'post_id' => $post_id,'file_transferred' => $file_transferred,'approved_by' => $approved_by));
        return array('post_id' => $post_id,'post_url' => get_permalink($post_id),'edit_url' => get_edit_post_link($post_id, ''),'file_transferred' => $file_transferred,'state_history' => $state_machine->get_history(),);
    }
    
    private function validate_discovery($discovery) {
        if (empty($discovery['title'])) return new \WP_Error('empty_title', 'عنوان اثر خالی است');
        if (empty($discovery['artist_en']) && empty($discovery['artist_fa'])) return new \WP_Error('empty_artist', 'نام هنرمند خالی است');
        if (empty($discovery['source_url'])) return new \WP_Error('empty_source', 'آدرس منبع خالی است');
        $valid = UrlValidator::validate($discovery['source_url']);
        if (is_wp_error($valid)) return $valid;
        return true;
    }
    
    private function build_post_data($discovery) {
        $artist_fa = !empty($discovery['artist_fa']) ? trim($discovery['artist_fa']) : '';
        $track_fa = !empty($discovery['title_fa']) ? trim($discovery['title_fa']) : '';
        $artist_en = !empty($discovery['artist_en']) ? trim($discovery['artist_en']) : '';
        $track_en = !empty($discovery['title_en']) ? trim($discovery['title_en']) : '';
        $artist_slug_en = !empty($discovery['artist_slug_en']) ? trim($discovery['artist_slug_en']) : '';
        
        // تلاش برای استخراج artist_fa فارسی از metadata_json اگر فعلی انگلیسی است یا خالی
        $meta_arr = array();
        if (!empty($discovery['metadata_json'])) {
            $decoded = json_decode($discovery['metadata_json'], true);
            if (is_array($decoded)) $meta_arr = $decoded;
        }
        
        if (empty($artist_fa) || !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa)) {
            // اولویت: metadata artist_fa فارسی
            if (!empty($meta_arr['artist_fa']) && preg_match('/[\x{0600}-\x{06FF}]/u', $meta_arr['artist_fa'])) {
                $artist_fa = trim($meta_arr['artist_fa']);
            } elseif (!empty($meta_arr['credits']) && is_array($meta_arr['credits'])) {
                // composer/lyricist فارسی می‌تواند نام خواننده باشد اگر artist_fa خالی است؟
                // ولی بهتر است از lyricist فقط اگر در عنوان اصلی باشد
            }
            // از title فارسی اگر شامل دو بخش است
            if ((empty($artist_fa) || !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa)) && !empty($discovery['title_fa'])) {
                // title_fa ممکن است شامل نام آهنگ + نام خواننده یا برعکس باشد
                // الگو: "بوسه آخر از روزبه بمانی" -> track=بوسه آخر, artist=روزبه بمانی
                // یا "شب شعر" فقط track
            }
        }
        
        // اگر هنوز artist_fa انگلیسی است، سعی کن از artist_slug_en به فارسی برگردانی؟ نمی‌توان، پس لاگ کن
        if (!empty($artist_fa) && !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa)) {
            // اگر artist_fa انگلیسی است ولی metadata_json شامل lyricist فارسی است که برابر نیست، از آن استفاده نکن
            // بلکه از artist_fa اصلی در DB که باید فارسی باشد استفاده کن - اگر خالی بود، بعداً fallback
            Logger::warning('artist_fa is English, trying to recover', array('artist_fa' => $artist_fa, 'discovery_id' => $discovery['id'] ?? 0));
            // سعی کن از عنوان فارسی اولیه که شامل نام خواننده است استخراج کنی
            if (!empty($discovery['title']) && preg_match('/[\x{0600}-\x{06FF}]/u', $discovery['title'])) {
                // title ممکن است "دانلود آهنگ محسن یگانه به نام تقویم مچاله" باشد که تمیز شده
            }
        }
        
        $track_fa = $this->clean_track_fa($track_fa, $artist_fa);
        $artist_fa = $this->clean_artist_fa($artist_fa);
        
        // تضمین نهایی: اگر artist_fa هنوز انگلیسی است یا خالی، از lyricist/composer فارسی استفاده کن
        if ((empty($artist_fa) || !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa))) {
            if (!empty($meta_arr['lyricist']) && preg_match('/[\x{0600}-\x{06FF}]/u', $meta_arr['lyricist'])) {
                // فقط اگر lyricist در title اصلی نیست که نام خواننده باشد، استفاده کن
                // برای vahid-hajitabar که lyricist=پیام قربانی و artist=وحید حاجی تبار، نباید جایگزین شود
                // پس فقط اگر artist_fa خالی بود
                if (empty($artist_fa) || $artist_fa === 'هنرمند') {
                    $artist_fa = trim($meta_arr['lyricist']);
                }
            }
            if ((empty($artist_fa) || !preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa)) && !empty($meta_arr['composer']) && preg_match('/[\x{0600}-\x{06FF}]/u', $meta_arr['composer'])) {
                if (empty($artist_fa) || $artist_fa === 'هنرمند') {
                    $artist_fa = trim($meta_arr['composer']);
                }
            }
        }
        
        if (empty($artist_fa)) $artist_fa = !empty($artist_en) ? $artist_en : 'هنرمند';
        if (empty($track_fa)) $track_fa = !empty($discovery['title']) ? $this->clean_track_fa($discovery['title'], $artist_fa) : 'آهنگ جدید';
        
        // اگر track_fa هنوز شامل artist_fa فارسی است، جدا کن - مثل "محسن یگانه زندگیم سوخت"
        if (!empty($artist_fa) && !empty($track_fa) && preg_match('/[\x{0600}-\x{06FF}]/u', $artist_fa)) {
            if (mb_strpos($track_fa, $artist_fa) === 0) {
                $remaining = trim(mb_substr($track_fa, mb_strlen($artist_fa)));
                $remaining = ltrim($remaining, " -–—");
                if (!empty($remaining)) $track_fa = $remaining;
            }
            if (mb_strpos($track_fa, $artist_fa . ' ') === 0) {
                $remaining = trim(mb_substr($track_fa, mb_strlen($artist_fa)));
                $remaining = ltrim($remaining, " -–—");
                if (!empty($remaining) && mb_strlen($remaining) < 100) $track_fa = $remaining;
            }
            if (mb_strpos($track_fa, ' از ') !== false) {
                $parts = explode(' از ', $track_fa);
                $first = trim($parts[0]);
                if (!empty($first) && mb_strlen($first) < 100) $track_fa = $first;
            }
        }
        
        $track_fa = $this->clean_track_fa($track_fa, $artist_fa);
        if (empty($track_fa)) $track_fa = 'آهنگ جدید';
        
        $title = "دانلود آهنگ {$artist_fa} به نام {$track_fa}";
        
        $lyrics = '';
        if (!empty($discovery['metadata_json'])) {
            $meta = json_decode($discovery['metadata_json'], true);
            if (is_array($meta)) {
                if (!empty($meta['lyrics'])) $lyrics = $meta['lyrics'];
                elseif (!empty($meta['music_text'])) $lyrics = $meta['music_text'];
            }
        }
        if (empty($lyrics) && isset($discovery['lyrics'])) $lyrics = $discovery['lyrics'];
        global $wpdb;
        $has_lyrics_col = $wpdb->get_var("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES . " LIKE 'lyrics'");
        if ($has_lyrics_col && !empty($discovery['lyrics'])) $lyrics = $discovery['lyrics'];
        $lyrics = $this->format_lyrics_verses($lyrics);
        
        if (empty($track_en)) {
            $track_en = !empty($discovery['title']) ? $discovery['title'] : '';
            if (!empty($artist_en) && stripos($track_en, $artist_en) === 0) {
                $track_en = trim(substr($track_en, strlen($artist_en)));
                $track_en = ltrim($track_en, " -–—");
            }
        }
        if (empty($artist_en)) $artist_en = $artist_fa;
        
        $content = $this->build_content($discovery, $artist_fa, $track_fa, $artist_en, $track_en);
        
        return array(
            'title' => $title,
            'artist_fa' => $artist_fa,
            'track_fa' => $track_fa,
            'artist_en' => $artist_en,
            'track_en' => !empty($track_en) ? $track_en : $discovery['title'],
            'slug' => !empty($discovery['slug']) ? $discovery['slug'] : sanitize_title($artist_en . '-' . $track_en),
            'content' => $content,
            'excerpt' => "دانلود آهنگ {$track_fa} از {$artist_fa}",
            'musics_type' => 'mp3',
            'music_txt' => $lyrics,
            'cover_url' => $discovery['cover_url'] ?? '',
            'music128' => $discovery['audio_url_128'] ?? '',
            'music320' => $discovery['audio_url_320'] ?? '',
            'video480p' => $discovery['video_url_480'] ?? '',
            'video720p' => $discovery['video_url_720'] ?? '',
            'video1080p' => $discovery['video_url_1080'] ?? '',
            'tags' => array($artist_fa, $track_fa, 'دانلود آهنگ'),
            'categories' => array(),
            'extra_meta' => array(
                'tscout_discovery_id' => $discovery['id'],
                'tscout_provider' => $discovery['provider'],
                'tscout_external_id' => $discovery['external_id'],
                'tscout_source_url' => $discovery['source_url'],
            )
        );
    }
    
    private function clean_artist_fa($artist_fa) {
        $artist_fa = trim($artist_fa);
        if (empty($artist_fa)) return '';
        $artist_fa = preg_replace('/^دانلود آهنگ\s+/u', '', $artist_fa);
        $artist_fa = trim($artist_fa);
        return $artist_fa;
    }
    
    private function clean_track_fa($track_fa, $artist_fa = '') {
        $track_fa = trim($track_fa);
        if (empty($track_fa)) return '';
        $prefixes = array('دانلود آهنگ جدید ', 'دانلود آهنگ ', 'دانلود ');
        foreach ($prefixes as $pre) {
            if (mb_strpos($track_fa, $pre) === 0) {
                $track_fa = trim(mb_substr($track_fa, mb_strlen($pre)));
                break;
            }
        }
        if (!empty($artist_fa) && mb_strpos($track_fa, ' از ') !== false && mb_strpos($track_fa, $artist_fa) !== false) {
            $parts = explode(' از ', $track_fa);
            $track_fa = trim($parts[0]);
        }
        if (!empty($artist_fa) && mb_strpos($track_fa, $artist_fa) === 0) {
            $rem = trim(mb_substr($track_fa, mb_strlen($artist_fa)));
            $rem = ltrim($rem, " -–—");
            if (!empty($rem)) $track_fa = $rem;
        }
        if (!empty($artist_fa) && mb_strpos($track_fa, $artist_fa . ' ') === 0) {
            $rem = trim(mb_substr($track_fa, mb_strlen($artist_fa)));
            $rem = ltrim($rem, " -–—");
            if (!empty($rem) && mb_strlen($rem) < 100) $track_fa = $rem;
        }
        foreach ($prefixes as $pre) {
            if (mb_strpos($track_fa, $pre) === 0) {
                $track_fa = trim(mb_substr($track_fa, mb_strlen($pre)));
                break;
            }
        }
        $track_fa = trim($track_fa, " -–—");
        $track_fa = preg_replace('/\s+/', ' ', $track_fa);
        return $track_fa;
    }
    
    private function format_lyrics_verses($lyrics) {
        $lyrics = trim($lyrics);
        if (empty($lyrics)) return '';
        $lyrics = str_replace(array("\r\n", "\r"), "\n", $lyrics);
        $lines = explode("\n", $lyrics);
        $formatted = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { if (!empty($formatted) && end($formatted) !== '') $formatted[] = ''; continue; }
            if (mb_strpos($line, 'دانلود آهنگ با کیفیت') !== false) continue;
            if (mb_strpos($line, 'پخش آهنگ') !== false && mb_strlen($line) < 30) continue;
            $formatted[] = $line;
        }
        $text = implode("\n", $formatted);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) > 10000) $text = mb_substr($text, 0, 10000);
        return $text;
    }
    
    private function build_content($discovery, $artist_fa, $track_fa, $artist_en = '', $track_en = '') {
        $home_url = function_exists('home_url') ? home_url('/') : 'https://rozmusic.com/';
        $home_url = rtrim($home_url, '/') . '/';
        $artist_fa_slug = str_replace(' ', '-', $artist_fa);
        $artist_fa_tag_url = $home_url . 'tag/' . rawurlencode($artist_fa_slug);
        $artist_en_for_url = !empty($artist_en) ? $artist_en : $artist_fa;
        $artist_en_slug = str_replace(' ', '-', $artist_en_for_url);
        $artist_en_slug = preg_replace('/[^A-Za-z0-9\-]/', '', $artist_en_slug);
        if (empty($artist_en_slug)) $artist_en_slug = sanitize_title($artist_en_for_url);
        $artist_en_tag_url = $home_url . 'tag/' . $artist_en_slug;
        $content = '';
        $content .= '<p><a href="' . esc_url($home_url) . '">دانلود آهنگ جدید</a> <strong><a href="' . esc_url($artist_fa_tag_url) . '">' . esc_html($artist_fa) . '</a></strong> به نام <strong>' . esc_html($track_fa) . '</strong></p>' . "\n\n";
        $credits_text = $this->build_credits_text($discovery);
        if (!empty($credits_text)) $content .= '<p>' . $credits_text . '</p>' . "\n\n";
        if (!empty($artist_en) && !empty($track_en)) {
            $track_en_clean = $track_en;
            if (stripos($track_en_clean, $artist_en) === 0) {
                $track_en_clean = trim(substr($track_en_clean, strlen($artist_en)));
                $track_en_clean = ltrim($track_en_clean, " -–—");
            }
            if (empty($track_en_clean)) $track_en_clean = $track_en;
            $content .= '<p>Download New Music <strong><a href="' . esc_url($artist_en_tag_url) . '">' . esc_html($artist_en) . '</a></strong> – <strong>' . esc_html($track_en_clean) . '</strong></p>';
        }
        return $content;
    }
    
    private function build_credits_text($discovery) {
        $meta = array();
        if (!empty($discovery['metadata_json'])) {
            $decoded = json_decode($discovery['metadata_json'], true);
            if (is_array($decoded)) $meta = $decoded;
        }
        $lyricist = isset($meta['lyricist']) ? $meta['lyricist'] : (isset($discovery['lyricist']) ? $discovery['lyricist'] : '');
        $composer = isset($meta['composer']) ? $meta['composer'] : (isset($discovery['composer']) ? $discovery['composer'] : '');
        $arranger = isset($meta['arranger']) ? $meta['arranger'] : (isset($discovery['arranger']) ? $discovery['arranger'] : '');
        $mix_master = isset($meta['mix_master']) ? $meta['mix_master'] : '';
        if (isset($meta['credits']) && is_array($meta['credits'])) {
            $c = $meta['credits'];
            if (empty($lyricist) && !empty($c['lyricist'])) $lyricist = $c['lyricist'];
            if (empty($composer) && !empty($c['composer'])) $composer = $c['composer'];
            if (empty($arranger) && !empty($c['arranger'])) $arranger = $c['arranger'];
            if (empty($mix_master) && !empty($c['mix_master'])) $mix_master = $c['mix_master'];
        }
        if (empty($lyricist) && !empty($discovery['lyricist'])) $lyricist = $discovery['lyricist'];
        if (empty($composer) && !empty($discovery['composer'])) $composer = $discovery['composer'];
        if (empty($arranger) && !empty($discovery['arranger'])) $arranger = $discovery['arranger'];
        $parts = array();
        if (!empty($lyricist) && !empty($composer)) {
            if (trim($lyricist) === trim($composer)) $parts[] = 'ترانه و موزیک: ' . esc_html($lyricist);
            else $parts[] = 'ترانه: ' . esc_html($lyricist) . ', موزیک: ' . esc_html($composer);
        } elseif (!empty($lyricist)) $parts[] = 'ترانه: ' . esc_html($lyricist);
        elseif (!empty($composer)) $parts[] = 'موزیک: ' . esc_html($composer);
        if (!empty($arranger)) $parts[] = 'تنظیم: ' . esc_html($arranger);
        if (!empty($mix_master)) $parts[] = 'میکس و مستر: ' . esc_html($mix_master);
        if (empty($parts)) return '';
        return implode(', ', $parts);
    }
    
    private function build_file_name($discovery) {
        // همیشه از نام انگلیسی برای فایل استفاده کن تا HostDL مشکلی نداشته باشد
        $artist = !empty($discovery['artist_en']) ? $discovery['artist_en'] : '';
        if (empty($artist) && !empty($discovery['artist_slug_en'])) {
            $artist = $discovery['artist_slug_en'];
        }
        if (empty($artist)) {
            $artist = !empty($discovery['artist_fa']) ? $discovery['artist_fa'] : 'artist';
        }
        $track = !empty($discovery['title_en']) ? $discovery['title_en'] : '';
        if (empty($track) && !empty($discovery['track_en_from_slug'])) {
            $track = $discovery['track_en_from_slug'];
        }
        if (empty($track)) {
            $track = !empty($discovery['title']) ? $discovery['title'] : ($discovery['title_fa'] ?? 'track');
        }
        $file_name = $artist . ' - ' . $track;
        // فقط انگلیسی و عدد و خط تیره و فاصله
        $file_name = str_replace(array('..', "\\", "\0"), '', $file_name);
        $file_name = preg_replace('#[/\\\\]+#', '-', $file_name);
        $file_name = preg_replace('/[^A-Za-z0-9\-_\.\(\)\[\] ]/u', '', $file_name);
        $file_name = trim(preg_replace('/\s+/', ' ', $file_name), " .-");
        // اگر بعد از پاکسازی فارسی خالی شد، از ID استفاده کن
        if (empty($file_name) || strlen($file_name) < 3) {
            $file_name = 'track-' . ($discovery['id'] ?? time());
        }
        // محدودیت طول
        if (strlen($file_name) > 80) $file_name = substr($file_name, 0, 80);
        return $file_name;
    }
    
    private function check_file_status($discovery) {
        return array(
            'has_128' => !empty($discovery['audio_url_128']),
            'has_320' => !empty($discovery['audio_url_320']),
            'has_cover' => !empty($discovery['cover_url']),
            'has_video' => !empty($discovery['video_url_1080']) || !empty($discovery['video_url_720']),
            'has_lyrics' => !empty($discovery['metadata_json']) && strpos($discovery['metadata_json'], 'lyrics') !== false,
            'hostdl_configured' => $this->hostdl->is_configured(),
        );
    }
    
    private function should_transfer_file($discovery, $payload = array()) {
        if (in_array($discovery['license_status'], array('forbidden', 'metadata_only'), true)) return false;
        if ($discovery['license_status'] === 'needs_review') {
            $license_approved = isset($payload['license_approved']) ? (bool) $payload['license_approved'] : false;
            if ($license_approved) return !empty($discovery['audio_url_128']) || !empty($discovery['audio_url_320']);
            $options = get_option('tscout_options', array());
            $policies = isset($options['provider_policies'][$discovery['provider']]) ? $options['provider_policies'][$discovery['provider']] : array();
            $allow = isset($policies['allow_audio_download']) ? (bool) $policies['allow_audio_download'] : true;
            if (!$allow) return false;
        }
        if (empty($discovery['audio_url_128']) && empty($discovery['audio_url_320'])) return false;
        return true;
    }
    
    private function can_publish($discovery, $match_result) {
        if ($discovery['license_status'] === 'forbidden') return false;
        if ($match_result['is_duplicate'] && $match_result['similarity'] >= 95) return false;
        return true;
    }
    
    private function get_warnings($discovery, $match_result) {
        $warnings = array();
        if ($discovery['license_status'] === 'needs_review') $warnings[] = 'مجوز این اثر نیاز به بررسی دارد. قبل از انتشار، تیک تایید مجوز را بزنید.';
        if ($discovery['license_status'] === 'metadata_only') $warnings[] = 'فقط متادیتای این اثر قابل استفاده است، نه فایل صوتی.';
        if ($match_result['needs_review']) $warnings[] = 'این اثر نیاز به بررسی دستی دارد: ' . $match_result['reason'];
        if (empty($discovery['audio_url_320']) && empty($discovery['audio_url_128'])) $warnings[] = 'فایل صوتی برای این اثر یافت نشد. فقط متادیتا منتشر خواهد شد.';
        if (!$this->hostdl->is_configured()) $warnings[] = 'هاست دانلود پیکربندی نشده است. فایل‌ها به صورت URL خارجی ذخیره می‌شوند. تنظیمات ترانه‌یار را بررسی کنید: ' . $this->adapter->get_download_host();
        if ($discovery['confidence'] < 50) $warnings[] = 'میزان اطمینان پایین است (' . $discovery['confidence'] . '%).';
        return $warnings;
    }
    
    private function get_discovery($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE id = %d LIMIT 1", intval($id)), ARRAY_A);
    }
    
    private function log_publish($discovery_id, $status, $message, $post_id = null) {
        Logger::info("Publish {$status}: {$message}", array('discovery_id' => $discovery_id,'post_id' => $post_id,'status' => $status));
    }
}
