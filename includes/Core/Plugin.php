<?php
namespace TaranehyarScout\Core;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Admin\AdminPages;
use TaranehyarScout\Admin\AjaxHandlers;
use TaranehyarScout\Jobs\JobQueue;
use TaranehyarScout\Providers\ProviderRegistry;

class Plugin {
    
    protected $loader;
    protected $version;
    
    public function __construct() {
        $this->version = TSCOUT_VERSION;
        $this->loader = new Loader();
        $this->define_admin_hooks();
        $this->define_job_hooks();
        $this->define_cron_intervals();
    }
    
    private function define_cron_intervals() {
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }
    
    public function add_cron_intervals($schedules) {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = array(
                'interval' => 300,
                'display' => __('هر 5 دقیقه', 'taranehyar-scout')
            );
        }
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display' => __('هر دقیقه', 'taranehyar-scout')
            );
        }
        return $schedules;
    }
    
    private function define_admin_hooks() {
        $admin = new AdminPages();
        $ajax = new AjaxHandlers();
        $queue = new JobQueue();
        
        // Admin menus
        $this->loader->add_action('admin_menu', $admin, 'register_menus');
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_assets');
        
        // AJAX handlers
        $this->loader->add_action('wp_ajax_tscout_search', $ajax, 'handle_search');
        $this->loader->add_action('wp_ajax_tscout_match', $ajax, 'handle_match');
        $this->loader->add_action('wp_ajax_tscout_publish_preview', $ajax, 'handle_publish_preview');
        $this->loader->add_action('wp_ajax_tscout_publish', $ajax, 'handle_publish');
        $this->loader->add_action('wp_ajax_tscout_bulk_action', $ajax, 'handle_bulk_action');
        $this->loader->add_action('wp_ajax_tscout_job_status', $ajax, 'handle_job_status');
        $this->loader->add_action('wp_ajax_tscout_retry_job', $ajax, 'handle_retry_job');
        $this->loader->add_action('wp_ajax_tscout_cancel_job', $ajax, 'handle_cancel_job');
        $this->loader->add_action('wp_ajax_tscout_dismiss_discovery', $ajax, 'handle_dismiss');
        $this->loader->add_action('wp_ajax_tscout_update_license', $ajax, 'handle_update_license');
        
        // Admin notices
        $this->loader->add_action('admin_notices', $admin, 'admin_notices');
        
        // Job processing
        $this->loader->add_action('tscout_process_queue', $queue, 'process_queue');
        $this->loader->add_action('tscout_cleanup', $queue, 'cleanup_old_data');
        
        // Plugin loaded check for dependencies
        $this->loader->add_action('admin_init', $this, 'check_dependencies');
    }
    
    private function define_job_hooks() {
        // Additional job hooks can be added here
    }
    
    public function check_dependencies() {
        // Check if Taranehyar is active - soft dependency
        // We don't prevent activation, just show notice
        if (!class_exists('TaranehYar_Settings') && current_user_can('manage_options')) {
            // Dependency check is done in admin_notices
        }
    }
    
    public function run() {
        $this->loader->run();
        
        // Initialize provider registry
        ProviderRegistry::init();
        
        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('taranehyar-scout', false, dirname(TSCOUT_PLUGIN_BASENAME) . '/languages/');
    }
    
    public function get_version() {
        return $this->version;
    }
}
