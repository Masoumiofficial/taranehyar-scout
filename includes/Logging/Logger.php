<?php
namespace TaranehyarScout\Logging;

if (!defined('ABSPATH')) exit;

class Logger {
    
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    
    public static function log($level, $message, $context = array()) {
        global $wpdb;
        
        $data = array(
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'context_json' => !empty($context) ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'job_id' => isset($context['job_id']) ? sanitize_text_field($context['job_id']) : null,
            'discovery_id' => isset($context['discovery_id']) ? intval($context['discovery_id']) : null,
            'created_at' => current_time('mysql')
        );
        
        // Don't log sensitive data
        if (isset($data['context_json'])) {
            $decoded = json_decode($data['context_json'], true);
            if (is_array($decoded)) {
                unset($decoded['token'], $decoded['password'], $decoded['apitoken']);
                $data['context_json'] = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }
        
        $wpdb->insert(TSCOUT_TABLE_LOGS, $data);
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[TSCOUT][$level] $message " . (!empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''));
        }
    }
    
    public static function debug($message, $context = array()) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }
    
    public static function info($message, $context = array()) {
        self::log(self::LEVEL_INFO, $message, $context);
    }
    
    public static function warning($message, $context = array()) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }
    
    public static function error($message, $context = array()) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }
    
    public static function get_logs($limit = 100, $level = null, $job_id = null) {
        global $wpdb;
        
        $where = array('1=1');
        $params = array();
        
        if ($level) {
            $where[] = 'level = %s';
            $params[] = $level;
        }
        
        if ($job_id) {
            $where[] = 'job_id = %s';
            $params[] = $job_id;
        }
        
        $where_sql = implode(' AND ', $where);
        $query = "SELECT * FROM " . TSCOUT_TABLE_LOGS . " WHERE $where_sql ORDER BY created_at DESC LIMIT %d";
        $params[] = intval($limit);
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    public static function clear_old_logs($days = 30) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . TSCOUT_TABLE_LOGS . " WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            intval($days)
        ));
    }
}
