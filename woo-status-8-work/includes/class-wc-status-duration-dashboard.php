<?php
/**
 * Dashboard widget ve bildirimler için sınıf
 * Not: Bildirim kısmı ana sınıfa taşındı. Burası sadece widget için.
 */

if (!defined('ABSPATH')) exit;

class WC_Status_Duration_Dashboard {
    private $table_name;
    private $cache;

    public function __construct($table_name, WC_Status_Duration_Cache $cache) {
        $this->table_name = $table_name;
        $this->cache = $cache;
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }

    /**
     * WordPress Dashboard'a widget ekler.
     */
    public function add_dashboard_widget() {
        if (current_user_can('manage_woocommerce')) {
            wp_add_dashboard_widget(
                'wc_status_duration_summary_widget',          // Widget slug
                __('Order Status Summary', 'wc-prod-duration'), // Title
                [$this, 'render_dashboard_widget']          // Display function
            );
        }
    }

    /**
     * Dashboard widget içeriğini oluşturur ve ekrana basar.
     */
    public function render_dashboard_widget() {
        $widget_data = $this->get_widget_data();

        echo '<div class="wc-prod-duration-widget">';

        // Son 7 Gün Özeti
        echo '<h4>' . esc_html__('Last 7 Days - Status Durations', 'wc-prod-duration') . '</h4>';
        if (empty($widget_data['last_7_days'])) {
            echo '<p>' . esc_html__('No data available for the last 7 days.', 'wc-prod-duration') . '</p>';
        } else {
            echo '<table class="widefat striped" style="margin-bottom: 15px;"><thead><tr>';
            echo '<th>' . esc_html__('Status', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Orders', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Avg. Duration', 'wc-prod-duration') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($widget_data['last_7_days'] as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row['status_name']) . '</td>';
                echo '<td>' . number_format_i18n($row['count']) . '</td>';
                echo '<td>' . $this->format_duration($row['avg_duration']) . '</td>';
                echo '</tr>';
            }
             echo '</tbody></table>';
        }

        // Açık Sipariş Sayısı
        echo '<p>' . sprintf(
            __('Currently Open Orders: %s', 'wc-prod-duration'),
            '<strong>' . number_format_i18n($widget_data['open_orders_count']) . '</strong>'
        ) . '</p>';

        // Detaylı Rapor Linki
        echo '<p style="text-align: right; margin-top: 10px;"><a href="' . esc_url(admin_url('admin.php?page=wc-production-report')) . '">' . esc_html__('View Full Report &raquo;', 'wc-prod-duration') . '</a></p>';

        echo '</div>'; // .wc-prod-duration-widget sonu
    }

    /**
     * Widget için gerekli veriyi önbellekten alır veya hesaplar.
     * @return array Widget verisi.
     */
    private function get_widget_data() {
        $cache_key = 'dashboard_widget_data';
        // Kısa süreli önbellek (örn. 15 dakika)
        $expiration = 15 * MINUTE_IN_SECONDS;

        return $this->cache->get_cached_data(
            $cache_key,
            function() {
                global $wpdb;
                $data = [
                    'last_7_days' => [],
                    'open_orders_count' => 0,
                ];
                $seven_days_ago_gmt = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
                $status_names = wc_get_order_statuses();

                // Son 7 gün verisi (Performans için optimize edilebilir)
                 $sql_last_7_days = $wpdb->prepare(
                    "SELECT
                        a.status,
                        COUNT(DISTINCT a.order_id) as order_count,
                        AVG(TIMESTAMPDIFF(SECOND, a.changed_at, (
                            SELECT MIN(b.changed_at)
                            FROM {$this->table_name} b
                            WHERE b.order_id = a.order_id AND b.changed_at > a.changed_at
                        ))) as avg_duration_seconds
                    FROM
                        {$this->table_name} a
                    WHERE
                        a.changed_at >= %s
                        AND EXISTS (
                             SELECT 1 FROM {$this->table_name} c
                             WHERE c.order_id = a.order_id AND c.changed_at > a.changed_at
                         )
                    GROUP BY
                        a.status
                    HAVING avg_duration_seconds IS NOT NULL AND avg_duration_seconds > 0
                    ORDER BY
                        order_count DESC
                    LIMIT 5", // En çok geçiş olan ilk 5 durumu göster
                    $seven_days_ago_gmt
                );
                $results_7_days = $wpdb->get_results($sql_last_7_days);

                foreach ($results_7_days as $row) {
                    $data['last_7_days'][] = [
                        'status' => $row->status,
                        'status_name' => $status_names[$row->status] ?? $row->status,
                        'count' => (int)$row->order_count,
                        'avg_duration' => round($row->avg_duration_seconds),
                    ];
                }

                // Açık sipariş sayısı
                $final_statuses_sql = "'" . implode("','", array_map('esc_sql', $this->get_final_statuses())) . "'";
                $data['open_orders_count'] = (int) $wpdb->get_var(
                     "SELECT COUNT(DISTINCT p.ID)
                      FROM {$wpdb->posts} p
                      WHERE p.post_type = 'shop_order'
                      AND p.post_status NOT IN ({$final_statuses_sql})"
                );


                return $data;
            },
            [], // Callback args
            $expiration // Cache süresi
            // Cache grubu belirtmeye gerek yok, ana grup kullanılır
        );
    }

     /**
      * Üretimin bittiği kabul edilen durumları döndürür.
      * @return array
      */
     private function get_final_statuses() {
         // Bu fonksiyon Scheduler sınıfında da var, tek bir yerden yönetmek daha iyi olabilir.
         return ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];
     }


    /**
     * Süreyi HH:MM:SS formatına çevirir.
     */
    private function format_duration($seconds) {
         if (!is_numeric($seconds) || $seconds < 0) return '00:00:00';
        $H = floor($seconds / 3600);
        $M = floor(($seconds % 3600) / 60);
        $S = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $H, $M, $S);
    }

} // Class WC_Status_Duration_Dashboard sonu
