<?php
namespace TaranehyarScout\Core;

if (!defined('ABSPATH')) exit;

class Deactivator {
    
    public function deactivate() {
        $this->clear_cron();
        flush_rewrite_rules();
    }
    
    private function clear_cron() {
        wp_clear_scheduled_hook('tscout_process_queue');
        wp_clear_scheduled_hook('tscout_cleanup');
    }
}
