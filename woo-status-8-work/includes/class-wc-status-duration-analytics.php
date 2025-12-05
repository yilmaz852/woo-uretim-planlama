<?php
/**
 * Gelişmiş analiz ve grafikler için sınıf
 */
if (!defined('ABSPATH')) exit;

class WC_Status_Duration_Analytics {

    private $table_name;
    private $settings_helper;
    private $cache;

    public function __construct($table_name, WC_Prod_Settings_Helper $settings_helper, WC_Status_Duration_Cache $cache) {
        $this->table_name = $table_name;
        $this->settings_helper = $settings_helper;
        $this->cache = $cache;
    }

    /**
     * Durum bazında günlük ortalama süre trend verisini getirir.
     * Düzeltildi: GMT kullanımı ve yerel tarih dönüşümü.
     */
    public function get_trend_data($start_date = null, $end_date = null) {
        $cache_key = 'analytics_trend_' . md5(($start_date ?? 'all') . '_' . ($end_date ?? 'all'));
        $force_refresh = isset($_GET['refresh_analysis']);

        return $this->cache->get_cached_data(
            $cache_key,
            function($start, $end) {
                global $wpdb;
                $start = $start ?: date('Y-m-d', strtotime('-30 days'));
                $end = $end ?: date('Y-m-d');
                $start_gmt = get_gmt_from_date($start . ' 00:00:00');
                $end_gmt = get_gmt_from_date($end . ' 23:59:59');

                // Sorgu: Her durum geçişinin süresini hesapla ve tarihe/duruma göre grupla
                $sql = $wpdb->prepare(
                    "SELECT DATE(a.changed_at) as date_gmt, a.status,
                            AVG(TIMESTAMPDIFF(SECOND, a.changed_at, next_event.changed_at)) as avg_duration_seconds
                     FROM {$this->table_name} a
                     INNER JOIN (
                         SELECT order_id, changed_at,
                                (SELECT MIN(changed_at) FROM {$this->table_name} WHERE order_id = T.order_id AND changed_at > T.changed_at) as next_change_at
                         FROM {$this->table_name} T
                     ) AS next_event ON a.order_id = next_event.order_id AND a.changed_at = next_event.changed_at
                     WHERE a.changed_at BETWEEN %s AND %s
                       AND next_event.next_change_at IS NOT NULL -- Sadece süresi hesaplanabilenler
                     GROUP BY date_gmt, a.status
                     HAVING avg_duration_seconds > 0 -- Geçerli süreler
                     ORDER BY date_gmt ASC",
                    $start_gmt, $end_gmt
                );
                $results = $wpdb->get_results($sql);

                $trend_data = []; $dates = []; $statuses = []; $status_names = wc_get_order_statuses();
                foreach ($results as $row) {
                    // Tarihi GMT'den WP yerel saatine çevir
                    $local_date_obj = new DateTime($row->date_gmt, new DateTimeZone('GMT'));
                    $local_date_obj->setTimezone(wp_timezone()); // WordPress saat dilimi
                    $local_date_str = $local_date_obj->format('Y-m-d');

                    $status_name = $status_names[$row->status] ?? $row->status;
                    if (!isset($trend_data[$status_name])) { $trend_data[$status_name] = []; $statuses[] = $status_name; }
                    $trend_data[$status_name][$local_date_str] = round($row->avg_duration_seconds);
                    if (!in_array($local_date_str, $dates)) $dates[] = $local_date_str;
                }
                if (!empty($dates)) {
                    sort($dates); // Tarihleri sırala
                    // Eksik tarihleri 0 ile doldur
                    foreach ($statuses as $status) {
                        $temp_data = []; foreach ($dates as $date) $temp_data[$date] = $trend_data[$status][$date] ?? 0;
                        $trend_data[$status] = $temp_data;
                    }
                }
                return ['data' => $trend_data, 'dates' => $dates, 'statuses' => $statuses];
            },
            [$start_date, $end_date], $this->settings_helper->get_setting('cache_time', 3600), $force_refresh, 'analytics'
        );
    }

