<?php
namespace TaranehyarScout\Providers;

if (!defined('ABSPATH')) exit;

class ProviderRegistry {
    
    private static $providers = array();
    private static $initialized = false;
    
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        // Register built-in providers - مبنا فقط mybia2music و ahaang بر اساس درخواست کاربر
        self::register(new MyBia2MusicProvider());
        self::register(new AhaangProvider());
        self::register(new MockProvider());
        // سایر providerها (NicMusic, Ganja2Music) به صورت فایل باقی می‌مانند اما ثبت نمی‌شوند تا فقط مبنا دو سایت اصلی باشد
        // در صورت نیاز می‌توان از طریق فیلتر tscout_providers اضافه کرد
        
        self::$initialized = true;
        
        // Allow third-party providers via filter
        $external_providers = apply_filters('tscout_providers', array());
        foreach ($external_providers as $provider) {
            if ($provider instanceof ProviderInterface) {
                self::register($provider);
            }
        }
    }
    
    public static function register(ProviderInterface $provider) {
        self::$providers[$provider->get_id()] = $provider;
    }
    
    public static function get($provider_id) {
        return isset(self::$providers[$provider_id]) ? self::$providers[$provider_id] : null;
    }
    
    public static function get_all() {
        return self::$providers;
    }
    
    public static function get_available() {
        $available = array();
        foreach (self::$providers as $id => $provider) {
            if ($provider->is_available()) {
                $available[$id] = $provider;
            }
        }
        return $available;
    }
    
    public static function get_enabled() {
        $options = get_option('tscout_options', array());
        $policies = isset($options['provider_policies']) ? $options['provider_policies'] : array();
        
        $enabled = array();
        foreach (self::$providers as $id => $provider) {
            $policy = isset($policies[$id]) ? $policies[$id] : array();
            $is_enabled = isset($policy['enabled']) ? (bool) $policy['enabled'] : true;
            
            // Mock provider disabled by default unless explicitly enabled
            if ($id === 'mock' && !isset($policies[$id])) {
                $is_enabled = false;
            }
            
            if ($is_enabled && $provider->is_available()) {
                $enabled[$id] = $provider;
            }
        }
        return $enabled;
    }
}
