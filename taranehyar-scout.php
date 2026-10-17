<?php
/**
 * Plugin Name:       Taranehyar Scout
 * Plugin URI:        https://etehadwp.com/
 * Description:       کشف، تطبیق و مدیریت هوشمند آثار موسیقی در وردپرس - مکمل ترانه‌یار 1.0.10. کشف کن، تطبیق بده، هوشمندانه منتشر کن. سازگار با قالب رز موزیک rozmusic.com - مبنا: فقط بیا2موزیک - رفع کامل نام خواننده و شناسایی موزیک‌ها (مجید اصلاحی)
 * Version:           1.0.10
 * Author:            اتحاد وردپرس
 * Author URI:        https://etehadwp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taranehyar-scout
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * 
 * ترانه‌یار اسکات | کشف و مدیریت هوشمند آثار موسیقی
 * توسعه‌دهنده: اتحاد وردپرس - https://etehadwp.com/
 * شعار: کشف کن، تطبیق بده، هوشمندانه منتشر کن.
 * سازگار با: Taranehyar 1.0.6, قالب رز موزیک rozmusic.com - نگاشت دقیق زمینه‌ها + متن ترانه ورس به ورس
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('TSCOUT_VERSION', '1.0.10');
define('TSCOUT_PLUGIN_FILE', __FILE__);
define('TSCOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSCOUT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TSCOUT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Table names
global $wpdb;
define('TSCOUT_TABLE_DISCOVERIES', $wpdb->prefix . 'taranehyar_scout_discoveries');
define('TSCOUT_TABLE_JOBS', $wpdb->prefix . 'taranehyar_scout_jobs');
define('TSCOUT_TABLE_LOGS', $wpdb->prefix . 'taranehyar_scout_logs');

// Load core
require_once TSCOUT_PLUGIN_DIR . 'includes/Core/Constants.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Security/Sanitizer.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Security/UrlValidator.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Security/Capability.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Logging/Logger.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Core/Activator.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Core/Deactivator.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Core/Loader.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/ProviderInterface.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/ProviderRegistry.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/MyBia2MusicProvider.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/AhaangProvider.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/NicMusicProvider.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/Ganja2MusicProvider.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Providers/MockProvider.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Metadata/Normalizer.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Catalog/TaranehyarAdapter.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Catalog/ArchiveScanner.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Matching/Matcher.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/HostDL/HostDLAdapter.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Jobs/JobQueue.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Publishing/Publisher.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Publishing/StateMachine.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Admin/AdminPages.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Admin/AjaxHandlers.php';
require_once TSCOUT_PLUGIN_DIR . 'includes/Core/Plugin.php';

/**
 * Activation hook
 */
function tscout_activate() {
    $activator = new TaranehyarScout\Core\Activator();
    $activator->activate();
}
register_activation_hook(__FILE__, 'tscout_activate');

/**
 * Deactivation hook
 */
function tscout_deactivate() {
    $deactivator = new TaranehyarScout\Core\Deactivator();
    $deactivator->deactivate();
}
register_deactivation_hook(__FILE__, 'tscout_deactivate');

/**
 * Initialize plugin
 */
function tscout_init() {
    $plugin = new TaranehyarScout\Core\Plugin();
    $plugin->run();
}
add_action('plugins_loaded', 'tscout_init');

/**
 * Add settings link on plugins page
 */
function tscout_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=tscout-dashboard') . '">تنظیمات</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . TSCOUT_PLUGIN_BASENAME, 'tscout_settings_link');
