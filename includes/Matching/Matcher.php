<?php
namespace TaranehyarScout\Matching;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Core\Constants;
use TaranehyarScout\Catalog\ArchiveScanner;
use TaranehyarScout\Metadata\Normalizer;
use TaranehyarScout\Logging\Logger;

class Matcher {
    
    private $scanner;
    private $thresholds;
    
    public function __construct() {
        $this->scanner = new ArchiveScanner();
        $options = get_option('tscout_options', array());
        $this->thresholds = array(
            'exact' => isset($options['matching_threshold_exact']) ? intval($options['matching_threshold_exact']) : 95,
            'high' => isset($options['matching_threshold_high']) ? intval($options['matching_threshold_high']) : 80,
            'medium' => isset($options['matching_threshold_medium']) ? intval($options['matching_threshold_medium']) : 60,
        );
    }
    
    /**
     * Match a single discovery against archive
     */
    public function match($discovery) {
        if (!is_array($discovery)) {
            return $this->create_result(Constants::MATCH_UNKNOWN, 0, 'داده نامعتبر', array());
        }
        
        $external_id = isset($discovery['external_id']) ? $discovery['external_id'] : '';
        $provider = isset($discovery['provider']) ? $discovery['provider'] : '';
        $artist_en = isset($discovery['artist_en']) ? $discovery['artist_en'] : '';
        $title = isset($discovery['title']) ? $discovery['title'] : '';
        
        // Check if already matched via our own table
        $existing_match = $this->check_existing_match($provider, $external_id);
        if ($existing_match) {
            return $this->create_result(
                $existing_match['match_status'],
                100,
                'قبلاً تطبیق داده شده',
                array($existing_match),
                $existing_match['matched_post_id']
            );
        }
        
        // Find matches in archive
        $matches = $this->scanner->find_matches($discovery, $this->thresholds['medium']);
        
        if (empty($matches)) {
            return $this->create_result(
                Constants::MATCH_NOT_PUBLISHED,
                0,
                'در آرشیو یافت نشد',
                array()
            );
        }
        
        // Analyze best match
        $best_match = $matches[0];
        $similarity = isset($best_match['similarity']) ? $best_match['similarity'] : 0;
        
        // Determine match status based on similarity and post status
        $status = $this->determine_status($best_match, $similarity, $discovery);
        $reason = $this->build_reason($best_match, $similarity, $discovery);
        
        Logger::info('Matched discovery', array(
            'external_id' => $external_id,
            'provider' => $provider,
            'artist' => $artist_en,
            'title' => $title,
            'match_status' => $status,
            'similarity' => $similarity,
            'matched_post_id' => isset($best_match['post_id']) ? $best_match['post_id'] : null
        ));
        
        return $this->create_result(
            $status,
            $similarity,
            $reason,
            $matches,
            isset($best_match['post_id']) ? $best_match['post_id'] : null
        );
    }
    
    /**
     * Match multiple discoveries
     */
    public function match_batch($discoveries) {
        $results = array();
        foreach ($discoveries as $discovery) {
            $results[] = array(
                'discovery' => $discovery,
                'match' => $this->match($discovery)
            );
        }
        return $results;
    }
    
