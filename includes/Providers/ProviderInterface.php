<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

interface ProviderInterface {
    
    /**
     * Get provider unique ID
     * @return string
     */
    public function get_id();
    
    /**
     * Get provider display name (English)
     * @return string
     */
    public function get_name();
    
    /**
     * Get provider display label (Persian)
     * @return string
     */
    public function get_label();
    
    /**
     * Check if provider is available and configured
     * @return bool
     */
    public function is_available();
    
    /**
     * Get provider status details
     * @return array
     */
    public function get_status();
    
    /**
     * Search for artist tracks
     * @param string $artist_en English artist name
     * @param array $options Search options (limit, types, date_range, etc)
     * @return array|WP_Error Array of normalized tracks or WP_Error
     */
    public function search_artist($artist_en, $options = array());
    
    /**
     * Get detailed track info from source URL
     * @param string $source_url
     * @return array|WP_Error
     */
    public function get_track_details($source_url);
    
    /**
     * Validate provider configuration
     * @return true|WP_Error
     */
    public function validate_config();
}
