<?php
/**
 * Ayarlar sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Settings {
    
    const OPTION_KEY = 'wup_settings';
    
    private static $settings = null;
    
    /**
     * Tüm ayarları al
     */
    public static function get_all() {
        if (self::$settings === null) {
            self::$settings = wp_parse_args(
                get_option(self::OPTION_KEY, array()),
                self::get_defaults()
            );
        }
        return self::$settings;
    }
    
    /**
     * Tek bir ayarı al
     */
    public static function get($key, $default = null) {
        $settings = self::get_all();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    /**
     * Ayarları kaydet
     */
    public static function save($settings) {
        $validated = self::validate($settings);
        update_option(self::OPTION_KEY, $validated);
        self::$settings = null;
        WUP_Cache::clear_all();
        return true;
    }
    
    /**
     * Varsayılan ayarlar
     */
    public static function get_defaults() {
        return array(
            'personnel_count' => 1,
            'daily_hours' => 8,
            'working_days' => array('1', '2', '3', '4', '5'),
            'status_durations' => array(),
            'notifications_enabled' => 0,
            'notification_threshold' => 24,
            'notification_email' => get_option('admin_email'),
            'cache_duration' => 3600
        );
    }
    
    /**
     * Ayarları doğrula
     */
    public static function validate($input) {
        $output = self::get_defaults();
        
        if (isset($input['personnel_count'])) {
            $output['personnel_count'] = max(1, absint($input['personnel_count']));
        }
        
        if (isset($input['daily_hours'])) {
            $output['daily_hours'] = max(0.5, min(24, floatval($input['daily_hours'])));
        }
        
        if (isset($input['working_days']) && is_array($input['working_days'])) {
            $output['working_days'] = array_map('sanitize_text_field', $input['working_days']);
        }
        
        if (isset($input['status_durations']) && is_array($input['status_durations'])) {
            $output['status_durations'] = array();
            foreach ($input['status_durations'] as $status => $duration) {
                $output['status_durations'][sanitize_key($status)] = max(0, absint($duration));
            }
        }
        
        if (isset($input['notifications_enabled'])) {
            $output['notifications_enabled'] = $input['notifications_enabled'] ? 1 : 0;
        }
        
        if (isset($input['notification_threshold'])) {
            $output['notification_threshold'] = max(1, absint($input['notification_threshold']));
        }
        
        if (isset($input['notification_email'])) {
            $output['notification_email'] = sanitize_email($input['notification_email']);
        }
        
        if (isset($input['cache_duration'])) {
            // Dakika olarak alıp saniyeye çevir (minimum 1 dakika = 60 saniye)
            $output['cache_duration'] = max(60, absint($input['cache_duration']) * 60);
        }
        
        return $output;
    }
    
    /**
     * Durum süresini al (saniye cinsinden - dahili kullanım için dakikadan dönüştürülür)
     */
    public static function get_status_duration($status) {
        $durations = self::get('status_durations', array());
        $status_key = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
        // Dakika olarak kaydedilen değeri saniyeye çevir
        $minutes = isset($durations[$status_key]) ? absint($durations[$status_key]) : 0;
        return $minutes * 60;
    }
    
    /**
     * Çalışma günlerini al
     */
    public static function get_working_days() {
        return self::get('working_days', array('1', '2', '3', '4', '5'));
    }
    
    /**
     * Günlük kapasiteyi hesapla (saniye)
     */
    public static function get_daily_capacity() {
        $personnel = self::get('personnel_count', 1);
        $hours = self::get('daily_hours', 8);
        return $personnel * $hours * 3600;
    }
}
