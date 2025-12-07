<?php
/**
 * UI yardımcı sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_UI {
    
    /**
     * Bildirim göster
     */
    public static function notice($message, $type = 'info', $dismissible = true) {
        $class = 'notice notice-' . sanitize_key($type);
        if ($dismissible) {
            $class .= ' is-dismissible';
        }
        printf('<div class="%s"><p>%s</p></div>', esc_attr($class), wp_kses_post($message));
    }
    
    /**
     * Durum dropdown'ı
     */
    public static function status_dropdown($name, $selected = '', $show_all = true) {
        $statuses = wc_get_order_statuses();
        
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        
        if ($show_all) {
            echo '<option value="">' . esc_html__('Tüm Durumlar', 'woo-uretim-planlama') . '</option>';
        }
        
        foreach ($statuses as $key => $label) {
            $value = str_replace('wc-', '', $key);
            $is_selected = ($selected === $value || $selected === $key) ? 'selected' : '';
            echo '<option value="' . esc_attr($value) . '" ' . $is_selected . '>' . esc_html($label) . '</option>';
        }
        
        echo '</select>';
    }
    
    /**
     * Süreyi formatla (HH:MM:SS)
     */
    public static function format_duration($seconds) {
        if (!is_numeric($seconds) || $seconds < 0) {
            return '00:00:00';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
    
    /**
     * İş saati formatla
     */
    public static function format_business_hours($seconds) {
        if (!is_numeric($seconds) || $seconds <= 0) {
            return __('0 saat', 'woo-uretim-planlama');
        }
        
        $daily_hours = WUP_Settings::get('daily_hours', 8);
        $seconds_per_day = $daily_hours * 3600;
        
        $days = floor($seconds / $seconds_per_day);
        $remaining = $seconds % $seconds_per_day;
        $hours = floor($remaining / 3600);
        $minutes = floor(($remaining % 3600) / 60);
        
        $parts = array();
        
        if ($days > 0) {
            $parts[] = sprintf(_n('%d gün', '%d gün', $days, 'woo-uretim-planlama'), $days);
        }
        
        if ($hours > 0) {
            $parts[] = sprintf(_n('%d saat', '%d saat', $hours, 'woo-uretim-planlama'), $hours);
        }
        
        if ($days === 0 && $hours === 0 && $minutes > 0) {
            $parts[] = sprintf(__('%d dk', 'woo-uretim-planlama'), $minutes);
        }
        
        return empty($parts) ? __('< 1 dk', 'woo-uretim-planlama') : implode(' ', $parts);
    }
    
    /**
     * Grafik renkleri
     */
    public static function get_chart_colors($count = 10) {
        $colors = array(
            '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
            '#858796', '#6f42c1', '#fd7e14', '#20c997', '#5a5c69',
            '#17a2b8', '#28a745', '#dc3545', '#ffc107', '#007bff'
        );
        
        $result = array();
        for ($i = 0; $i < $count; $i++) {
            $result[] = $colors[$i % count($colors)];
        }
        
        return $result;
    }
    
    /**
     * Tarih formatla
     */
    public static function format_date($timestamp, $format = null) {
        if (!$format) {
            $format = get_option('date_format') . ' ' . get_option('time_format');
        }
        return date_i18n($format, $timestamp);
    }
    
    /**
     * Sayfa başlığı
     */
    public static function page_header($title, $subtitle = '') {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';
        if ($subtitle) {
            echo '<p class="description">' . esc_html($subtitle) . '</p>';
        }
    }
    
    /**
     * Sayfa sonu
     */
    public static function page_footer() {
        echo '</div>';
    }
}
