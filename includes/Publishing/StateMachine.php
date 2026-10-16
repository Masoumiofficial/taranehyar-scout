<?php
namespace TaranehyarScout\Publishing;

if (!defined('ABSPATH')) exit;

class StateMachine {
    
    const STATE_INIT = 'init';
    const STATE_VALIDATING = 'validating';
    const STATE_CHECKING_LICENSE = 'checking_license';
    const STATE_CHECKING_DUPLICATE = 'checking_duplicate';
    const STATE_PREVIEW = 'preview';
    const STATE_AWAITING_APPROVAL = 'awaiting_approval';
    const STATE_PREPARING_METADATA = 'preparing_metadata';
    const STATE_TRANSFERRING_FILE = 'transferring_file';
    const STATE_VALIDATING_FILE = 'validating_file';
    const STATE_CREATING_POST = 'creating_post';
    const STATE_FINAL_CHECK = 'final_check';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED = 'failed';
    
    private $current_state;
    private $transitions = array();
    private $history = array();
    
    public function __construct($initial_state = self::STATE_INIT) {
        $this->current_state = $initial_state;
        $this->define_transitions();
    }
    
    private function define_transitions() {
        $this->transitions = array(
            self::STATE_INIT => array(self::STATE_VALIDATING, self::STATE_FAILED),
            self::STATE_VALIDATING => array(self::STATE_CHECKING_LICENSE, self::STATE_FAILED),
            self::STATE_CHECKING_LICENSE => array(self::STATE_CHECKING_DUPLICATE, self::STATE_FAILED),
            self::STATE_CHECKING_DUPLICATE => array(self::STATE_PREVIEW, self::STATE_FAILED),
            self::STATE_PREVIEW => array(self::STATE_AWAITING_APPROVAL, self::STATE_FAILED),
            self::STATE_AWAITING_APPROVAL => array(self::STATE_PREPARING_METADATA, self::STATE_FAILED),
            self::STATE_PREPARING_METADATA => array(self::STATE_TRANSFERRING_FILE, self::STATE_CREATING_POST, self::STATE_FAILED),
            self::STATE_TRANSFERRING_FILE => array(self::STATE_VALIDATING_FILE, self::STATE_FAILED),
            self::STATE_VALIDATING_FILE => array(self::STATE_CREATING_POST, self::STATE_FAILED),
            self::STATE_CREATING_POST => array(self::STATE_FINAL_CHECK, self::STATE_FAILED),
            self::STATE_FINAL_CHECK => array(self::STATE_COMPLETED, self::STATE_FAILED),
        );
    }
    
    public function can_transition_to($new_state) {
        if (!isset($this->transitions[$this->current_state])) {
            return false;
        }
        return in_array($new_state, $this->transitions[$this->current_state], true);
    }
    
    public function transition_to($new_state, $context = array()) {
        if (!$this->can_transition_to($new_state)) {
            return new \WP_Error('invalid_transition', "انتقال از {$this->current_state} به {$new_state} مجاز نیست");
        }
        
        $this->history[] = array(
            'from' => $this->current_state,
            'to' => $new_state,
            'time' => current_time('mysql'),
            'context' => $context
        );
        
        $this->current_state = $new_state;
        return true;
    }
    
    public function get_current_state() {
        return $this->current_state;
    }
    
    public function get_history() {
        return $this->history;
    }
    
    public function is_completed() {
        return $this->current_state === self::STATE_COMPLETED;
    }
    
    public function is_failed() {
        return $this->current_state === self::STATE_FAILED;
    }
    
    public function get_state_label($state = null) {
        $state = $state ?: $this->current_state;
        $labels = array(
            self::STATE_INIT => 'آغاز',
            self::STATE_VALIDATING => 'اعتبارسنجی',
            self::STATE_CHECKING_LICENSE => 'بررسی مجوز',
            self::STATE_CHECKING_DUPLICATE => 'بررسی تکراری',
            self::STATE_PREVIEW => 'پیش‌نمایش',
            self::STATE_AWAITING_APPROVAL => 'در انتظار تأیید',
            self::STATE_PREPARING_METADATA => 'آماده‌سازی متادیتا',
            self::STATE_TRANSFERRING_FILE => 'انتقال فایل',
            self::STATE_VALIDATING_FILE => 'اعتبارسنجی فایل',
            self::STATE_CREATING_POST => 'ایجاد پست',
            self::STATE_FINAL_CHECK => 'بررسی نهایی',
            self::STATE_COMPLETED => 'تکمیل شده',
            self::STATE_FAILED => 'ناموفق',
        );
        return isset($labels[$state]) ? $labels[$state] : $state;
    }
}
