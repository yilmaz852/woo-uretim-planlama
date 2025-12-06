<?php
/**
 * Üretim Planlama sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Scheduler {
    
    private static $instance = null;
    private $table_name;
    
    // Final durumlar - üretimi tamamlanmış
    private $final_statuses = array('wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed');
    
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
     * Final durumları al
     */
    public function get_final_statuses() {
        return $this->final_statuses;
    }
    
    /**
     * Planlanabilir durumları al
     */
    public function get_schedulable_statuses() {
        $all_statuses = array_keys(wc_get_order_statuses());
        return array_diff($all_statuses, $this->final_statuses);
    }
    
    /**
     * Üretim programını hesapla
     */
    public function get_schedule() {
        return WUP_Cache::get('schedule_data', function() {
            $orders = wc_get_orders(array(
                'status' => $this->get_schedulable_statuses(),
                'limit' => -1,
                'orderby' => 'date',
                'order' => 'ASC'
            ));
            
            $schedule = array(
                'orders' => array(),
                'total_seconds' => 0,
                'average_seconds' => 0
            );
            
            $current_time = time();
            $daily_capacity = WUP_Settings::get_daily_capacity();
            $working_days = WUP_Settings::get_working_days();
            
            if ($daily_capacity <= 0) {
                return $schedule;
            }
            
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }
                
                $remaining = $this->calculate_remaining_time($order);
                
                if ($remaining['seconds'] <= 0) {
                    continue;
                }
                
                $schedule['total_seconds'] += $remaining['seconds'];
                
                // Tahmini tamamlanma tarihi
                $completion_date = $this->calculate_completion_date(
                    $current_time,
                    $remaining['seconds'],
                    $daily_capacity,
                    $working_days
                );
                
                // Gerçek geçmiş ortalama
                $actual_avg = $this->get_actual_average($order->get_id());
                
                $schedule['orders'][] = array(
                    'order' => $order,
                    'order_id' => $order->get_id(),
                    'customer' => $order->get_formatted_billing_full_name(),
                    'status' => $order->get_status(),
                    'remaining_seconds' => $remaining['seconds'],
                    'remaining_formatted' => WUP_UI::format_business_hours($remaining['seconds']),
                    'next_statuses' => $remaining['next_statuses'],
                    'completion_date' => $completion_date,
                    'completion_formatted' => $completion_date ? WUP_UI::format_date($completion_date) : '-',
                    'actual_avg_seconds' => $actual_avg,
                    'actual_avg_formatted' => $actual_avg > 0 ? WUP_UI::format_duration($actual_avg) : '-'
                );
                
                $current_time = $completion_date ?: $current_time;
            }
            
            $count = count($schedule['orders']);
            $schedule['average_seconds'] = $count > 0 ? round($schedule['total_seconds'] / $count) : 0;
            
            return $schedule;
        }, WUP_Settings::get('cache_duration', 3600));
    }
    
    /**
     * Sipariş için kalan süreyi hesapla
     */
    private function calculate_remaining_time($order) {
        $current_status = 'wc-' . $order->get_status();
        $all_statuses = array_keys(wc_get_order_statuses());
        $current_index = array_search($current_status, $all_statuses);
        
        $result = array(
            'seconds' => 0,
            'next_statuses' => array()
        );
        
        if ($current_index === false || in_array($current_status, $this->final_statuses)) {
            return $result;
        }
        
        // Mevcut durum süresini ekle
        $current_duration = WUP_Settings::get_status_duration($current_status);
        $result['seconds'] += $current_duration;
        
        // Sonraki durumların sürelerini ekle
        for ($i = $current_index + 1; $i < count($all_statuses); $i++) {
            $status = $all_statuses[$i];
            
            if (in_array($status, $this->final_statuses)) {
                break;
            }
            
            $duration = WUP_Settings::get_status_duration($status);
            
            if ($duration > 0) {
                $result['seconds'] += $duration;
                $status_name = wc_get_order_status_name(str_replace('wc-', '', $status));
                $result['next_statuses'][] = $status_name;
            }
        }
        
        return $result;
    }
    
    /**
     * Tahmini tamamlanma tarihi
     */
    private function calculate_completion_date($start_time, $required_seconds, $daily_capacity, $working_days) {
        if ($required_seconds <= 0 || $daily_capacity <= 0 || empty($working_days)) {
            return $start_time;
        }
        
        $current = $start_time;
        $remaining = $required_seconds;
        $limit = 365; // Maksimum 1 yıl
        
        while ($remaining > 0 && $limit > 0) {
            $day_of_week = date('w', $current);
            
            if (in_array((string)$day_of_week, $working_days)) {
                $processed = min($remaining, $daily_capacity);
                $remaining -= $processed;
                
                if ($remaining <= 0) {
                    return $current;
                }
            }
            
            $current = strtotime('+1 day', strtotime(date('Y-m-d', $current)));
            $limit--;
        }
        
        return $limit <= 0 ? null : $current;
    }
    
    /**
     * Siparişin gerçek geçmiş ortalaması
     */
    private function get_actual_average($order_id) {
        global $wpdb;
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT changed_at FROM {$this->table_name} 
             WHERE order_id = %d 
             ORDER BY changed_at ASC",
            $order_id
        ));
        
        if (count($history) < 2) {
            return 0;
        }
        
        $total = 0;
        $count = 0;
        
        for ($i = 0; $i < count($history) - 1; $i++) {
            $start = strtotime($history[$i]->changed_at);
            $end = strtotime($history[$i + 1]->changed_at);
            $diff = $end - $start;
            
            if ($diff > 0) {
                $total += $diff;
                $count++;
            }
        }
        
        return $count > 0 ? round($total / $count) : 0;
    }
    
    /**
     * Program sayfasını render et
     */
    public function render_page() {
        WUP_UI::page_header(
            __('Üretim Programı', 'woo-uretim-planlama'),
            __('Açık siparişler için tahmini üretim süreleri ve tamamlanma tarihleri.', 'woo-uretim-planlama')
        );
        
        // Departman/İşçi özeti
        $this->render_department_summary();
        
        $schedule = $this->get_schedule();
        
        if (empty($schedule['orders'])) {
            WUP_UI::notice(__('Planlanacak açık sipariş bulunamadı.', 'woo-uretim-planlama'), 'info');
        } else {
            echo '<h2>' . esc_html__('Planlanan Siparişler', 'woo-uretim-planlama') . '</h2>';
            
            echo '<table class="widefat fixed striped wup-schedule-table">';
            echo '<thead><tr>';
            echo '<th style="width:80px;">' . esc_html__('Sipariş', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Müşteri', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('İşçi', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Geçmiş Ort.', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Kalan Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Tahmini Bitiş', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Sonraki Adımlar', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($schedule['orders'] as $item) {
                $order_url = admin_url('post.php?post=' . $item['order_id'] . '&action=edit');
                $status_name = wc_get_order_status_name($item['status']);
                $workers = WUP_Settings::get_status_workers('wc-' . $item['status']);
                
                echo '<tr>';
                echo '<td><a href="' . esc_url($order_url) . '">#' . esc_html($item['order_id']) . '</a></td>';
                echo '<td>' . esc_html($item['customer']) . '</td>';
                echo '<td>' . esc_html($status_name) . '</td>';
                echo '<td>' . esc_html($workers) . ' ' . esc_html__('kişi', 'woo-uretim-planlama') . '</td>';
                echo '<td>' . esc_html($item['actual_avg_formatted']) . '</td>';
                echo '<td>' . esc_html($item['remaining_formatted']) . '</td>';
                echo '<td>' . esc_html($item['completion_formatted']) . '</td>';
                echo '<td style="font-size:0.9em;">' . esc_html(implode(' → ', $item['next_statuses'])) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            
            // Özet bilgiler
            echo '<h2>' . esc_html__('Özet', 'woo-uretim-planlama') . '</h2>';
            echo '<ul class="wup-summary">';
            echo '<li>' . sprintf(
                __('Toplam Sipariş: %d', 'woo-uretim-planlama'),
                count($schedule['orders'])
            ) . '</li>';
            echo '<li>' . sprintf(
                __('Toplam İş Yükü: %s', 'woo-uretim-planlama'),
                WUP_UI::format_business_hours($schedule['total_seconds'])
            ) . '</li>';
            echo '<li>' . sprintf(
                __('Sipariş Başına Ortalama: %s', 'woo-uretim-planlama'),
                WUP_UI::format_business_hours($schedule['average_seconds'])
            ) . '</li>';
            echo '</ul>';
        }
        
        WUP_UI::page_footer();
    }
    
    /**
     * Departman/İşçi özeti
     */
    private function render_department_summary() {
        $statuses = wc_get_order_statuses();
        $settings = WUP_Settings::get_all();
        
        // Sadece ayarlanmış durumları göster
        $configured_statuses = array();
        foreach ($statuses as $key => $label) {
            $duration = isset($settings['status_durations'][$key]) ? $settings['status_durations'][$key] : 0;
            $workers = isset($settings['status_workers'][$key]) ? $settings['status_workers'][$key] : 1;
            
            if ($duration > 0) {
                $configured_statuses[$key] = array(
                    'label' => $label,
                    'duration' => $duration,
                    'workers' => $workers
                );
            }
        }
        
        if (empty($configured_statuses)) {
            return;
        }
        
        echo '<h2>' . esc_html__('Departman Kapasitesi', 'woo-uretim-planlama') . '</h2>';
        echo '<table class="widefat fixed striped" style="max-width:700px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('İşçi Sayısı', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('İşlem Süresi', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Tek İşçi Süresi', 'woo-uretim-planlama') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        $total_workers = 0;
        
        foreach ($configured_statuses as $key => $data) {
            $total_workers += $data['workers'];
            $single_worker_hours = round(($data['duration'] * $data['workers']) / 60, 1);
            $configured_hours = round($data['duration'] / 60, 1);
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($data['label']) . '</strong></td>';
            echo '<td>' . esc_html($data['workers']) . ' ' . esc_html__('kişi', 'woo-uretim-planlama') . '</td>';
            echo '<td>' . esc_html($configured_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '<td style="color:#666;">' . esc_html($single_worker_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '<tfoot><tr>';
        echo '<th>' . esc_html__('Toplam', 'woo-uretim-planlama') . '</th>';
        echo '<th colspan="3">' . esc_html($total_workers) . ' ' . esc_html__('işçi', 'woo-uretim-planlama') . '</th>';
        echo '</tr></tfoot>';
        echo '</table>';
        echo '<br>';
    }
    
    /**
     * Sipariş meta box
     */
    public function render_order_meta_box($post) {
        $order = wc_get_order($post->ID);
        
        if (!$order) {
            return;
        }
        
        $status = 'wc-' . $order->get_status();
        $duration = WUP_Settings::get_status_duration($status);
        $actual_avg = $this->get_actual_average($order->get_id());
        $remaining = $this->calculate_remaining_time($order);
        
        echo '<p><strong>' . esc_html__('Mevcut Durum:', 'woo-uretim-planlama') . '</strong> ';
        echo esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
        
        echo '<p><strong>' . esc_html__('Tahmini Süre (Bu Durum):', 'woo-uretim-planlama') . '</strong> ';
        echo $duration > 0 ? WUP_UI::format_duration($duration) : esc_html__('Ayarlanmamış', 'woo-uretim-planlama');
        echo '</p>';
        
        if ($actual_avg > 0) {
            echo '<p><strong>' . esc_html__('Gerçek Ortalama:', 'woo-uretim-planlama') . '</strong> ';
            echo WUP_UI::format_duration($actual_avg) . '</p>';
        }
        
        if ($remaining['seconds'] > 0) {
            echo '<p><strong>' . esc_html__('Tahmini Kalan:', 'woo-uretim-planlama') . '</strong> ';
            echo WUP_UI::format_business_hours($remaining['seconds']) . '</p>';
        }
    }
}
