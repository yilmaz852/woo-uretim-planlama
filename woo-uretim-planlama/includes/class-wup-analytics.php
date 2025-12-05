<?php
/**
 * Analytics sınıfı - Grafikler ve Analizler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Analytics {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wup_status_history';
    }
    
    /**
     * Trend verisini al (saat cinsinden)
     */
    public function get_trend_data($start_date = null, $end_date = null) {
        $cache_key = 'trend_' . md5($start_date . $end_date);
        
        return WUP_Cache::get($cache_key, function() use ($start_date, $end_date) {
            global $wpdb;
            
            $start = $start_date ?: date('Y-m-d', strtotime('-30 days'));
            $end = $end_date ?: date('Y-m-d');
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(changed_at) as date_val, status,
                        AVG(TIMESTAMPDIFF(SECOND, changed_at, 
                            (SELECT MIN(h2.changed_at) FROM {$this->table_name} h2 
                             WHERE h2.order_id = h.order_id AND h2.changed_at > h.changed_at)
                        )) as avg_duration
                 FROM {$this->table_name} h
                 WHERE DATE(changed_at) BETWEEN %s AND %s
                 GROUP BY date_val, status
                 HAVING avg_duration IS NOT NULL AND avg_duration > 0
                 ORDER BY date_val ASC",
                $start, $end
            ));
            
            $data = array(
                'dates' => array(),
                'statuses' => array(),
                'values' => array()
            );
            
            $status_names = wc_get_order_statuses();
            
            foreach ($results as $row) {
                $date = $row->date_val;
                $status_key = strpos($row->status, 'wc-') === 0 ? $row->status : 'wc-' . $row->status;
                $status_name = isset($status_names[$status_key]) ? $status_names[$status_key] : $row->status;
                
                if (!in_array($date, $data['dates'])) {
                    $data['dates'][] = $date;
                }
                
                if (!in_array($status_name, $data['statuses'])) {
                    $data['statuses'][] = $status_name;
                    $data['values'][$status_name] = array();
                }
                
                // Saniyeyi saate çevir (2 ondalık basamak)
                $hours = round($row->avg_duration / 3600, 2);
                $data['values'][$status_name][$date] = $hours;
            }
            
            // Eksik tarihleri 0 ile doldur
            sort($data['dates']);
            foreach ($data['statuses'] as $status) {
                $filled = array();
                foreach ($data['dates'] as $date) {
                    $filled[$date] = isset($data['values'][$status][$date]) ? $data['values'][$status][$date] : 0;
                }
                $data['values'][$status] = array_values($filled);
            }
            
            return $data;
        }, WUP_Settings::get('cache_duration', 3600));
    }
    
    /**
     * Durum dağılımı
     */
    public function get_status_distribution($start_date = null, $end_date = null) {
        $cache_key = 'distribution_' . md5($start_date . $end_date);
        
        return WUP_Cache::get($cache_key, function() use ($start_date, $end_date) {
            global $wpdb;
            
            $start = $start_date ?: date('Y-m-d', strtotime('-30 days'));
            $end = $end_date ?: date('Y-m-d');
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(DISTINCT order_id) as order_count
                 FROM {$this->table_name}
                 WHERE DATE(changed_at) BETWEEN %s AND %s
                 GROUP BY status
                 ORDER BY order_count DESC",
                $start, $end
            ), ARRAY_A);
            
            $distribution = array();
            $status_names = wc_get_order_statuses();
            
            foreach ($results as $row) {
                $status_key = strpos($row['status'], 'wc-') === 0 ? $row['status'] : 'wc-' . $row['status'];
                $status_name = isset($status_names[$status_key]) ? $status_names[$status_key] : $row['status'];
                $distribution[$status_name] = intval($row['order_count']);
            }
            
            return $distribution;
        }, WUP_Settings::get('cache_duration', 3600));
    }
    
    /**
     * Haftanın günlerine göre analiz (saat cinsinden)
     */
    public function get_weekday_analysis($start_date = null, $end_date = null) {
        $cache_key = 'weekday_' . md5($start_date . $end_date);
        
        return WUP_Cache::get($cache_key, function() use ($start_date, $end_date) {
            global $wpdb;
            
            $start = $start_date ?: date('Y-m-d', strtotime('-90 days'));
            $end = $end_date ?: date('Y-m-d');
            
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT DAYOFWEEK(changed_at) as weekday, status,
                        AVG(TIMESTAMPDIFF(SECOND, changed_at, 
                            (SELECT MIN(h2.changed_at) FROM {$this->table_name} h2 
                             WHERE h2.order_id = h.order_id AND h2.changed_at > h.changed_at)
                        )) as avg_duration
                 FROM {$this->table_name} h
                 WHERE DATE(changed_at) BETWEEN %s AND %s
                 GROUP BY weekday, status
                 HAVING avg_duration IS NOT NULL AND avg_duration > 0
                 ORDER BY weekday ASC",
                $start, $end
            ));
            
            $data = array();
            $status_names = wc_get_order_statuses();
            
            foreach ($results as $row) {
                $status_key = strpos($row->status, 'wc-') === 0 ? $row->status : 'wc-' . $row->status;
                $status_name = isset($status_names[$status_key]) ? $status_names[$status_key] : $row->status;
                
                if (!isset($data[$status_name])) {
                    $data[$status_name] = array_fill(0, 7, 0);
                }
                
                // MySQL DAYOFWEEK: 1=Pazar, 2=Pazartesi, ... 7=Cumartesi
                // PHP'de: 0=Pazar, 1=Pazartesi, ... 6=Cumartesi
                $php_day = ($row->weekday - 1);
                // Saniyeyi saate çevir (2 ondalık basamak)
                $data[$status_name][$php_day] = round($row->avg_duration / 3600, 2);
            }
            
            return $data;
        }, WUP_Settings::get('cache_duration', 3600));
    }
    
    /**
     * Analiz sayfasını render et
     */
    public function render_page() {
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
        
        WUP_UI::page_header(
            __('Gelişmiş Analiz', 'woo-uretim-planlama'),
            __('Sipariş durumları ve süreleri hakkında detaylı analizler.', 'woo-uretim-planlama')
        );
        
        // Filtre formu
        echo '<form method="get" class="wup-filter-form">';
        echo '<input type="hidden" name="page" value="wup-analytics">';
        
        echo '<div class="filter-row">';
        echo '<label for="start_date">' . esc_html__('Başlangıç:', 'woo-uretim-planlama') . '</label>';
        echo '<input type="date" name="start_date" id="start_date" value="' . esc_attr($start_date) . '">';
        
        echo '<label for="end_date">' . esc_html__('Bitiş:', 'woo-uretim-planlama') . '</label>';
        echo '<input type="date" name="end_date" id="end_date" value="' . esc_attr($end_date) . '">';
        
        echo '<button type="submit" class="button">' . esc_html__('Filtrele', 'woo-uretim-planlama') . '</button>';
        echo '<a href="' . esc_url(add_query_arg('refresh', time())) . '" class="button">' . esc_html__('Yenile', 'woo-uretim-planlama') . '</a>';
        echo '</div>';
        echo '</form>';
        
        // Refresh parametresi varsa cache temizle
        if (isset($_GET['refresh'])) {
            WUP_Cache::clear_group('trend');
            WUP_Cache::clear_group('distribution');
            WUP_Cache::clear_group('weekday');
        }
        
        // 1. Trend Grafiği
        $trend_data = $this->get_trend_data($start_date, $end_date);
        if (!empty($trend_data['dates'])) {
            echo '<div class="wup-chart-container">';
            echo '<h2>' . esc_html__('Günlük Ortalama Süre Trendi (Saat)', 'woo-uretim-planlama') . '</h2>';
            echo '<div class="wup-chart-wrapper"><canvas id="trendChart"></canvas></div>';
            echo '</div>';
            
            $chart_data = array(
                'type' => 'line',
                'labels' => $trend_data['dates'],
                'datasets' => array()
            );
            
            $colors = WUP_UI::get_chart_colors(count($trend_data['statuses']));
            $i = 0;
            
            foreach ($trend_data['statuses'] as $status) {
                $chart_data['datasets'][] = array(
                    'label' => $status,
                    'data' => $trend_data['values'][$status],
                    'borderColor' => $colors[$i],
                    'backgroundColor' => $colors[$i] . '33',
                    'fill' => false,
                    'tension' => 0.1
                );
                $i++;
            }
            
            echo '<script>var wupTrendData = ' . wp_json_encode($chart_data) . ';</script>';
        } else {
            WUP_UI::notice(__('Trend verisi bulunamadı.', 'woo-uretim-planlama'), 'warning');
        }
        
        // 2. Dağılım Grafiği
        $distribution = $this->get_status_distribution($start_date, $end_date);
        if (!empty($distribution)) {
            echo '<div class="wup-chart-container">';
            echo '<h2>' . esc_html__('Durum Dağılımı', 'woo-uretim-planlama') . '</h2>';
            echo '<div class="wup-chart-wrapper"><canvas id="distributionChart"></canvas></div>';
            echo '</div>';
            
            $chart_data = array(
                'type' => 'pie',
                'labels' => array_keys($distribution),
                'data' => array_values($distribution),
                'colors' => WUP_UI::get_chart_colors(count($distribution))
            );
            
            echo '<script>var wupDistributionData = ' . wp_json_encode($chart_data) . ';</script>';
        }
        
        // 3. Haftalık Analiz
        $weekday_data = $this->get_weekday_analysis($start_date, $end_date);
        if (!empty($weekday_data)) {
            echo '<div class="wup-chart-container">';
            echo '<h2>' . esc_html__('Haftanın Günlerine Göre Ortalama Süre (Saat)', 'woo-uretim-planlama') . '</h2>';
            echo '<div class="wup-chart-wrapper"><canvas id="weekdayChart"></canvas></div>';
            echo '</div>';
            
            $day_names = array(
                __('Pazar', 'woo-uretim-planlama'),
                __('Pazartesi', 'woo-uretim-planlama'),
                __('Salı', 'woo-uretim-planlama'),
                __('Çarşamba', 'woo-uretim-planlama'),
                __('Perşembe', 'woo-uretim-planlama'),
                __('Cuma', 'woo-uretim-planlama'),
                __('Cumartesi', 'woo-uretim-planlama')
            );
            
            $chart_data = array(
                'type' => 'bar',
                'labels' => $day_names,
                'datasets' => array()
            );
            
            $colors = WUP_UI::get_chart_colors(count($weekday_data));
            $i = 0;
            
            foreach ($weekday_data as $status => $values) {
                $chart_data['datasets'][] = array(
                    'label' => $status,
                    'data' => $values,
                    'backgroundColor' => $colors[$i]
                );
                $i++;
            }
            
            echo '<script>var wupWeekdayData = ' . wp_json_encode($chart_data) . ';</script>';
        }
        
        WUP_UI::page_footer();
    }
}
