<?php
namespace TaranehyarScout\Admin;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\Capability;
use TaranehyarScout\Providers\ProviderRegistry;
use TaranehyarScout\Metadata\Normalizer;
use TaranehyarScout\Matching\Matcher;
use TaranehyarScout\Publishing\Publisher;
use TaranehyarScout\Jobs\JobQueue;
use TaranehyarScout\Logging\Logger;

class AjaxHandlers {
    
    public function handle_search() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $artist_en = isset($_POST['artist_en']) ? sanitize_text_field($_POST['artist_en']) : '';
        $providers = isset($_POST['providers']) ? (array) $_POST['providers'] : array();
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        $types = isset($_POST['types']) ? (array) $_POST['types'] : array();
        
        if (empty($artist_en)) {
            wp_send_json_error(array('message' => 'نام هنرمند الزامی است'));
        }
        
        if (empty($providers)) {
            $providers = array_keys(ProviderRegistry::get_enabled());
        }
        
        // Sanitize providers
        $providers = array_map('sanitize_key', $providers);
        
        $queue = new JobQueue();
        $job_id = $queue->add_job('discovery_search', array(
            'artist_en' => $artist_en,
            'providers' => $providers,
            'options' => array(
                'limit' => $limit,
                'types' => $types,
            )
        ));
        
        if (is_wp_error($job_id)) {
            wp_send_json_error(array('message' => $job_id->get_error_message()));
        }
        
        wp_send_json_success(array(
            'job_id' => $job_id,
            'message' => 'جستجو در صف قرار گرفت'
        ));
    }
    
    public function handle_match() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $discovery_ids = isset($_POST['discovery_ids']) ? (array) $_POST['discovery_ids'] : array();
        $discovery_ids = array_map('intval', $discovery_ids);
        
        $queue = new JobQueue();
        $job_id = $queue->add_job('discovery_match', array(
            'discovery_ids' => $discovery_ids
        ));
        
        if (is_wp_error($job_id)) {
            wp_send_json_error(array('message' => $job_id->get_error_message()));
        }
        
        wp_send_json_success(array(
            'job_id' => $job_id,
            'message' => 'تطبیق در صف قرار گرفت'
        ));
    }
    
    public function handle_publish_preview() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $discovery_id = isset($_POST['discovery_id']) ? intval($_POST['discovery_id']) : 0;
        
        if (!$discovery_id) {
            wp_send_json_error(array('message' => 'شناسه نامعتبر'));
        }
        
        $publisher = new Publisher();
        $preview = $publisher->preview($discovery_id);
        
        if (is_wp_error($preview)) {
            wp_send_json_error(array('message' => $preview->get_error_message()));
        }
        
        wp_send_json_success($preview);
    }
    
    public function handle_publish() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $discovery_id = isset($_POST['discovery_id']) ? intval($_POST['discovery_id']) : 0;
        $publish_status = isset($_POST['publish_status']) ? sanitize_text_field($_POST['publish_status']) : 'draft';
        $license_approved = isset($_POST['license_approved']) ? (bool) $_POST['license_approved'] : false;
        
        if (!$discovery_id) {
            wp_send_json_error(array('message' => 'شناسه نامعتبر'));
        }
        
        // For immediate publish (small sites), do it directly
        // For larger, use queue
        $use_queue = isset($_POST['use_queue']) ? (bool) $_POST['use_queue'] : false;
        
        if ($use_queue) {
            $queue = new JobQueue();
            $job_id = $queue->add_job('publish_track', array(
                'discovery_id' => $discovery_id,
                'publish_status' => $publish_status,
                'license_approved' => $license_approved,
                'approved_by' => get_current_user_id(),
            ));
            
            if (is_wp_error($job_id)) {
                wp_send_json_error(array('message' => $job_id->get_error_message()));
            }
            
            wp_send_json_success(array(
                'job_id' => $job_id,
                'queued' => true,
                'message' => 'انتشار در صف قرار گرفت'
            ));
        } else {
            $publisher = new Publisher();
            $result = $publisher->publish(array(
                'discovery_id' => $discovery_id,
                'publish_status' => $publish_status,
                'license_approved' => $license_approved,
                'approved_by' => get_current_user_id(),
            ));
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            wp_send_json_success($result);
        }
    }
    
    public function handle_bulk_action() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
        $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids = array_map('intval', $ids);
        
        if (empty($action) || empty($ids)) {
            wp_send_json_error(array('message' => 'عملیات یا شناسه‌ها نامعتبر است'));
        }
        
        global $wpdb;
        
        switch ($action) {
            case 'match':
                $queue = new JobQueue();
                $job_id = $queue->add_job('discovery_match', array('discovery_ids' => $ids));
                wp_send_json_success(array('job_id' => $job_id, 'message' => 'تطبیق گروهی در صف قرار گرفت'));
                break;
                
            case 'ignore':
                $result = $wpdb->query("UPDATE " . TSCOUT_TABLE_DISCOVERIES . " SET discovery_status = 'ignored' WHERE id IN (" . implode(',', $ids) . ")");
                wp_send_json_success(array('updated' => $result));
                break;
                
            case 'delete':
                if (!current_user_can('manage_options')) {
                    wp_send_json_error(array('message' => 'فقط مدیر می‌تواند حذف کند'));
                }
                $result = $wpdb->query("DELETE FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE id IN (" . implode(',', $ids) . ")");
                wp_send_json_success(array('deleted' => $result));
                break;
                
            default:
                wp_send_json_error(array('message' => 'عملیات ناشناخته'));
        }
    }
    
    public function handle_job_status() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        
        if (empty($job_id)) {
            wp_send_json_error(array('message' => 'شناسه job الزامی است'));
        }
        
        $queue = new JobQueue();
        $job = $queue->get_job($job_id);
        
        if (!$job) {
            wp_send_json_error(array('message' => 'Job یافت نشد'));
        }
        
        wp_send_json_success($job);
    }
    
    public function handle_retry_job() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        
        $queue = new JobQueue();
        $result = $queue->retry_job($job_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Job مجدداً در صف قرار گرفت'));
    }
    
    public function handle_cancel_job() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        
        $queue = new JobQueue();
        $result = $queue->cancel_job($job_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Job لغو شد'));
    }
    
    public function handle_dismiss() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        global $wpdb;
        $wpdb->update(
            TSCOUT_TABLE_DISCOVERIES,
            array('discovery_status' => 'ignored'),
            array('id' => $id)
        );
        
        wp_send_json_success();
    }
    
    public function handle_update_license() {
        Capability::verify_ajax_nonce('tscout_ajax');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $status = isset($_POST['license_status']) ? sanitize_text_field($_POST['license_status']) : '';
        
        $allowed = array('verified', 'needs_review', 'metadata_only', 'file_allowed_not_verified', 'forbidden');
        if (!in_array($status, $allowed, true)) {
            wp_send_json_error(array('message' => 'وضعیت مجوز نامعتبر'));
        }
        
        global $wpdb;
        $wpdb->update(
            TSCOUT_TABLE_DISCOVERIES,
            array('license_status' => $status),
            array('id' => $id)
        );
        
        wp_send_json_success();
    }
}