    private function check_existing_match($provider, $external_id) {
        global $wpdb;
        
        if (empty($provider) || empty($external_id)) {
            return null;
        }
        
        $hash = md5($provider . '|' . $external_id);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . TSCOUT_TABLE_DISCOVERIES . " WHERE provider_hash = %s LIMIT 1",
            $hash
        ), ARRAY_A);
        
        if ($row && !empty($row['matched_post_id'])) {
            return $row;
        }
        
        return null;
    }
    
    private function determine_status($best_match, $similarity, $discovery) {
        $post_status = isset($best_match['post_status']) ? $best_match['post_status'] : '';
        
        // Exact match
        if ($similarity >= $this->thresholds['exact']) {
            if ($post_status === 'publish') {
                return Constants::MATCH_PUBLISHED;
            } elseif (in_array($post_status, array('draft', 'pending', 'future'), true)) {
                return Constants::MATCH_DRAFT;
            } else {
                return Constants::MATCH_POSSIBLE_DUPLICATE;
            }
        }
        
        // High similarity
        if ($similarity >= $this->thresholds['high']) {
            // Check for remix/live differences
            $is_remix_diff = $this->is_version_difference($discovery, $best_match);
            if ($is_remix_diff) {
                return Constants::MATCH_NOT_PUBLISHED; // Different version, not duplicate
            }
            return Constants::MATCH_POSSIBLE_DUPLICATE;
        }
        
        // Medium similarity
        if ($similarity >= $this->thresholds['medium']) {
            return Constants::MATCH_NEEDS_MANUAL;
        }
        
        return Constants::MATCH_NOT_PUBLISHED;
    }
    
    private function is_version_difference($discovery, $archive_item) {
        // Check if one is remix and other is not
        $discovery_is_remix = !empty($discovery['is_remix']);
        $archive_title = isset($archive_item['post_title']) ? strtolower($archive_item['post_title']) : '';
        $archive_is_remix = strpos($archive_title, 'remix') !== false || strpos($archive_title, 'ریمیکس') !== false;
        
        if ($discovery_is_remix !== $archive_is_remix) {
            return true;
        }
        
        $discovery_is_live = !empty($discovery['is_live']);
        $archive_is_live = strpos($archive_title, 'live') !== false || strpos($archive_title, 'زنده') !== false;
        
        if ($discovery_is_live !== $archive_is_live) {
            return true;
        }
        
        return false;
    }
    
    private function build_reason($best_match, $similarity, $discovery) {
        $reasons = array();
        
        $reasons[] = "شباهت: {$similarity}%";
        
        if (isset($best_match['post_id'])) {
            $reasons[] = "پست موجود: #{$best_match['post_id']} - {$best_match['post_title']}";
        }
        
        if (isset($best_match['post_status'])) {
            $status_labels = array(
                'publish' => 'منتشر شده',
                'draft' => 'پیش‌نویس',
                'pending' => 'در انتظار',
                'future' => 'زمان‌بندی شده',
            );
            $label = isset($status_labels[$best_match['post_status']]) ? $status_labels[$best_match['post_status']] : $best_match['post_status'];
            $reasons[] = "وضعیت: {$label}";
        }
        
        // Add version difference info
        if (!empty($discovery['is_remix'])) {
            $reasons[] = "نسخه ریمیکس";
        }
        if (!empty($discovery['is_live'])) {
            $reasons[] = "اجرای زنده";
        }
        if (!empty($discovery['is_collab'])) {
            $reasons[] = "همکاری";
        }
        
        return implode(' | ', $reasons);
    }
    
    private function create_result($status, $similarity, $reason, $matches, $matched_post_id = null) {
        return array(
            'status' => $status,
            'similarity' => $similarity,
            'reason' => $reason,
            'matches' => $matches,
            'matched_post_id' => $matched_post_id,
            'is_duplicate' => in_array($status, array(Constants::MATCH_PUBLISHED, Constants::MATCH_DRAFT, Constants::MATCH_POSSIBLE_DUPLICATE), true),
            'can_publish' => in_array($status, array(Constants::MATCH_NOT_PUBLISHED), true),
            'needs_review' => in_array($status, array(Constants::MATCH_NEEDS_MANUAL, Constants::MATCH_POSSIBLE_DUPLICATE), true),
            'label' => $this->get_status_label($status),
        );
    }
    
    public function get_status_label($status) {
        $labels = array(
            Constants::MATCH_PUBLISHED => 'منتشر شده',
            Constants::MATCH_DRAFT => 'پیش‌نویس',
            Constants::MATCH_POSSIBLE_DUPLICATE => 'احتمال تکراری',
            Constants::MATCH_NOT_PUBLISHED => 'منتشر نشده',
            Constants::MATCH_NEEDS_MANUAL => 'نیاز به بررسی دستی',
            Constants::MATCH_UNKNOWN => 'نامشخص',
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
    
    public function get_status_color($status) {
        $colors = array(
            Constants::MATCH_PUBLISHED => '#10b981',
            Constants::MATCH_DRAFT => '#f59e0b',
            Constants::MATCH_POSSIBLE_DUPLICATE => '#ef4444',
            Constants::MATCH_NOT_PUBLISHED => '#3b82f6',
            Constants::MATCH_NEEDS_MANUAL => '#8b5cf6',
            Constants::MATCH_UNKNOWN => '#6b7280',
        );
        return isset($colors[$status]) ? $colors[$status] : '#6b7280';
    }
}
