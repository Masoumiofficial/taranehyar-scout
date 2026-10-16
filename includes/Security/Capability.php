<?php
namespace TaranehyarScout\Security;

if (!defined('ABSPATH')) exit;

class Capability {
    
    const CAP_MANAGE_SCOUT = 'manage_options'; // For settings
    const CAP_PUBLISH_SCOUT = 'publish_posts'; // For discovery/publishing
    const CAP_VIEW_SCOUT = 'edit_posts'; // For viewing results
    
    public static function can_manage() {
        return current_user_can(self::CAP_MANAGE_SCOUT);
    }
    
    public static function can_publish() {
        return current_user_can(self::CAP_PUBLISH_SCOUT);
    }
    
    public static function can_view() {
        return current_user_can(self::CAP_VIEW_SCOUT);
    }
    
    public static function check_manage() {
        if (!self::can_manage()) {
            wp_die(__('شما دسترسی کافی برای مدیریت تنظیمات را ندارید.', 'taranehyar-scout'), 403);
        }
    }
    
    public static function check_publish() {
        if (!self::can_publish()) {
            wp_die(__('شما دسترسی کافی برای انتشار آثار را ندارید.', 'taranehyar-scout'), 403);
        }
    }
    
    public static function check_view() {
        if (!self::can_view()) {
            wp_die(__('شما دسترسی کافی برای مشاهده این بخش را ندارید.', 'taranehyar-scout'), 403);
        }
    }
    
    public static function verify_nonce($action, $nonce_field = '_wpnonce') {
        $nonce = isset($_REQUEST[$nonce_field]) ? $_REQUEST[$nonce_field] : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(__('درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.', 'taranehyar-scout'), 403);
        }
    }
    
    public static function verify_ajax_nonce($action) {
        if (!check_ajax_referer($action, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce verification failed'), 403);
            wp_die();
        }
    }
}
