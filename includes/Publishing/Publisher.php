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
        if (!$discovery) {
            return new \WP_Error('not_found', 'اثر یافت نشد');
        }
        if ($discovery['license_status'] === 'forbidden') {
            return new \WP_Error('forbidden', 'استفاده از این اثر مجاز نیست');
        }
        $match_result = $this->matcher->match($discovery);
        $preview = array(
            'discovery' => $discovery,
            'match' => $match_result,
            'post_data' => $this->build_post_data($discovery),
            'file_status' => $this->check_file_status($discovery),
            'license_status' => $discovery['license_status'],
            'can_publish' => $this->can_publish($discovery, $match_result),
            'warnings' => $this->get_warnings($discovery, $match_result),
        );
        return $preview;
    }
    
    public function publish($payload, $job = null) {
        $discovery_id = isset($payload['discovery_id']) ? intval($payload['discovery_id']) : 0;
        $approved_by = isset($payload['approved_by']) ? intval($payload['approved_by']) : get_current_user_id();
        $publish_status = isset($payload['publish_status']) ? sanitize_text_field($payload['publish_status']) : 'draft';
        
        if (!$discovery_id) {
            return new \WP_Error('invalid_id', 'شناسه اثر نامعتبر است');
        }
        
        $discovery = $this->get_discovery($discovery_id);
        if (!$discovery) {
            return new \WP_Error('not_found', 'اثر یافت نشد');
        }
        
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
            // استفاده از title_en برای نام فایل HostDL (سازگار با مسیر آرشیو ترانه‌یار)
            $transfer_title = !empty($discovery['title_en']) ? $discovery['title_en'] : $discovery['title'];
            $transfer_artist = !empty($discovery['artist_en']) ? $discovery['artist_en'] : $discovery['artist_fa'];
            $transfer_data = $this->hostdl->build_music_transfer_data(
                $discovery,
                $transfer_artist,
                $transfer_title,
                $file_name
            );
            
            if (!empty($transfer_data['link320'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link320'], array('mp3'));
                if (is_wp_error($valid)) {
                    Logger::warning('File validation failed', array('url' => $transfer_data['link320'], 'error' => $valid->get_error_message()));
                    $transfer_data['link320'] = '';
                }
            }
            if (!empty($transfer_data['link128'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link128'], array('mp3'));
                if (is_wp_error($valid)) {
                    $transfer_data['link128'] = '';
                }
            }
            
            if ((!empty($transfer_data['link320']) || !empty($transfer_data['link128'])) && $this->hostdl->is_configured()) {
                $transfer_result = $this->hostdl->transfer($transfer_data, 'music');
                if (is_wp_error($transfer_result)) {
                    Logger::warning('File transfer failed, will use external URLs', array('discovery_id' => $discovery_id, 'error' => $transfer_result->get_error_message()));
                    // حتی اگر انتقال ناموفق بود، URL خارجی را نگه دار اگر مجاز است
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
        $wpdb->update(
            TSCOUT_TABLE_DISCOVERIES,
            array(
                'matched_post_id' => $post_id,
                'match_status' => 'published',
                'discovery_status' => 'published',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $discovery_id)
        );
        
        $this->log_publish($discovery_id, 'completed', "پست #{$post_id} ایجاد شد", $post_id);
        Logger::info('Discovery published', array(
            'discovery_id' => $discovery_id,
            'post_id' => $post_id,
            'file_transferred' => $file_transferred,
            'approved_by' => $approved_by
        ));
        
        return array(
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, ''),
            'file_transferred' => $file_transferred,
            'state_history' => $state_machine->get_history(),
        );
    }
    
    private function validate_discovery($discovery) {
        if (empty($discovery['title'])) {
            return new \WP_Error('empty_title', 'عنوان اثر خالی است');
        }
        if (empty($discovery['artist_en']) && empty($discovery['artist_fa'])) {
            return new \WP_Error('empty_artist', 'نام هنرمند خالی است');
        }
        if (empty($discovery['source_url'])) {
            return new \WP_Error('empty_source', 'آدرس منبع خالی است');
        }
        $valid = UrlValidator::validate($discovery['source_url']);
        if (is_wp_error($valid)) return $valid;
        return true;
    }
    
    private function build_post_data($discovery) {
        // استخراج نام‌های فارسی - اگر title_fa شامل نام خواننده باشد، جدا کن
        $artist_fa = !empty($discovery['artist_fa']) ? trim($discovery['artist_fa']) : '';
        $track_fa = !empty($discovery['title_fa']) ? trim($discovery['title_fa']) : '';
        
        // اگر artist_fa خالی است ولی artist_en داریم، از artist_en استفاده کن (fallback)
        if (empty($artist_fa)) {
            $artist_fa = !empty($discovery['artist_en']) ? $discovery['artist_en'] : '';
        }
        
        // اگر track_fa خالی است، از title استفاده کن
        if (empty($track_fa)) {
            $track_fa = !empty($discovery['title']) ? $discovery['title'] : '';
        }
        
        // اگر track_fa هنوز شامل artist_fa است (مثل "محسن یگانه تو خوب" که شامل "محسن یگانه" است)، جدا کن
        if (!empty($artist_fa) && !empty($track_fa)) {
            // بررسی دقیق: اگر track_fa با artist_fa شروع می‌شود
            if (mb_strpos($track_fa, $artist_fa) === 0) {
                $remaining = trim(mb_substr($track_fa, mb_strlen($artist_fa)));
                if (!empty($remaining)) {
                    $track_fa = $remaining;
                }
            }
            // همچنین بررسی بدون فاصله اضافی
            $artist_fa_normalized = preg_replace('/\s+/', ' ', $artist_fa);
            $track_fa_normalized = preg_replace('/\s+/', ' ', $track_fa);
            if (mb_strpos($track_fa_normalized, $artist_fa_normalized) === 0) {
                $remaining = trim(mb_substr($track_fa_normalized, mb_strlen($artist_fa_normalized)));
                if (!empty($remaining)) {
                    $track_fa = $remaining;
                }
            }
        }
        
        // اگر هنوز track_fa خالی است، fallback به title
        if (empty($track_fa)) {
            $track_fa = $discovery['title'];
        }
        
        // عنوان نهایی باید "دانلود آهنگ {artist_fa} به نام {track_fa}" باشد
        $title = "دانلود آهنگ {$artist_fa} به نام {$track_fa}";
        
        // تلاش برای استفاده از قالب SEO ترانه‌یار 1.0.6 اگر موجود است
        if (class_exists('TaranehYar_SEO')) {
            try {
                $seo = \TaranehYar_SEO::profile('music', array(
                    'artist' => $artist_fa,
                    'track' => $track_fa,
                    'artist_en' => $discovery['artist_en'],
                    'track_en' => $discovery['title'],
                    'site' => get_bloginfo('name'),
                ));
                if (!empty($seo['post_title'])) {
                    // اگر قالب SEO شامل {artist} و {track} است، باید درست باشد
                    // اما اگر شامل کل عنوان قبلی است، از عنوان خودمان استفاده کن
                    // بررسی: اگر seo title شامل artist_fa و track_fa باشد، قبول کن
                    if (mb_strpos($seo['post_title'], $artist_fa) !== false && mb_strpos($seo['post_title'], $track_fa) !== false) {
                        $title = $seo['post_title'];
                    } else {
                        // اگر قالب SEO خالی یا ناقص است، از عنوان خودمان استفاده کن
                        // اما اگر تنظیمات SEO عنوان را دارد، آن را ترجیح بده
                    }
                }
            } catch (\Exception $e) {
                // در صورت خطا، از عنوان خودمان استفاده کن
            }
        }
        
        $content = $this->build_content($discovery, $artist_fa, $track_fa);
        
        // استخراج متن ترانه از metadata_json یا lyrics
        $lyrics = '';
        if (!empty($discovery['metadata_json'])) {
            $meta = json_decode($discovery['metadata_json'], true);
            if (is_array($meta)) {
                if (!empty($meta['lyrics'])) $lyrics = $meta['lyrics'];
                elseif (!empty($meta['music_text'])) $lyrics = $meta['music_text'];
            }
        }
        // fallback به ستون‌های مستقیم اگر وجود دارد
        if (empty($lyrics) && isset($discovery['lyrics'])) {
            $lyrics = $discovery['lyrics'];
        }
        
        // برای سازگاری با ستون‌های جدید اگر اضافه شده باشد
        global $wpdb;
        $has_lyrics_col = $wpdb->get_var("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES . " LIKE 'lyrics'");
        if ($has_lyrics_col && !empty($discovery['lyrics'])) {
            $lyrics = $discovery['lyrics'];
        }
        
        return array(
            'title' => $title,
            'artist_fa' => $artist_fa,
            'track_fa' => $track_fa,
            'artist_en' => $discovery['artist_en'],
            'track_en' => !empty($discovery['title_en']) ? $discovery['title_en'] : $discovery['title'],
            'slug' => !empty($discovery['slug']) ? $discovery['slug'] : sanitize_title($discovery['artist_en'] . '-' . $discovery['title']),
            'content' => $content,
            'excerpt' => "دانلود آهنگ {$track_fa} از {$artist_fa}",
            'musics_type' => 'mp3',
            'music_txt' => $lyrics, // متن ترانه
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
    
    private function build_content($discovery, $artist_fa, $track_fa) {
        $content = "<p>دانلود آهنگ جدید <strong>{$artist_fa}</strong> به نام <strong>{$track_fa}</strong> با کیفیت 320 و 128.</p>";
        
        if (!empty($discovery['genre'])) {
            $content .= "<p>سبک: {$discovery['genre']}</p>";
        }
        if (!empty($discovery['year'])) {
            $content .= "<p>سال انتشار: {$discovery['year']}</p>";
        }
        
        // متن ترانه در محتوا نمایش داده نشود - فقط در متا ذخیره می‌شود (طبق تغییرات 1.0.6 ترانه‌یار)
        // اما برای سازگاری، اگر قالب قدیمی است، می‌توان نمایش داد
        
        $content .= "<p><small>منبع: <a href=\"" . esc_url($discovery['source_url']) . "\" target=\"_blank\" rel=\"noopener\">" . esc_html($discovery['provider']) . "</a></small></p>";
        return $content;
    }
    
    private function build_file_name($discovery) {
        $artist = !empty($discovery['artist_en']) ? $discovery['artist_en'] : $discovery['artist_fa'];
        $track = !empty($discovery['title']) ? $discovery['title'] : $discovery['title_fa'];
        // برای نام فایل، از انگلیسی استفاده کن اگر موجود است
        if (!empty($discovery['title_en'])) {
            $track = $discovery['title_en'];
        }
        $file_name = $artist . ' - ' . $track;
        $file_name = str_replace(array('..', "\\", "\0"), '', $file_name);
        $file_name = preg_replace('#[/\\\\]+#', '-', $file_name);
        $file_name = preg_replace('/[^A-Za-z0-9\-_\.\(\)\[\] ]/u', '', $file_name);
        $file_name = trim(preg_replace('/\s+/', ' ', $file_name), " .-");
        return $file_name !== '' ? $file_name : 'track-' . $discovery['id'];
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
        if (in_array($discovery['license_status'], array('forbidden', 'metadata_only'), true)) {
            return false;
        }
        // اگر مجوز needs_review است و کاربر تایید کرده، اجازه انتقال بده
        if ($discovery['license_status'] === 'needs_review') {
            $license_approved = isset($payload['license_approved']) ? (bool) $payload['license_approved'] : false;
            if ($license_approved) {
                // حتی اگر policy می‌گوید allow_audio_download=0، چون کاربر تایید کرده اجازه بده
                return !empty($discovery['audio_url_128']) || !empty($discovery['audio_url_320']);
            }
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
        return true; // حتی اگر needs_review باشد، با تایید مجوز قابل انتشار است
    }
    
    private function get_warnings($discovery, $match_result) {
        $warnings = array();
        if ($discovery['license_status'] === 'needs_review') {
            $warnings[] = 'مجوز این اثر نیاز به بررسی دارد. قبل از انتشار، تیک تایید مجوز را بزنید.';
        }
        if ($discovery['license_status'] === 'metadata_only') {
            $warnings[] = 'فقط متادیتای این اثر قابل استفاده است، نه فایل صوتی.';
        }
        if ($match_result['needs_review']) {
            $warnings[] = 'این اثر نیاز به بررسی دستی دارد: ' . $match_result['reason'];
        }
        if (empty($discovery['audio_url_320']) && empty($discovery['audio_url_128'])) {
            $warnings[] = 'فایل صوتی برای این اثر یافت نشد. فقط متادیتا منتشر خواهد شد.';
        }
        if (!$this->hostdl->is_configured()) {
            $warnings[] = 'هاست دانلود پیکربندی نشده است. فایل‌ها به صورت URL خارجی ذخیره می‌شوند. تنظیمات ترانه‌یار را بررسی کنید: ' . $this->adapter->get_download_host();
        }
        if ($discovery['confidence'] < 50) {
            $warnings[] = 'میزان اطمینان پایین است (' . $discovery['confidence'] . '%).';
        }
        return $warnings;
    }
    
    private function get_discovery($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE id = %d LIMIT 1",
            intval($id)
        ), ARRAY_A);
    }
    
    private function log_publish($discovery_id, $status, $message, $post_id = null) {
        Logger::info("Publish {$status}: {$message}", array(
            'discovery_id' => $discovery_id,
            'post_id' => $post_id,
            'status' => $status
        ));
    }
}
