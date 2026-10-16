<?php
namespace TaranehyarScout\Admin;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Security\Capability;
use TaranehyarScout\Providers\ProviderRegistry;
use TaranehyarScout\Jobs\JobQueue;
use TaranehyarScout\Catalog\TaranehyarAdapter;
use TaranehyarScout\HostDL\HostDLAdapter;

class AdminPages {
    
    public function register_menus() {
        add_menu_page(
            'ترانه‌یار اسکات',
            'اسکات',
            'edit_posts',
            'tscout-dashboard',
            array($this, 'page_dashboard'),
            'dashicons-search',
            30
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'داشبورد اسکات',
            'داشبورد',
            'edit_posts',
            'tscout-dashboard',
            array($this, 'page_dashboard')
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'کشف آثار',
            'کشف آثار',
            'publish_posts',
            'tscout-discovery',
            array($this, 'page_discovery')
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'نتایج کشف',
            'نتایج کشف',
            'edit_posts',
            'tscout-results',
            array($this, 'page_results')
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'وضعیت انتقال‌ها',
            'وضعیت انتقال‌ها',
            'manage_options',
            'tscout-transfers',
            array($this, 'page_transfers')
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'تنظیمات اسکات',
            'تنظیمات',
            'manage_options',
            'tscout-settings',
            array($this, 'page_settings')
        );
        
        add_submenu_page(
            'tscout-dashboard',
            'درباره اسکات',
            'درباره',
            'edit_posts',
            'tscout-about',
            array($this, 'page_about')
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'tscout') === false) {
            return;
        }
        
