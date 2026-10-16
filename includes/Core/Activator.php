<?php
namespace TaranehyarScout\Core;

if (!defined('ABSPATH')) exit;

class Activator {
    
    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        $this->schedule_cron();
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Discoveries table - نسخه 1.0.1 با ستون‌های lyrics و title_en و music_text برای قالب آهنگ‌بان
        $sql_discoveries = "CREATE TABLE IF NOT EXISTS " . TSCOUT_TABLE_DISCOVERIES . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(255) NOT NULL,
            provider varchar(100) NOT NULL,
            provider_hash varchar(255) NOT NULL,
            artist_en varchar(255) NOT NULL,
            artist_fa varchar(255) DEFAULT NULL,
            artist_normalized varchar(255) NOT NULL,
            title varchar(500) NOT NULL,
            title_normalized varchar(500) NOT NULL,
            title_fa varchar(500) DEFAULT NULL,
            title_en varchar(500) DEFAULT NULL,
            album varchar(500) DEFAULT NULL,
            track_type varchar(50) DEFAULT 'single',
            year int(4) DEFAULT NULL,
            duration int DEFAULT NULL,
            genre varchar(100) DEFAULT NULL,
            language varchar(20) DEFAULT NULL,
            source_url text NOT NULL,
            cover_url text DEFAULT NULL,
            audio_url_128 text DEFAULT NULL,
            audio_url_320 text DEFAULT NULL,
            video_url_480 text DEFAULT NULL,
            video_url_720 text DEFAULT NULL,
            video_url_1080 text DEFAULT NULL,
            lyrics longtext DEFAULT NULL,
            metadata_json longtext DEFAULT NULL,
            confidence float DEFAULT 0,
            license_status varchar(50) DEFAULT 'needs_review',
            match_status varchar(50) DEFAULT 'unknown',
            matched_post_id bigint(20) DEFAULT NULL,
            discovery_status varchar(50) DEFAULT 'new',
            is_remix tinyint(1) DEFAULT 0,
            is_live tinyint(1) DEFAULT 0,
            is_collab tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY provider_hash (provider_hash),
            KEY provider (provider),
            KEY artist_normalized (artist_normalized),
            KEY title_normalized (title_normalized),
            KEY match_status (match_status),
            KEY discovery_status (discovery_status),
            KEY matched_post_id (matched_post_id)
        ) $charset_collate;";
        
        // Jobs table
        $sql_jobs = "CREATE TABLE IF NOT EXISTS " . TSCOUT_TABLE_JOBS . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(100) NOT NULL,
            job_type varchar(100) NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(50) DEFAULT 'queued',
            progress int DEFAULT 0,
            attempts int DEFAULT 0,
            max_attempts int DEFAULT 3,
            last_error text DEFAULT NULL,
            checkpoint varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            scheduled_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_id (job_id),
            KEY status (status),
            KEY job_type (job_type),
            KEY scheduled_at (scheduled_at)
        ) $charset_collate;";
        
        // Logs table
        $sql_logs = "CREATE TABLE IF NOT EXISTS " . TSCOUT_TABLE_LOGS . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) DEFAULT 'info',
            message text NOT NULL,
            context_json longtext DEFAULT NULL,
            job_id varchar(100) DEFAULT NULL,
            discovery_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY job_id (job_id),
            KEY discovery_id (discovery_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_discoveries);
        dbDelta($sql_jobs);
        dbDelta($sql_logs);
        
        // Migration برای نسخه 1.0.1 - اضافه کردن ستون‌های جدید اگر وجود ندارند
        $this->migrate_v101();
    }
    
    private function migrate_v101() {
        global $wpdb;
        
        // بررسی و اضافه کردن ستون title_en
        $col = $wpdb->get_var("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES . " LIKE 'title_en'");
        if (!$col) {
            $wpdb->query("ALTER TABLE " . TSCOUT_TABLE_DISCOVERIES . " ADD COLUMN title_en varchar(500) DEFAULT NULL AFTER title_fa");
        }
        
        // بررسی و اضافه کردن ستون lyrics
        $col = $wpdb->get_var("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES . " LIKE 'lyrics'");
        if (!$col) {
            $wpdb->query("ALTER TABLE " . TSCOUT_TABLE_DISCOVERIES . " ADD COLUMN lyrics longtext DEFAULT NULL AFTER video_url_1080");
        }
        
        // به‌روزرسانی تنظیمات برای نسخه 1.0.1
        $options = get_option('tscout_options', array());
        if (empty($options['theme_meta_mapping'])) {
            $options['theme_meta_mapping'] = array(
                'singer_taxonomy' => 'singer',
                'musics_type' => 'musics_type',
                'artist_en' => 'artist_en',
                'song_en' => 'song_en',
                'art_name' => 'artist',
                'track_name' => 'song',
                'music_txt' => 'music_text',
                'music128' => 'music128',
                'music320' => 'music320',
                'album' => 'album',
                'album128' => 'album128',
                'album320' => 'album320',
                'video480p' => 'video480',
                'video720p' => 'video720',
                'video1080p' => 'video1080',
            );
            $options['allow_external_urls'] = 1;
            if (isset($options['provider_policies']['mybia2music'])) {
                $options['provider_policies']['mybia2music']['allow_audio_download'] = 1;
                $options['provider_policies']['mybia2music']['allow_cover_download'] = 1;
            }
            update_option('tscout_options', $options, false);
        }
        
        update_option('tscout_version', TSCOUT_VERSION, false);
    }
    
    private function set_default_options() {
        $defaults = array(
            'version' => TSCOUT_VERSION,
            'matching_threshold_exact' => 95,
            'matching_threshold_high' => 80,
            'matching_threshold_medium' => 60,
            'max_results_per_search' => 50,
            'max_requests_per_provider' => 10,
            'request_timeout' => 30,
            'enable_mock_provider' => 0,
            'default_license_status' => 'needs_review',
            'auto_publish' => 0,
            'enable_background_jobs' => 1,
            'cleanup_days' => 30,
            'allow_external_urls' => 1, // اجازه استفاده از URL خارجی در صورت عدم انتقال به HostDL
            'provider_policies' => array(
                'mybia2music' => array(
                    'enabled' => 1,
                    'rate_limit' => 2,
                    'license_status' => 'needs_review',
                    'allow_audio_download' => 1, // اصلاح: اجازه انتقال فایل پس از تایید مجوز
                    'allow_cover_download' => 1,
                    'metadata_allowed' => 1,
                ),
                'mock' => array(
                    'enabled' => 0,
                    'rate_limit' => 0,
                    'license_status' => 'verified',
                    'allow_audio_download' => 1,
                    'allow_cover_download' => 1,
                    'metadata_allowed' => 1,
                )
            ),
            'installed_at' => current_time('mysql'),
            'developer' => 'اتحاد وردپرس',
            'developer_url' => 'https://etehadwp.com/',
            // نگاشت پیش‌فرض برای قالب‌هایی که از کلیدهای متفاوت استفاده می‌کنند (مثل قالب آهنگ‌بان)
            'theme_meta_mapping' => array(
                'singer_taxonomy' => 'singer',
                'musics_type' => 'musics_type',
                'artist_en' => 'artist_en',
                'song_en' => 'song_en',
                'art_name' => 'artist',
                'track_name' => 'song',
                'music_txt' => 'music_text',
                'music128' => 'music128',
                'music320' => 'music320',
                'album' => 'album',
                'album128' => 'album128',
                'album320' => 'album320',
                'video480p' => 'video480',
                'video720p' => 'video720',
                'video1080p' => 'video1080',
            ),
        );
        
        $existing = get_option('tscout_options', array());
        if (empty($existing)) {
            update_option('tscout_options', $defaults, false);
        } else {
            // Merge defaults for new keys
            $merged = array_merge($defaults, $existing);
            update_option('tscout_options', $merged, false);
        }
    }
    
    private function schedule_cron() {
        if (!wp_next_scheduled('tscout_process_queue')) {
            wp_schedule_event(time(), 'every_five_minutes', 'tscout_process_queue');
        }
        if (!wp_next_scheduled('tscout_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tscout_cleanup');
        }
        
        // Add custom cron interval if not exists
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['every_five_minutes'])) {
                $schedules['every_five_minutes'] = array(
                    'interval' => 300,
                    'display' => 'هر 5 دقیقه'
                );
            }
            return $schedules;
        });
    }
}