    /**
     * Durum geçiş dağılımını alır.
     * Düzeltildi: GMT kullanımı.
     */
     public function get_status_distribution($start_date = null, $end_date = null) {
         $cache_key = 'analytics_dist_' . md5(($start_date ?? 'all') . '_' . ($end_date ?? 'all'));
         $force_refresh = isset($_GET['refresh_analysis']);
         return $this->cache->get_cached_data($cache_key, function($start, $end) {
                 global $wpdb; $start = $start ?: date('Y-m-d', strtotime('-30 days')); $end = $end ?: date('Y-m-d');
                 $start_gmt = get_gmt_from_date($start . ' 00:00:00'); $end_gmt = get_gmt_from_date($end . ' 23:59:59');
                 // Sorgu: Belirtilen aralıkta her durumda kaç farklı siparişin bulunduğunu say
                 $sql = $wpdb->prepare(
                     "SELECT status, COUNT(DISTINCT order_id) as order_count
                      FROM {$this->table_name} WHERE changed_at BETWEEN %s AND %s
                      GROUP BY status", $start_gmt, $end_gmt);
                 $results = $wpdb->get_results($sql, ARRAY_A);
                 $distribution = []; $status_names = wc_get_order_statuses();
                 foreach ($results as $row) { $status_name = $status_names[$row['status']] ?? $row['status']; $distribution[$status_name] = (int)$row['order_count']; }
                 arsort($distribution); return $distribution;
             }, [$start_date, $end_date], $this->settings_helper->get_setting('cache_time', 3600), $force_refresh, 'analytics');
     }

    /**
     * Haftanın günlerine göre ortalama durum sürelerini alır.
     * Düzeltildi: GMT kullanımı ve gün indeksi.
     */
     public function get_weekday_avg_duration($start_date = null, $end_date = null) {
         $cache_key = 'analytics_weekday_' . md5(($start_date ?? 'all') . '_' . ($end_date ?? 'all'));
         $force_refresh = isset($_GET['refresh_analysis']);
         return $this->cache->get_cached_data($cache_key, function($start, $end) {
                 global $wpdb; $start = $start ?: date('Y-m-d', strtotime('-90 days')); $end = $end ?: date('Y-m-d');
                 $start_gmt = get_gmt_from_date($start . ' 00:00:00'); $end_gmt = get_gmt_from_date($end . ' 23:59:59');
                 // Sorgu: Haftanın gününe göre ortalama süre
                 $sql = $wpdb->prepare(
                     "SELECT DAYOFWEEK(a.changed_at) as mysql_weekday, a.status,
                             AVG(TIMESTAMPDIFF(SECOND, a.changed_at, next_event.changed_at)) as avg_duration_seconds
                      FROM {$this->table_name} a
                      INNER JOIN (SELECT order_id, changed_at, (SELECT MIN(changed_at) FROM {$this->table_name} WHERE order_id = T.order_id AND changed_at > T.changed_at) as next_change_at FROM {$this->table_name} T) AS next_event ON a.order_id = next_event.order_id AND a.changed_at = next_event.changed_at
                      WHERE a.changed_at BETWEEN %s AND %s AND next_event.next_change_at IS NOT NULL
                      GROUP BY mysql_weekday, a.status HAVING avg_duration_seconds > 0
                      ORDER BY mysql_weekday ASC, a.status ASC", $start_gmt, $end_gmt);
                 $results = $wpdb->get_results($sql);
                 $weekday_data = []; $status_names = wc_get_order_statuses();
                 foreach ($results as $row) {
                      $status_name = $status_names[$row->status] ?? $row->status;
                      if (!isset($weekday_data[$status_name])) $weekday_data[$status_name] = array_fill(0, 7, 0);
                      $php_weekday_index = ($row->mysql_weekday - 1 + 7) % 7; // 0=Pazar, 1=Pzt,... 6=Cmt
                      $weekday_data[$status_name][$php_weekday_index] = round($row->avg_duration_seconds);
                 } return $weekday_data;
             }, [$start_date, $end_date], $this->settings_helper->get_setting('cache_time', 3600), $force_refresh, 'analytics');
     }

    /**
     * Gelişmiş analiz grafiklerini render eder.
     * Önceki kodla aynı, sadece veri çekme fonksiyonları güncellendi.
     */
    public function render_advanced_charts() {
        // Tarih filtresi (önceki kodla aynı)
        $start_date = isset($_GET['chart_start_date']) ? sanitize_text_field($_GET['chart_start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['chart_end_date']) ? sanitize_text_field($_GET['chart_end_date']) : date('Y-m-d');
        echo '<div class="analysis-filters" style="margin-bottom: 20px;"><form method="get" action=""><input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
        echo '<label for="chart_start_date">' . esc_html__('Start Date:', 'wc-prod-duration') . '</label><input type="date" id="chart_start_date" name="chart_start_date" value="' . esc_attr($start_date) . '" style="max-width: 150px;"> ';
        echo '<label for="chart_end_date">' . esc_html__('End Date:', 'wc-prod-duration') . '</label><input type="date" id="chart_end_date" name="chart_end_date" value="' . esc_attr($end_date) . '" style="max-width: 150px;"> ';
        echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'wc-prod-duration') . '">';
        echo '<a href="' . esc_url(add_query_arg(['refresh_analysis' => time()], remove_query_arg('refresh_analysis'))) . '" class="button" style="margin-left: 10px;">' . esc_html__('Refresh Data', 'wc-prod-duration') . '</a>';
        echo '</form></div>';

        // 1. Trend Grafiği
        $trend_data = $this->get_trend_data($start_date, $end_date); // Güncellenmiş fonksiyonu çağır
        if (!empty($trend_data['data'])) {
            echo '<div class="analytics-container chart-container"><h2>' . esc_html__('Daily Average Duration Trend per Status', 'wc-prod-duration') . '</h2><canvas id="trendChart"></canvas></div>';
            $trend_chart_js_data = ['type' => 'line', 'element_id' => 'trendChart', 'labels' => $trend_data['dates'], 'datasets' => [], 'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Average Duration (seconds)', 'wc-prod-duration')]], 'x' => ['title' => ['display' => true, 'text' => __('Date', 'wc-prod-duration')]]], 'plugins' => ['legend' => ['position' => 'top'], 'tooltip' => ['callbacks' => ['label' => 'js:wc_prod_duration_format_tooltip_label']]]]];
            $colors = WC_Status_Duration_UI::get_chart_colors(count($trend_data['statuses'])); $color_index = 0;
            foreach ($trend_data['data'] as $status_name => $date_values) { $trend_chart_js_data['datasets'][] = ['label' => $status_name, 'data' => array_values($date_values), 'fill' => false, 'borderColor' => $colors[$color_index % count($colors)], 'backgroundColor' => $colors[$color_index % count($colors)], 'tension' => 0.1]; $color_index++; }
            wp_add_inline_script('wc-prod-duration-admin-script', 'const wc_prod_trend_chart_data = ' . wp_json_encode($trend_chart_js_data) . '; wc_prod_duration_render_chart(wc_prod_trend_chart_data);', 'after');
        } else { WC_Status_Duration_UI::show_notice(__('No trend data found.', 'wc-prod-duration'), 'warning'); }

        // 2. Durum Dağılım Grafiği
        $distribution_data = $this->get_status_distribution($start_date, $end_date); // Güncellenmiş fonksiyonu çağır
        if (!empty($distribution_data)) {
            echo '<div class="analytics-container chart-container"><h2>' . esc_html__('Order Distribution by Status', 'wc-prod-duration') . '</h2><canvas id="distributionChart"></canvas></div>';
            $dist_labels = array_keys($distribution_data); $dist_values = array_values($distribution_data); $dist_colors = WC_Status_Duration_UI::get_chart_colors(count($dist_labels));
            $dist_chart_js_data = ['type' => 'pie', 'element_id' => 'distributionChart', 'labels' => $dist_labels, 'datasets' => [['data' => $dist_values, 'backgroundColor' => $dist_colors]], 'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'top'], 'title' => ['display' => true, 'text' => __('Order Distribution by Status', 'wc-prod-duration')]]]];
            wp_add_inline_script('wc-prod-duration-admin-script', 'const wc_prod_dist_chart_data = ' . wp_json_encode($dist_chart_js_data) . '; wc_prod_duration_render_chart(wc_prod_dist_chart_data);', 'after');
        } else { WC_Status_Duration_UI::show_notice(__('No distribution data found.', 'wc-prod-duration'), 'warning'); }

        // 3. Haftanın Günü Grafiği
        $weekday_data = $this->get_weekday_avg_duration($start_date, $end_date); // Güncellenmiş fonksiyonu çağır
        if (!empty($weekday_data)) {
            echo '<div class="analytics-container chart-container"><h2>' . esc_html__('Average Duration by Day of the Week', 'wc-prod-duration') . '</h2><canvas id="weekdayChart"></canvas></div>';
            $start_of_week = (int) get_option('start_of_week', 1); $wp_weekdays = []; $raw_weekdays = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
            for ($i = 0; $i < 7; $i++) { $day_index = ($start_of_week + $i) % 7; $wp_weekdays[$day_index] = $raw_weekdays[$day_index]; }
            $weekday_chart_js_data = ['type' => 'bar', 'element_id' => 'weekdayChart', 'labels' => array_values($wp_weekdays), 'datasets' => [], 'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Average Duration (seconds)', 'wc-prod-duration')]], 'x' => ['title' => ['display' => true, 'text' => __('Day of Week', 'wc-prod-duration')]]], 'plugins' => ['legend' => ['position' => 'top'], 'tooltip' => ['callbacks' => ['label' => 'js:wc_prod_duration_format_tooltip_label']]]]];
            $colors = WC_Status_Duration_UI::get_chart_colors(count($weekday_data)); $color_index = 0;
            foreach ($weekday_data as $status_name => $day_values) {
                 $ordered_day_values = []; foreach (array_keys($wp_weekdays) as $day_index) $ordered_day_values[] = $day_values[$day_index] ?? 0;
                 $weekday_chart_js_data['datasets'][] = ['label' => $status_name, 'data' => $ordered_day_values, 'backgroundColor' => $colors[$color_index % count($colors)]]; $color_index++;
            }
            wp_add_inline_script('wc-prod-duration-admin-script', 'const wc_prod_weekday_chart_data = ' . wp_json_encode($weekday_chart_js_data) . '; wc_prod_duration_render_chart(wc_prod_weekday_chart_data);', 'after');
        } else { WC_Status_Duration_UI::show_notice(__('No weekday analysis data found.', 'wc-prod-duration'), 'warning'); }
    }

} // Class WC_Status_Duration_Analytics sonu