        wp_enqueue_style('tscout-admin', TSCOUT_PLUGIN_URL . 'assets/css/admin.css', array(), TSCOUT_VERSION);
        wp_enqueue_script('tscout-admin', TSCOUT_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), TSCOUT_VERSION, true);
        
        wp_localize_script('tscout-admin', 'tscout_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tscout_ajax'),
            'i18n' => array(
                'searching' => 'در حال جستجو...',
                'matching' => 'در حال تطبیق...',
                'publishing' => 'در حال انتشار...',
                'confirm_publish' => 'آیا از انتشار این اثر اطمینان دارید؟',
                'confirm_bulk' => 'آیا از انجام عملیات گروهی اطمینان دارید؟',
                'error' => 'خطا رخ داد',
                'success' => 'عملیات موفق',
            )
        ));
    }
    
    public function admin_notices() {
        $adapter = TaranehyarAdapter::get_instance();
        if (!$adapter->is_active() && current_user_can('manage_options')) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>ترانه‌یار اسکات:</strong> افزونه اصلی ترانه‌یار فعال نیست. برخی قابلیت‌ها ممکن است محدود شوند. ';
            echo '<a href="https://github.com/Masoumiofficial/taranehyar" target="_blank">دریافت ترانه‌یار</a>';
            echo '</p></div>';
        }
        
        $hostdl = new HostDLAdapter();
        if (!$hostdl->is_configured() && current_user_can('manage_options') && isset($_GET['page']) && strpos($_GET['page'], 'tscout') !== false) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>ترانه‌یار اسکات:</strong> هاست دانلود پیکربندی نشده است. برای انتقال فایل‌ها، تنظیمات ترانه‌یار را تکمیل کنید.';
            echo '</p></div>';
        }
    }
    
    public function page_dashboard() {
        Capability::check_view();
        
        $queue = new JobQueue();
        $stats = $queue->get_stats();
        
        global $wpdb;
        $discovery_stats = $wpdb->get_row("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN match_status = 'not_published' THEN 1 ELSE 0 END) as not_published,
            SUM(CASE WHEN match_status = 'published' THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN discovery_status = 'new' THEN 1 ELSE 0 END) as new_count,
            SUM(CASE WHEN license_status = 'needs_review' THEN 1 ELSE 0 END) as needs_review
            FROM " . TSCOUT_TABLE_DISCOVERIES, ARRAY_A);
        
        $providers = ProviderRegistry::get_all();
        $enabled_providers = ProviderRegistry::get_enabled();
        
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/dashboard.php';
    }
    
    public function page_discovery() {
        Capability::check_publish();
        
        $providers = ProviderRegistry::get_all();
        $enabled = ProviderRegistry::get_enabled();
        
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/discovery.php';
    }
    
    public function page_results() {
        Capability::check_view();
        
        global $wpdb;
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $filter_provider = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        $where = array('1=1');
        $params = array();
        
        if (!empty($filter_status)) {
            $where[] = 'match_status = %s';
            $params[] = $filter_status;
        }
        
        if (!empty($filter_provider)) {
            $where[] = 'provider = %s';
            $params[] = $filter_provider;
        }
        
        if (!empty($search)) {
            $where[] = '(title LIKE %s OR artist_en LIKE %s OR artist_fa LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        
        $count_query = "SELECT COUNT(*) FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE $where_sql";
        if (!empty($params)) {
            $count_query = $wpdb->prepare($count_query, $params);
        }
        $total = intval($wpdb->get_var($count_query));
        $total_pages = ceil($total / $per_page);
        
        $query = "SELECT * FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, array($per_page, $offset));
        $query = $wpdb->prepare($query, $query_params);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $providers = ProviderRegistry::get_all();
        
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/results.php';
    }
    
    public function page_transfers() {
        Capability::check_manage();
        
        $queue = new JobQueue();
        $jobs = $queue->get_jobs(null, 50);
        $stats = $queue->get_stats();
        
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/transfers.php';
    }
    
    public function page_settings() {
        Capability::check_manage();
        
        if (isset($_POST['tscout_save_settings']) && check_admin_referer('tscout_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
        }
        
        $options = get_option('tscout_options', array());
        $providers = ProviderRegistry::get_all();
        
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/settings.php';
    }
    
    public function page_about() {
        Capability::check_view();
        include TSCOUT_PLUGIN_DIR . 'includes/Admin/views/about.php';
    }
    
    private function save_settings() {
        $options = get_option('tscout_options', array());
        
        $new_options = array();
        
        $new_options['matching_threshold_exact'] = isset($_POST['matching_threshold_exact']) ? intval($_POST['matching_threshold_exact']) : 95;
        $new_options['matching_threshold_high'] = isset($_POST['matching_threshold_high']) ? intval($_POST['matching_threshold_high']) : 80;
        $new_options['matching_threshold_medium'] = isset($_POST['matching_threshold_medium']) ? intval($_POST['matching_threshold_medium']) : 60;
        $new_options['max_results_per_search'] = isset($_POST['max_results_per_search']) ? intval($_POST['max_results_per_search']) : 50;
        $new_options['request_timeout'] = isset($_POST['request_timeout']) ? intval($_POST['request_timeout']) : 30;
        $new_options['cleanup_days'] = isset($_POST['cleanup_days']) ? intval($_POST['cleanup_days']) : 30;
        $new_options['enable_mock_provider'] = isset($_POST['enable_mock_provider']) ? 1 : 0;
        $new_options['allow_external_urls'] = isset($_POST['allow_external_urls']) ? 1 : 0;
        
        // Provider policies
        $providers = ProviderRegistry::get_all();
        $policies = isset($options['provider_policies']) ? $options['provider_policies'] : array();
        
        foreach ($providers as $id => $provider) {
            if (!isset($policies[$id])) {
                $policies[$id] = array();
            }
            $policies[$id]['enabled'] = isset($_POST['provider_enabled_' . $id]) ? 1 : 0;
            $policies[$id]['license_status'] = isset($_POST['provider_license_' . $id]) ? sanitize_text_field($_POST['provider_license_' . $id]) : 'needs_review';
            $policies[$id]['allow_audio_download'] = isset($_POST['provider_allow_audio_' . $id]) ? 1 : 0;
            $policies[$id]['allow_cover_download'] = isset($_POST['provider_allow_cover_' . $id]) ? 1 : 0;
        }
        
        $new_options['provider_policies'] = $policies;
        
        // Merge with existing
        $merged = array_merge($options, $new_options);
        update_option('tscout_options', $merged, false);
    }
}
