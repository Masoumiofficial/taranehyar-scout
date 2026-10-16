<?php
namespace TaranehyarScout\Jobs;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Logging\Logger;

class JobQueue {
    
    const MAX_ATTEMPTS = 3;
    const BATCH_SIZE = 5;
    
    public function __construct() {
        // Constructor
    }
    
    /**
     * Add job to queue
     */
    public function add_job($type, $payload, $scheduled_at = null) {
        global $wpdb;
        
        $job_id = $this->generate_job_id();
        
        $data = array(
            'job_id' => $job_id,
            'job_type' => sanitize_key($type),
            'payload_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'queued',
            'progress' => 0,
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'created_at' => current_time('mysql'),
            'scheduled_at' => $scheduled_at ? $scheduled_at : current_time('mysql'),
        );
        
        $result = $wpdb->insert(TSCOUT_TABLE_JOBS, $data);
        
        if ($result) {
            Logger::info('Job added to queue', array('job_id' => $job_id, 'type' => $type));
            
            // Schedule immediate processing if not already scheduled
            if (!wp_next_scheduled('tscout_process_queue')) {
                wp_schedule_single_event(time() + 10, 'tscout_process_queue');
            }
            
            return $job_id;
        }
        
        return new \WP_Error('db_error', 'خطا در افزودن job به صف');
    }
    
