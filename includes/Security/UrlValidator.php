<?php
namespace TaranehyarScout\Security;

if (!defined('ABSPATH')) exit;

class UrlValidator {
    
    /**
     * Validate URL against SSRF and other security issues
     * Based on TaranehYar_Http::validate_remote_url but stricter
     */
    public static function validate($url, $allow_empty = false) {
        if (empty($url)) {
            return $allow_empty ? true : new \WP_Error('empty_url', 'آدرس خالی است.');
        }
        
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || !in_array($parts['scheme'], array('http', 'https'), true)) {
            return new \WP_Error('invalid_scheme', 'آدرس باید با http یا https شروع شود.');
        }
        
        $host = isset($parts['host']) ? $parts['host'] : '';
        if (empty($host)) {
            return new \WP_Error('invalid_host', 'میزبان آدرس نامعتبر است.');
        }
        
        // Block obvious internal hosts
        $blocked_hosts = array('localhost', '127.0.0.1', '0.0.0.0', '::1', '::ffff:127.0.0.1');
        if (in_array(strtolower($host), $blocked_hosts, true)) {
            return new \WP_Error('ssrf_blocked', 'آدرس‌های داخلی مجاز نیستند.');
        }
        
        // Check if host is IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new \WP_Error('ssrf_blocked', 'آدرس IP داخلی مجاز نیست.');
            }
        } else {
            // Resolve DNS and check IPs
            // Suppress warnings for DNS failures
            $ips = @gethostbynamel($host);
            if (is_array($ips)) {
                foreach ($ips as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return new \WP_Error('ssrf_blocked', 'این دامنه به یک آدرس داخلی اشاره می‌کند و مجاز نیست.');
                    }
                }
            }
            // If DNS fails, we still allow it - might be temporary, but log it
        }
        
        // Additional checks: block private TLDs and suspicious patterns
        if (preg_match('/\.(local|internal|localhost|test|example|invalid)$/i', $host)) {
            return new \WP_Error('ssrf_blocked', 'دامنه خصوصی مجاز نیست.');
        }
        
        // Block URLs with credentials
        if (isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error('invalid_url', 'آدرس نباید شامل نام کاربری یا رمز عبور باشد.');
        }
        
        // Check for path traversal in URL
        if (strpos($url, '..') !== false || strpos($url, "\0") !== false) {
            return new \WP_Error('invalid_url', 'آدرس شامل کاراکترهای غیرمجاز است.');
        }
        
        return true;
    }
    
    public static function is_valid($url, $allow_empty = false) {
        $result = self::validate($url, $allow_empty);
        return $result === true;
    }
    
    /**
     * Validate that URL is from allowed provider domains
     */
    public static function is_allowed_provider_domain($url, $provider_id) {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return false;
        }
        
        $host = strtolower($parts['host']);
        
        $allowed_domains = array(
            'mybia2music' => array('mybia2music.com', 'sv2.mybia2music.com', 'sv.mybia2music.com', 'www.mybia2music.com'),
            'mock' => array('example.com', 'mock.local'),
        );
        
        if (!isset($allowed_domains[$provider_id])) {
            // For unknown providers, allow any public URL (already validated by SSRF check)
            return true;
        }
        
        foreach ($allowed_domains[$provider_id] as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sanitize and validate external URL for storage
     */
    public static function sanitize_and_validate($url) {
        $url = esc_url_raw($url);
        if (empty($url)) {
            return '';
        }
        
        $valid = self::validate($url, true);
        if (is_wp_error($valid)) {
            return new \WP_Error('invalid_url', $valid->get_error_message());
        }
        
        return $url;
    }
}
