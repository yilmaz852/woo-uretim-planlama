<?php
/**
 * Cache sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Cache {
    
    private static $cache_group = 'wup_cache';
    
    /**
     * Önbellekten veri al veya oluştur
     */
    public static function get($key, $callback = null, $expiration = 3600) {
        $cache_key = self::make_key($key);
        $data = get_transient($cache_key);
        
        if ($data === false && is_callable($callback)) {
            $data = call_user_func($callback);
            if ($data !== null) {
                set_transient($cache_key, $data, $expiration);
            }
        }
        
        return $data;
    }
    
    /**
     * Önbelleğe veri kaydet
     */
    public static function set($key, $data, $expiration = 3600) {
        $cache_key = self::make_key($key);
        return set_transient($cache_key, $data, $expiration);
    }
    
    /**
     * Önbellekten veri sil
     */
    public static function delete($key) {
        $cache_key = self::make_key($key);
        return delete_transient($cache_key);
    }
    
    /**
     * Grup bazlı önbellek temizle
     */
    public static function clear_group($group) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_wup_' . $group . '_%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_wup_' . $group . '_%'
            )
        );
    }
    
    /**
     * Tüm eklenti önbelleğini temizle
     */
    public static function clear_all() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wup_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wup_%'");
    }
    
    /**
     * Önbellek anahtarı oluştur
     */
    private static function make_key($key) {
        if (is_array($key)) {
            $key = md5(wp_json_encode($key));
        }
        return 'wup_' . sanitize_key($key);
    }
}