    /**
     * Process queue (called by WP-Cron)
     */
    public function process_queue() {
        global $wpdb;
        
        // Get pending jobs
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . TSCOUT_TABLE_JOBS . " 
             WHERE status IN ('queued', 'failed') 
             AND attempts < max_attempts 
             AND (scheduled_at IS NULL OR scheduled_at <= %s)
             ORDER BY created_at ASC 
             LIMIT %d",
            current_time('mysql'),
            self::BATCH_SIZE
        ), ARRAY_A);
        
        if (empty($jobs)) {
            return;
        }
        
        Logger::info('Processing job queue', array('count' => count($jobs)));
        
        foreach ($jobs as $job) {
            $this->process_single_job($job);
        }
        
        // Schedule next run if there are more jobs
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM " . TSCOUT_TABLE_JOBS . " WHERE status = 'queued'");
        if ($remaining > 0) {
            wp_schedule_single_event(time() + 60, 'tscout_process_queue');
        }
    }
    
    private function process_single_job($job) {
        global $wpdb;
        
        $job_id = $job['job_id'];
        
        // Mark as processing
        $wpdb->update(
            TSCOUT_TABLE_JOBS,
            array(
                'status' => 'processing',
                'started_at' => current_time('mysql'),
                'attempts' => intval($job['attempts']) + 1,
            ),
            array('job_id' => $job_id)
        );
        
        Logger::info('Processing job', array('job_id' => $job_id, 'type' => $job['job_type'], 'attempt' => intval($job['attempts']) + 1));
        
        try {
            $payload = json_decode($job['payload_json'], true);
            $result = $this->execute_job($job['job_type'], $payload, $job);
            
            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }
            
            // Success
            $wpdb->update(
                TSCOUT_TABLE_JOBS,
                array(
                    'status' => 'completed',
                    'progress' => 100,
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('job_id' => $job_id)
            );
            
            Logger::info('Job completed', array('job_id' => $job_id));
            
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            
            $is_last_attempt = (intval($job['attempts']) + 1) >= intval($job['max_attempts']);
            $new_status = $is_last_attempt ? 'failed' : 'queued';
            $next_schedule = $is_last_attempt ? null : date('Y-m-d H:i:s', time() + $this->get_retry_delay(intval($job['attempts']) + 1));
            
            $wpdb->update(
                TSCOUT_TABLE_JOBS,
                array(
                    'status' => $new_status,
                    'last_error' => $error_message,
                    'scheduled_at' => $next_schedule,
                    'updated_at' => current_time('mysql'),
                ),
                array('job_id' => $job_id)
            );
            
            Logger::error('Job failed', array('job_id' => $job_id, 'error' => $error_message, 'attempt' => intval($job['attempts']) + 1));
        }
    }
    
    private function execute_job($type, $payload, $job) {
        switch ($type) {
            case 'discovery_search':
                return $this->execute_search_job($payload, $job);
                
            case 'discovery_match':
                return $this->execute_match_job($payload, $job);
                
            case 'publish_track':
                return $this->execute_publish_job($payload, $job);
                
            case 'transfer_file':
                return $this->execute_transfer_job($payload, $job);
                
            default:
                // Allow custom job types via filter
                $result = apply_filters('tscout_execute_job', null, $type, $payload, $job);
                if ($result !== null) {
                    return $result;
                }
                return new \WP_Error('unknown_job_type', "نوع job ناشناخته: {$type}");
        }
    }
    
    private function execute_search_job($payload, $job) {
        $artist_en = isset($payload['artist_en']) ? $payload['artist_en'] : '';
        $providers = isset($payload['providers']) ? $payload['providers'] : array();
        $options = isset($payload['options']) ? $payload['options'] : array();
        
        if (empty($artist_en)) {
            return new \WP_Error('empty_artist', 'نام هنرمند خالی است');
        }
        
        $this->update_progress($job['job_id'], 10);
        
        // Use provider registry to search
        $all_discoveries = array();
        
        foreach ($providers as $provider_id) {
            $provider = \TaranehyarScout\Providers\ProviderRegistry::get($provider_id);
            if (!$provider) {
                continue;
            }
            
            $results = $provider->search_artist($artist_en, $options);
            if (!is_wp_error($results)) {
                $all_discoveries = array_merge($all_discoveries, $results);
            }
            
            $this->update_progress($job['job_id'], 10 + (80 * count($all_discoveries) / max(1, count($providers) * 10)));
        }
        
        // Normalize and save discoveries
        $this->update_progress($job['job_id'], 90);
        
        $saved = 0;
        foreach ($all_discoveries as $raw) {
            $normalized = \TaranehyarScout\Metadata\Normalizer::normalize($raw, $raw['provider'] ?? 'unknown');
            if (!is_wp_error($normalized)) {
                $save_result = $this->save_discovery($normalized);
                if (!is_wp_error($save_result)) {
                    $saved++;
                }
            }
        }
        
        $this->update_progress($job['job_id'], 100);
        
        return array('found' => count($all_discoveries), 'saved' => $saved);
    }
    
    private function execute_match_job($payload, $job) {
        $discovery_ids = isset($payload['discovery_ids']) ? $payload['discovery_ids'] : array();
        
        if (empty($discovery_ids)) {
            // Match all unmatched
            global $wpdb;
            $discovery_ids = $wpdb->get_col("SELECT id FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE match_status = 'unknown' LIMIT 100");
        }
        
        $matcher = new \TaranehyarScout\Matching\Matcher();
        $matched = 0;
        
        foreach ($discovery_ids as $index => $discovery_id) {
            $discovery = $this->get_discovery($discovery_id);
            if (!$discovery) {
                continue;
            }
            
            $result = $matcher->match($discovery);
            
            // Update discovery with match result
            global $wpdb;
            $wpdb->update(
                TSCOUT_TABLE_DISCOVERIES,
                array(
                    'match_status' => $result['status'],
                    'matched_post_id' => $result['matched_post_id'],
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $discovery_id)
            );
            
            $matched++;
            $progress = intval(($index + 1) / count($discovery_ids) * 100);
            $this->update_progress($job['job_id'], $progress);
        }
        
        return array('matched' => $matched);
    }
    
    private function execute_publish_job($payload, $job) {
        $publisher = new \TaranehyarScout\Publishing\Publisher();
        return $publisher->publish($payload, $job);
    }
    
    private function execute_transfer_job($payload, $job) {
        $adapter = new \TaranehyarScout\HostDL\HostDLAdapter();
        $type = isset($payload['type']) ? $payload['type'] : 'music';
        $data = isset($payload['data']) ? $payload['data'] : array();
        
        return $adapter->transfer($data, $type);
    }
    
    private function save_discovery($normalized) {
        global $wpdb;
        
        // Check if already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE provider_hash = %s LIMIT 1",
            $normalized['provider_hash']
        ));
        
        if ($existing) {
            // Update existing - نسخه 1.0.1 با title_en و lyrics
            $update_data = array(
                'title' => $normalized['title'],
                'title_normalized' => $normalized['title_normalized'],
                'title_fa' => $normalized['title_fa'],
                'title_en' => $normalized['title_en'] ?? '',
                'artist_fa' => $normalized['artist_fa'],
                'cover_url' => $normalized['cover_url'],
                'audio_url_128' => $normalized['audio_url_128'],
                'audio_url_320' => $normalized['audio_url_320'],
                'lyrics' => $normalized['lyrics'] ?? '',
                'metadata_json' => $normalized['metadata_json'],
                'confidence' => $normalized['confidence'],
                'updated_at' => current_time('mysql'),
            );
            // فقط ستون‌هایی که وجود دارند را به‌روز کن (برای سازگاری با نسخه قدیمی)
            $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES, 0);
            $filtered = array();
            foreach ($update_data as $k => $v) {
                if (in_array($k, $existing_cols)) {
                    $filtered[$k] = $v;
                }
            }
            $wpdb->update(
                TSCOUT_TABLE_DISCOVERIES,
                $filtered,
                array('provider_hash' => $normalized['provider_hash'])
            );
            return intval($existing);
        }
        
        // Insert new - نسخه 1.0.1
        $data = array(
            'external_id' => $normalized['external_id'],
            'provider' => $normalized['provider'],
            'provider_hash' => $normalized['provider_hash'],
            'artist_en' => $normalized['artist_en'],
            'artist_fa' => $normalized['artist_fa'],
            'artist_normalized' => $normalized['artist_normalized'],
            'title' => $normalized['title'],
            'title_normalized' => $normalized['title_normalized'],
            'title_fa' => $normalized['title_fa'],
            'title_en' => $normalized['title_en'] ?? '',
            'album' => $normalized['album'],
            'track_type' => $normalized['track_type'],
            'year' => $normalized['year'],
            'duration' => $normalized['duration'],
            'genre' => $normalized['genre'],
            'language' => $normalized['language'],
            'source_url' => $normalized['source_url'],
            'cover_url' => $normalized['cover_url'],
            'audio_url_128' => $normalized['audio_url_128'],
            'audio_url_320' => $normalized['audio_url_320'],
            'video_url_480' => $normalized['video_url_480'],
            'video_url_720' => $normalized['video_url_720'],
            'video_url_1080' => $normalized['video_url_1080'],
            'lyrics' => $normalized['lyrics'] ?? '',
            'metadata_json' => $normalized['metadata_json'],
            'confidence' => $normalized['confidence'],
            'license_status' => $normalized['license_status'],
            'match_status' => 'unknown',
            'discovery_status' => 'new',
            'is_remix' => $normalized['is_remix'] ? 1 : 0,
            'is_live' => $normalized['is_live'] ? 1 : 0,
            'is_collab' => $normalized['is_collab'] ? 1 : 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        // فیلتر برای ستون‌های موجود
        $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM " . TSCOUT_TABLE_DISCOVERIES, 0);
        if (!empty($existing_cols)) {
            $filtered = array();
            foreach ($data as $k => $v) {
                if (in_array($k, $existing_cols)) {
                    $filtered[$k] = $v;
                }
            }
            $data = $filtered;
        }
        
        $result = $wpdb->insert(TSCOUT_TABLE_DISCOVERIES, $data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return new \WP_Error('db_error', 'خطا در ذخیره کشف');
    }
    
    private function get_discovery($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE id = %d LIMIT 1",
            intval($id)
        ), ARRAY_A);
    }
    
    private function update_progress($job_id, $progress) {
        global $wpdb;
        $wpdb->update(
            TSCOUT_TABLE_JOBS,
            array('progress' => intval($progress), 'updated_at' => current_time('mysql')),
            array('job_id' => $job_id)
        );
    }
    
    private function get_retry_delay($attempt) {
        // Exponential backoff: 1min, 5min, 15min
        $delays = array(60, 300, 900);
        $index = min($attempt - 1, count($delays) - 1);
        return $delays[$index];
    }
    
    private function generate_job_id() {
        return 'tscout_' . time() . '_' . wp_generate_password(8, false, false);
    }
    
    /**
     * Get job by ID
     */
    public function get_job($job_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . TSCOUT_TABLE_JOBS . " WHERE job_id = %s LIMIT 1",
            $job_id
        ), ARRAY_A);
    }
    
    /**
     * Get jobs with filters
     */
    public function get_jobs($status = null, $limit = 20) {
        global $wpdb;
        
        $where = "1=1";
        $params = array();
        
        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        $query = "SELECT * FROM " . TSCOUT_TABLE_JOBS . " WHERE $where ORDER BY created_at DESC LIMIT %d";
        $params[] = intval($limit);
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Retry failed job
     */
    public function retry_job($job_id) {
        global $wpdb;
        
        $job = $this->get_job($job_id);
        if (!$job) {
            return new \WP_Error('not_found', 'Job یافت نشد');
        }
        
        $wpdb->update(
            TSCOUT_TABLE_JOBS,
            array(
                'status' => 'queued',
                'scheduled_at' => current_time('mysql'),
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ),
            array('job_id' => $job_id)
        );
        
        if (!wp_next_scheduled('tscout_process_queue')) {
            wp_schedule_single_event(time() + 10, 'tscout_process_queue');
        }
        
        return true;
    }
    
    /**
     * Cancel job
     */
    public function cancel_job($job_id) {
        global $wpdb;
        
        $wpdb->update(
            TSCOUT_TABLE_JOBS,
            array(
                'status' => 'cancelled',
                'updated_at' => current_time('mysql'),
            ),
            array('job_id' => $job_id)
        );
        
        return true;
    }
    
    /**
     * Cleanup old data
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        $options = get_option('tscout_options', array());
        $days = isset($options['cleanup_days']) ? intval($options['cleanup_days']) : 30;
        
        // Delete old completed jobs
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . TSCOUT_TABLE_JOBS . " WHERE status = 'completed' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Delete old logs
        \TaranehyarScout\Logging\Logger::clear_old_logs($days);
        
        Logger::info('Cleanup completed', array('days' => $days));
    }
    
    /**
     * Get queue stats
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = array(
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
        );
        
        $results = $wpdb->get_results("SELECT status, COUNT(*) as count FROM " . TSCOUT_TABLE_JOBS . " GROUP BY status", ARRAY_A);
        foreach ($results as $row) {
            $status = $row['status'];
            $count = intval($row['count']);
            $stats[$status] = $count;
            $stats['total'] += $count;
        }
        
        return $stats;
    }
}
