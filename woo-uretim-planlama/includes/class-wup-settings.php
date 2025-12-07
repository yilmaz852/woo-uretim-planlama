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
            'status_durations' => array(),    // Temel süre (dakika) - tam kadro ile
            'status_workers' => array(),      // Her durum için işçi sayısı
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
        
        // Her durum için işçi sayısı
        if (isset($input['status_workers']) && is_array($input['status_workers'])) {
            $output['status_workers'] = array();
            foreach ($input['status_workers'] as $status => $workers) {
                $output['status_workers'][sanitize_key($status)] = max(1, absint($workers));
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
     * Durum için işçi sayısını al
     */
    public static function get_status_workers($status) {
        $workers = self::get('status_workers', array());
        $status_key = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
        return isset($workers[$status_key]) ? max(1, absint($workers[$status_key])) : 1;
    }
    
    /**
     * Durum için temel süreyi al (tam kadro ile dakika cinsinden)
     */
    public static function get_status_base_duration($status) {
        $durations = self::get('status_durations', array());
        $status_key = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
        return isset($durations[$status_key]) ? absint($durations[$status_key]) : 0;
    }
    
    /**
     * Durum süresini al (saniye cinsinden - işçi sayısına göre hesaplanır)
     * Formül: Temel süre / mevcut işçi sayısı * standart işçi sayısı
     * Örnek: 3 işçi ile 180dk = 1 işçi ile 540dk (180 / 3 * 1 = 60dk per işçi, tek işçi = 180dk, ters)
     * Doğru formül: 3 işçi ile 180dk demek, 1 işçi 540dk yapar (180 * 3 / 1)
     */
    public static function get_status_duration($status, $custom_workers = null) {
        $base_minutes = self::get_status_base_duration($status);
        
        if ($base_minutes <= 0) {
            return 0;
        }
        
        $configured_workers = self::get_status_workers($status);
        $actual_workers = $custom_workers !== null ? max(1, $custom_workers) : $configured_workers;
        
        // Temel süre tam kadro ile belirlenmiş süre
        // Daha az işçi = daha uzun süre
        // Örnek: 3 işçi ile 180dk → 1 işçi ile: 180 * (3/1) = 540dk
        $adjusted_minutes = $base_minutes * ($configured_workers / $actual_workers);
        
        // Dakikayı saniyeye çevir
        return round($adjusted_minutes * 60);
    }
    
    /**
     * Durum için süre simulasyonu (farklı işçi sayıları ile)
     */
    public static function simulate_duration($status, $worker_count) {
        return self::get_status_duration($status, $worker_count);
    }
    
    /**
     * Çalışma günlerini al
     */
    public static function get_working_days() {
        return self::get('working_days', array('1', '2', '3', '4', '5'));
    }
    
    /**
     * Günlük kapasiteyi hesapla (saniye)
     * Departmanlardan toplam işçi sayısını alır
     */
    public static function get_daily_capacity() {
        // Departmanlardan toplam işçi sayısını al
        $total_workers = 0;
        $departments = WUP_Departments::get_all();
        
        foreach ($departments as $dept) {
            $total_workers += isset($dept['workers']) ? absint($dept['workers']) : 1;
        }
        
        // Minimum 1 işçi
        $total_workers = max(1, $total_workers);
        
        $hours = self::get('daily_hours', 8);
        return $total_workers * $hours * 3600;
    }
    
    /**
     * Toplam işçi sayısını al (tüm departmanların toplamı)
     */
    public static function get_total_workers() {
        $total_workers = 0;
        $departments = WUP_Departments::get_all();
        
        foreach ($departments as $dept) {
            $total_workers += isset($dept['workers']) ? absint($dept['workers']) : 1;
        }
        
        return max(1, $total_workers);
    }
}
