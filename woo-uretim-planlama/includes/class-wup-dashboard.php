<?php
/**
 * Dashboard Widget sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Dashboard {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_dashboard_setup', array($this, 'add_widget'));
    }
    
    /**
     * Widget ekle
     */
    public function add_widget() {
        if (current_user_can('manage_woocommerce')) {
            wp_add_dashboard_widget(
                'wup_summary_widget',
                __('Sipariş Durumu Özeti', 'woo-uretim-planlama'),
                array($this, 'render_widget')
            );
        }
    }
    
    /**
     * Widget içeriği
     */
    public function render_widget() {
        $data = $this->get_widget_data();
        
        echo '<div class="wup-widget">';
        
        // Son 7 günlük özet
        echo '<h4>' . esc_html__('Son 7 Gün - Durum Süreleri', 'woo-uretim-planlama') . '</h4>';
        
        if (empty($data['recent'])) {
            echo '<p>' . esc_html__('Son 7 günde veri bulunamadı.', 'woo-uretim-planlama') . '</p>';
        } else {
            echo '<table class="widefat striped" style="margin-bottom: 15px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Sipariş', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Ort. Süre', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($data['recent'] as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row['status_name']) . '</td>';
                echo '<td>' . number_format_i18n($row['count']) . '</td>';
                echo '<td>' . WUP_UI::format_duration($row['avg_duration']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        // Açık sipariş sayısı
        echo '<p>' . sprintf(
            __('Açık Siparişler: %s', 'woo-uretim-planlama'),
            '<strong>' . number_format_i18n($data['open_orders']) . '</strong>'
        ) . '</p>';
        
        // Rapor linki
        echo '<p style="text-align: right;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=wup-report')) . '">';
        echo esc_html__('Detaylı Rapor &raquo;', 'woo-uretim-planlama');
        echo '</a></p>';
        
        echo '</div>';
    }
    
    /**
     * Widget verisi
     */
    private function get_widget_data() {
        return WUP_Cache::get('dashboard_widget', function() {
            global $wpdb;
            $table = $wpdb->prefix . 'wup_status_history';
            $data = array(
                'recent' => array(),
                'open_orders' => 0
            );
            
            // Son 7 gün verisi
            $seven_days_ago = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
            $status_names = wc_get_order_statuses();
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT h.status, COUNT(DISTINCT h.order_id) as order_count,
                        AVG(TIMESTAMPDIFF(SECOND, h.changed_at, 
                            (SELECT MIN(h2.changed_at) FROM {$table} h2 
                             WHERE h2.order_id = h.order_id AND h2.changed_at > h.changed_at)
                        )) as avg_duration
                 FROM {$table} h
                 WHERE h.changed_at >= %s
                 GROUP BY h.status
                 HAVING avg_duration IS NOT NULL AND avg_duration > 0
                 ORDER BY order_count DESC
                 LIMIT 5",
                $seven_days_ago
            ));
            
            foreach ($results as $row) {
                $status_key = strpos($row->status, 'wc-') === 0 ? $row->status : 'wc-' . $row->status;
                $data['recent'][] = array(
                    'status' => $row->status,
                    'status_name' => isset($status_names[$status_key]) ? $status_names[$status_key] : $row->status,
                    'count' => intval($row->order_count),
                    'avg_duration' => round($row->avg_duration)
                );
            }
            
            // Açık sipariş sayısı
            $final_statuses = array('wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed');
            $data['open_orders'] = intval($wpdb->get_var(
                "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} 
                 WHERE post_type = 'shop_order' 
                 AND post_status NOT IN ('" . implode("','", $final_statuses) . "')"
            ));
            
            return $data;
        }, 900); // 15 dakika cache
    }
}
