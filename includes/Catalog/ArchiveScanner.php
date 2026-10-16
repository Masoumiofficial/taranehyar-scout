<?php
namespace TaranehyarScout\Catalog;

if (!defined('ABSPATH')) exit;

use TaranehyarScout\Metadata\Normalizer;
use TaranehyarScout\Security\Sanitizer;

class ArchiveScanner {
    
    private $adapter;
    
    public function __construct() {
        $this->adapter = TaranehyarAdapter::get_instance();
    }
    
    /**
     * Scan existing archive for matching tracks
     */
    public function scan_all($limit = 1000) {
        global $wpdb;
        
        $artist_en_key = $this->adapter->get_meta_key('artist_en');
        $song_en_key = $this->adapter->get_meta_key('song_en');
        $art_name_key = $this->adapter->get_meta_key('art_name');
        $track_name_key = $this->adapter->get_meta_key('track_name');
        
        // Get posts with music meta
        $query = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_date,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) as artist_en,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) as song_en,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) as art_name,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) as track_name
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN (%s, %s, %s, %s)
             WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash', 'auto-draft')
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT %d",
            $artist_en_key, $song_en_key, $art_name_key, $track_name_key,
            $artist_en_key, $song_en_key, $art_name_key, $track_name_key,
            $limit
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        $archive = array();
        foreach ($results as $row) {
            $archive[] = array(
                'post_id' => intval($row['ID']),
                'post_title' => $row['post_title'],
                'post_status' => $row['post_status'],
                'artist_en' => $row['artist_en'] ?: '',
                'song_en' => $row['song_en'] ?: '',
                'art_name' => $row['art_name'] ?: '',
                'track_name' => $row['track_name'] ?: '',
                'artist_normalized' => Normalizer::normalize_artist_for_matching($row['artist_en'] ?: $row['art_name']),
                'title_normalized' => Normalizer::normalize_title_for_matching($row['song_en'] ?: $row['track_name'] ?: $row['post_title']),
            );
        }
        
        return $archive;
    }
    
    /**
     * Find matching posts for a given discovery
     */
    public function find_matches($discovery, $threshold = 80) {
        global $wpdb;
        
        $artist_normalized = isset($discovery['artist_normalized']) ? $discovery['artist_normalized'] : '';
        $title_normalized = isset($discovery['title_normalized']) ? $discovery['title_normalized'] : '';
        
        if (empty($artist_normalized) && empty($title_normalized)) {
            return array();
        }
        
        // First, try exact match on normalized fields if we have indexed data
        // For performance, we search by artist first, then filter by title
        
        $artist_en_key = $this->adapter->get_meta_key('artist_en');
        $song_en_key = $this->adapter->get_meta_key('song_en');
        $art_name_key = $this->adapter->get_meta_key('art_name');
        $track_name_key = $this->adapter->get_meta_key('track_name');
        
        // Build query to find potential matches
        // We'll search posts where artist_en or art_name contains artist, or title contains track
        
        $candidates = $this->scan_all(5000); // Get recent 5000 posts
        
        $matches = array();
        foreach ($candidates as $candidate) {
            $score = $this->calculate_similarity($discovery, $candidate);
            if ($score >= $threshold) {
                $candidate['similarity'] = $score;
                $matches[] = $candidate;
            }
        }
        
        // Sort by similarity descending
        usort($matches, function($a, $b) {
            return $b['similarity'] - $a['similarity'];
        });
        
        return array_slice($matches, 0, 10); // Top 10 matches
    }
    
    /**
     * Calculate similarity between discovery and archive item
     */
    private function calculate_similarity($discovery, $archive_item) {
        $artist_score = 0;
        $title_score = 0;
        
        $discovery_artist = isset($discovery['artist_normalized']) ? $discovery['artist_normalized'] : '';
        $discovery_title = isset($discovery['title_normalized']) ? $discovery['title_normalized'] : '';
        
        $archive_artist = isset($archive_item['artist_normalized']) ? $archive_item['artist_normalized'] : '';
        $archive_title = isset($archive_item['title_normalized']) ? $archive_item['title_normalized'] : '';
        
        // Artist similarity
        if (!empty($discovery_artist) && !empty($archive_artist)) {
            similar_text($discovery_artist, $archive_artist, $artist_percent);
            $artist_score = $artist_percent;
        } elseif (empty($discovery_artist) && empty($archive_artist)) {
            $artist_score = 50; // Neutral if both empty
        }
        
        // Title similarity
        if (!empty($discovery_title) && !empty($archive_title)) {
            similar_text($discovery_title, $archive_title, $title_percent);
            $title_score = $title_percent;
        }
        
        // Weighted average: artist 40%, title 60%
        $total = ($artist_score * 0.4) + ($title_score * 0.6);
        
        // Bonus for exact matches
        if ($discovery_artist === $archive_artist && !empty($discovery_artist)) {
            $total = min(100, $total + 10);
        }
        if ($discovery_title === $archive_title && !empty($discovery_title)) {
            $total = min(100, $total + 10);
        }
        
        return round($total, 1);
    }
    
    /**
     * Check if a discovery already exists in WordPress by title
     */
    public function check_duplicate_by_title($title) {
        return $this->adapter->post_exists_by_title($title);
    }
    
    /**
     * Get post count
     */
    public function get_archive_count() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash', 'auto-draft')");
        return intval($count);
    }
    
    /**
     * Get taxonomy terms for artist
     */
    public function get_artist_terms($artist_name) {
        $taxonomy = $this->adapter->get_singer_taxonomy();
        if (!taxonomy_exists($taxonomy)) {
            return array();
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'name' => $artist_name,
            'hide_empty' => false,
        ));
        
        return is_wp_error($terms) ? array() : $terms;
    }
}
