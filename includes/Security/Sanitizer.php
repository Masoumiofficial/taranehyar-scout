<?php
namespace TaranehyarScout\Security;

if (!defined('ABSPATH')) exit;

class Sanitizer {
    
    public static function sanitize_artist_name($name) {
        $name = trim((string) $name);
        $name = sanitize_text_field($name);
        // Remove excessive spaces
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }
    
    public static function sanitize_text($text) {
        return sanitize_text_field($text);
    }
    
    public static function sanitize_url($url) {
        return esc_url_raw($url);
    }
    
    public static function sanitize_int($value, $default = 0) {
        $int = intval($value);
        return $int ? $int : $default;
    }
    
    public static function sanitize_filename($name) {
        $name = str_replace(array('..', "\\", "\0"), '', (string) $name);
        $name = preg_replace('#[/\\\\]+#', '-', $name);
        $name = preg_replace('/[^A-Za-z0-9\-_\.\(\)\[\] ]/u', '', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name), " .-");
        return $name !== '' ? $name : 'file';
    }
    
    public static function normalize_string($str) {
        $str = (string) $str;
        $str = strtolower($str);
        // Remove accents if function exists
        if (function_exists('remove_accents')) {
            $str = remove_accents($str);
        }
        // Keep only alphanumeric and spaces
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        $str = trim($str);
        return $str;
    }
    
    public static function sanitize_provider_id($provider) {
        return sanitize_key($provider);
    }
    
    public static function sanitize_license_status($status) {
        $allowed = array('verified', 'needs_review', 'metadata_only', 'file_allowed_not_verified', 'forbidden');
        return in_array($status, $allowed, true) ? $status : 'needs_review';
    }
    
    public static function sanitize_match_status($status) {
        $allowed = array('published', 'draft', 'possible_duplicate', 'not_published', 'needs_manual_review', 'unknown');
        return in_array($status, $allowed, true) ? $status : 'unknown';
    }
}
