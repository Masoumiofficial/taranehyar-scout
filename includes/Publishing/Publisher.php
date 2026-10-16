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
    
    /**
     * Generate preview for publishing
     */
    public function preview($discovery_id) {
        $discovery = $this->get_discovery($discovery_id);
        if (!$discovery) {
            return new \WP_Error('not_found', 'اثر یافت نشد');
        }
        
        // Check license
        if ($discovery['license_status'] === 'forbidden') {
            return new \WP_Error('forbidden', 'استفاده از این اثر مجاز نیست');
        }
        
        // Check match status
        $match_result = $this->matcher->match($discovery);
        
        // Build preview data
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
    
    /**
     * Publish discovery
     */
    public function publish($payload, $job = null) {
        $discovery_id = isset($payload['discovery_id']) ? intval($payload['discovery_id']) : 0;
        $approved_by = isset($payload['approved_by']) ? intval($payload['approved_by']) : get_current_user_id();
        $publish_status = isset($payload['publish_status']) ? $payload['publish_status'] : 'draft';
        
        if (!$discovery_id) {
            return new \WP_Error('invalid_id', 'شناسه اثر نامعتبر است');
        }
        
        $discovery = $this->get_discovery($discovery_id);
        if (!$discovery) {
            return new \WP_Error('not_found', 'اثر یافت نشد');
        }
        
        $state_machine = new StateMachine();
        
        // Step 1: Validate
        $state_machine->transition_to(StateMachine::STATE_VALIDATING);
        $validation = $this->validate_discovery($discovery);
        if (is_wp_error($validation)) {
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $validation->get_error_message()));
            $this->log_publish($discovery_id, 'failed', $validation->get_error_message());
            return $validation;
        }
        
        // Step 2: Check license
        $state_machine->transition_to(StateMachine::STATE_CHECKING_LICENSE);
        if ($discovery['license_status'] === 'forbidden') {
            $error = new \WP_Error('forbidden', 'استفاده از این اثر مجاز نیست');
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            $this->log_publish($discovery_id, 'failed', 'مجوز ندارد');
            return $error;
        }
        
        if ($discovery['license_status'] === 'needs_review') {
            // Check if admin has approved license
            $license_approved = isset($payload['license_approved']) ? (bool) $payload['license_approved'] : false;
            if (!$license_approved) {
                $error = new \WP_Error('license_needs_review', 'مجوز این اثر نیاز به بررسی دارد');
                $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
                return $error;
            }
        }
        
        // Step 3: Check duplicate
        $state_machine->transition_to(StateMachine::STATE_CHECKING_DUPLICATE);
        $match_result = $this->matcher->match($discovery);
        if ($match_result['is_duplicate'] && $match_result['similarity'] >= 95) {
            // Exact duplicate, prevent publishing
            $error = new \WP_Error('duplicate', 'این اثر قبلاً منتشر شده است: ' . $match_result['reason']);
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            return $error;
        }
        
        // Step 4: Preview (already done, but mark)
        $state_machine->transition_to(StateMachine::STATE_PREVIEW);
        $state_machine->transition_to(StateMachine::STATE_AWAITING_APPROVAL);
        
        // Step 5: Prepare metadata
        $state_machine->transition_to(StateMachine::STATE_PREPARING_METADATA);
        $post_data = $this->build_post_data($discovery);
        $post_data['status'] = $publish_status;
        
        // Step 6: Transfer file if allowed and HostDL configured
        $file_transferred = false;
        $transfer_data = array();
        
        if ($this->should_transfer_file($discovery)) {
            $state_machine->transition_to(StateMachine::STATE_TRANSFERRING_FILE);
            
            $file_name = $this->build_file_name($discovery);
            $transfer_data = $this->hostdl->build_music_transfer_data(
                $discovery,
                $discovery['artist_en'],
                $discovery['title'],
                $file_name
            );
            
            // Validate file URLs
            if (!empty($transfer_data['link320'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link320'], array('mp3'));
                if (is_wp_error($valid)) {
                    Logger::warning('File validation failed', array('url' => $transfer_data['link320'], 'error' => $valid->get_error_message()));
                    // Don't fail, just warn - we can publish without file
                    $transfer_data['link320'] = '';
                }
            }
            
            if (!empty($transfer_data['link128'])) {
                $valid = $this->hostdl->validate_file($transfer_data['link128'], array('mp3'));
                if (is_wp_error($valid)) {
                    $transfer_data['link128'] = '';
                }
            }
            
            // Only transfer if we have at least one valid URL and HostDL is configured
            if ((!empty($transfer_data['link320']) || !empty($transfer_data['link128'])) && $this->hostdl->is_configured()) {
                $transfer_result = $this->hostdl->transfer($transfer_data, 'music');
                
                if (is_wp_error($transfer_result)) {
                    // Log but don't fail - we can still create post with external URLs
                    Logger::warning('File transfer failed, will use external URLs', array('discovery_id' => $discovery_id, 'error' => $transfer_result->get_error_message()));
                } else {
                    $file_transferred = true;
                    $state_machine->transition_to(StateMachine::STATE_VALIDATING_FILE);
                    
                    // Build final file URLs pointing to HostDL
                    $host = $this->adapter->get_download_host();
                    $archive_path = $transfer_data['archive_path'];
                    $post_data['music320'] = $this->adapter->build_file_url($host, $archive_path, $file_name . '.mp3');
                    $post_data['music128'] = $this->adapter->build_file_url($host, $archive_path, $file_name . ' [128].mp3');
                }
            }
        }
        
        // If not transferred, use external URLs or leave empty based on policy
        if (!$file_transferred) {
            $options = get_option('tscout_options', array());
            $allow_external = isset($options['allow_external_urls']) ? (bool) $options['allow_external_urls'] : false;
            
            if ($allow_external) {
                $post_data['music320'] = isset($discovery['audio_url_320']) ? $discovery['audio_url_320'] : '';
                $post_data['music128'] = isset($discovery['audio_url_128']) ? $discovery['audio_url_128'] : '';
            } else {
                // No file transfer and external not allowed - publish metadata only
                $post_data['music320'] = '';
                $post_data['music128'] = '';
            }
        }
        
        // Step 7: Create post
        $state_machine->transition_to(StateMachine::STATE_CREATING_POST);
        
        // Check again for duplicate title before creating
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
        
        // Step 8: Final check
        $state_machine->transition_to(StateMachine::STATE_FINAL_CHECK);
        
        $final_post = get_post($post_id);
        if (!$final_post) {
            $error = new \WP_Error('post_not_found', 'پست ایجاد شد اما یافت نشد');
            $state_machine->transition_to(StateMachine::STATE_FAILED, array('error' => $error->get_error_message()));
            return $error;
        }
        
        // Step 9: Completed
        $state_machine->transition_to(StateMachine::STATE_COMPLETED);
        
        // Update discovery
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
        if (is_wp_error($valid)) {
            return $valid;
        }
        
        return true;
    }
    
    private function build_post_data($discovery) {
        $artist_fa = !empty($discovery['artist_fa']) ? $discovery['artist_fa'] : $discovery['artist_en'];
        $track_fa = !empty($discovery['title_fa']) ? $discovery['title_fa'] : $discovery['title'];
        
        $title = "دانلود آهنگ {$artist_fa} به نام {$track_fa}";
        
        // Try to use SEO template if Taranehyar available
        if (class_exists('TaranehYar_SEO')) {
            $seo = \TaranehYar_SEO::profile('music', array(
                'artist' => $artist_fa,
                'track' => $track_fa,
                'artist_en' => $discovery['artist_en'],
                'track_en' => $discovery['title'],
                'site' => get_bloginfo('name'),
            ));
            if (!empty($seo['post_title'])) {
                $title = $seo['post_title'];
            }
        }
        
        $content = $this->build_content($discovery);
        
        return array(
            'title' => $title,
            'artist_fa' => $artist_fa,
            'track_fa' => $track_fa,
            'artist_en' => $discovery['artist_en'],
            'track_en' => $discovery['title'],
            'slug' => !empty($discovery['slug']) ? $discovery['slug'] : sanitize_title($discovery['artist_en'] . '-' . $discovery['title']),
            'content' => $content,
            'excerpt' => "دانلود آهنگ {$track_fa} از {$artist_fa}",
            'musics_type' => 'mp3',
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
    
    private function build_content($discovery) {
        $artist_fa = !empty($discovery['artist_fa']) ? $discovery['artist_fa'] : $discovery['artist_en'];
        $track_fa = !empty($discovery['title_fa']) ? $discovery['title_fa'] : $discovery['title'];
        
        $content = "<p>دانلود آهنگ جدید <strong>{$artist_fa}</strong> به نام <strong>{$track_fa}</strong> با کیفیت 320 و 128.</p>";
        
        if (!empty($discovery['genre'])) {
            $content .= "<p>سبک: {$discovery['genre']}</p>";
        }
        
        if (!empty($discovery['year'])) {
            $content .= "<p>سال انتشار: {$discovery['year']}</p>";
        }
        
        // Add source attribution
        $content .= "<p><small>منبع: <a href=\"" . esc_url($discovery['source_url']) . "\" target=\"_blank\" rel=\"noopener\">" . esc_html($discovery['provider']) . "</a></small></p>";
        
        return $content;
    }
    
    private function build_file_name($discovery) {
        $artist = !empty($discovery['artist_en']) ? $discovery['artist_en'] : $discovery['artist_fa'];
        $track = !empty($discovery['title']) ? $discovery['title'] : $discovery['title_fa'];
        
        $file_name = $artist . ' - ' . $track;
        
        // Sanitize
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
            'hostdl_configured' => $this->hostdl->is_configured(),
        );
    }
    
    private function should_transfer_file($discovery) {
        // Check license
        if (in_array($discovery['license_status'], array('forbidden', 'metadata_only'), true)) {
            return false;
        }
        
        if ($discovery['license_status'] === 'needs_review') {
            $options = get_option('tscout_options', array());
            $policies = isset($options['provider_policies'][$discovery['provider']]) ? $options['provider_policies'][$discovery['provider']] : array();
            $allow = isset($policies['allow_audio_download']) ? (bool) $policies['allow_audio_download'] : false;
            if (!$allow) {
                return false;
            }
        }
        
        // Check if file exists
        if (empty($discovery['audio_url_128']) && empty($discovery['audio_url_320'])) {
            return false;
        }
        
        return true;
    }
    
    private function can_publish($discovery, $match_result) {
        // Cannot publish if forbidden
        if ($discovery['license_status'] === 'forbidden') {
            return false;
        }
        
        // Cannot publish if already published with high similarity
        if ($match_result['is_duplicate'] && $match_result['similarity'] >= 95) {
            return false;
        }
        
        // Needs review if license needs review
        if ($discovery['license_status'] === 'needs_review') {
            // Can publish only if admin explicitly approves
            return false; // Will require explicit approval
        }
        
        return true;
    }
    
    private function get_warnings($discovery, $match_result) {
        $warnings = array();
        
        if ($discovery['license_status'] === 'needs_review') {
            $warnings[] = 'مجوز این اثر نیاز به بررسی دارد. قبل از انتشار، حقوق استفاده را تأیید کنید.';
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
            $warnings[] = 'هاست دانلود پیکربندی نشده است. فایل‌ها منتقل نخواهند شد.';
        }
        
        if ($discovery['confidence'] < 50) {
            $warnings[] = 'میزان اطمینان پایین است (' . $discovery['confidence'] . '%). اطلاعات را بررسی کنید.';
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
