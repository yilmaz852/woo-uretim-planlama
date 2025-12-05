<?php
/**
 * Üretim Programlama Modülü
 */

if (!defined('ABSPATH')) exit;

class WC_Production_Scheduler {

    private $table_name;
    private $settings_helper;
    private $cache;

    public function __construct($table_name, WC_Prod_Settings_Helper $settings_helper, WC_Status_Duration_Cache $cache) {
        $this->table_name = $table_name;
        $this->settings_helper = $settings_helper;
        $this->cache = $cache;
         // Meta kutusu kaydetme hook'u (eğer öncelik vb. eklenecekse)
        // add_action('save_post_shop_order', [$this, 'save_production_meta'], 10, 1);
    }

    /**
     * Üretim Programı admin sayfasını render eder.
     * YENİ: "Gerçek Ort. Süre (Geçmiş)" sütunu eklendi.
     */
    public function render_schedule_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Production Schedule', 'wc-prod-duration') . '</h1>';
        echo '<p>' . esc_html__('Estimated production times and sequence for open orders.', 'wc-prod-duration') . '</p>';

        $schedule_data = $this->get_schedule_data();

        if (empty($schedule_data['orders'])) {
            WC_Status_Duration_UI::show_notice(__('No open orders found to schedule.', 'wc-prod-duration'), 'info');
        } else {
            echo '<h2>' . esc_html__('Scheduled Orders', 'wc-prod-duration') . '</h2>';
            echo '<table class="widefat fixed striped wc-production-schedule-table">';
            echo '<thead><tr>';
            echo '<th style="width: 80px;">' . esc_html__('Order ID', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Customer', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Current Status', 'wc-prod-duration') . '</th>';
            echo '<th title="' . esc_attr__('Average actual time spent in previous statuses for this order', 'wc-prod-duration') . '">' . esc_html__('Actual Avg. Duration (Past)', 'wc-prod-duration') . '</th>'; // YENİ SÜTUN
            echo '<th>' . esc_html__('Est. Remaining Time (Work Hrs)', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Est. Completion Date', 'wc-prod-duration') . '</th>';
            echo '<th>' . esc_html__('Next Steps (Est.)', 'wc-prod-duration') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($schedule_data['orders'] as $order_item) {
                $order = $order_item['order'];
                if (!$order instanceof WC_Order) continue;
                $order_link = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                $completion_date_str = $order_item['estimated_completion_date'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $order_item['estimated_completion_date']) : __('N/A', 'wc-prod-duration');
                // Yeni sütun için veri
                $actual_avg_str = $order_item['actual_past_avg_seconds'] > 0 ? $this->format_duration($order_item['actual_past_avg_seconds']) : '-';

                echo '<tr>';
                echo '<td><a href="' . esc_url($order_link) . '">#' . esc_html($order->get_id()) . '</a></td>';
                echo '<td>' . esc_html($order->get_formatted_billing_full_name()) . '</td>';
                echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
                echo '<td>' . esc_html($actual_avg_str) . '</td>'; // YENİ SÜTUN
                echo '<td>' . $this->format_business_hours($order_item['estimated_remaining_seconds']) . '</td>';
                echo '<td>' . esc_html($completion_date_str) . '</td>';
                echo '<td style="font-size: 0.9em;">' . esc_html(implode(' → ', $order_item['next_statuses'] ?? [])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

             // Genel İstatistikler (öncekiyle aynı)
             echo '<h2>' . esc_html__('Overview', 'wc-prod-duration') . '</h2><ul>';
             echo '<li>' . sprintf(__('Total Open Orders: %d', 'wc-prod-duration'), count($schedule_data['orders'])) . '</li>';
             echo '<li>' . sprintf(__('Total Estimated Workload: %s', 'wc-prod-duration'), $this->format_business_hours($schedule_data['total_estimated_seconds'])) . '</li>';
             echo '<li>' . sprintf(__('Average Remaining Time per Order: %s', 'wc-prod-duration'), $this->format_business_hours($schedule_data['average_remaining_seconds'])) . '</li>';
             echo '</ul>';
        }
        echo '</div>';
    }

    /**
     * Sipariş düzenleme sayfasındaki üretim bilgileri meta kutusu.
     * Önceki kodla aynı.
     */
    public function render_order_production_meta_box($post) {
         $order_id = $post->ID; $order = wc_get_order($order_id); if (!$order) return;
         $current_status = $order->get_status(); $status_key = 'wc-' . $current_status;
         $status_durations = $this->settings_helper->get_setting('status_durations', []);
         $estimated_time_for_current = $status_durations[$status_key] ?? null;

         echo '<p><strong>' . esc_html__('Current Status:', 'wc-prod-duration') . '</strong> ' . esc_html(wc_get_order_status_name($current_status)) . '</p>';
         if ($estimated_time_for_current !== null && $estimated_time_for_current > 0) { echo '<p><strong>' . esc_html__('Est. Time for Current Status:', 'wc-prod-duration') . '</strong> ' . $this->format_duration($estimated_time_for_current) . '</p>'; }
         else { echo '<p><strong>' . esc_html__('Est. Time for Current Status:', 'wc-prod-duration') . '</strong> ' . esc_html__('Not set', 'wc-prod-duration') . '</p>'; }
         $remaining_info = $this->calculate_remaining_time_for_order($order); // Tahmini kalan
         if ($remaining_info['total_seconds'] > 0) { echo '<p><strong>' . esc_html__('Est. Total Remaining Time:', 'wc-prod-duration') . '</strong> ' . $this->format_duration($remaining_info['total_seconds']) . '</p><p><small>(' . esc_html__('Based on status settings', 'wc-prod-duration') . ')</small></p>'; }
         elseif (!in_array($status_key, $this->get_final_statuses())) { echo '<p><strong>' . esc_html__('Est. Total Remaining Time:', 'wc-prod-duration') . '</strong> ' . esc_html__('Calculation requires status durations.', 'wc-prod-duration') . '</p>'; }
         // Gerçek geçmiş ortalamayı da ekleyebiliriz
         $actual_avg_info = $this->get_actual_past_average_duration($order_id);
         if($actual_avg_info['total_seconds'] > 0) {
              echo '<p><strong>' . esc_html__('Actual Avg. Duration (Past):', 'wc-prod-duration') . '</strong> ' . $this->format_duration($actual_avg_info['average_seconds']) . ' (' . sprintf(_n('%d status', '%d statuses', $actual_avg_info['status_count'], 'wc-prod-duration'), $actual_avg_info['status_count']) .')</p>';
         }
    }

    /**
     * Üretim programı verisini önbellekten alır veya hesaplar.
     */
    public function get_schedule_data() {
        // Önbellekleme (önceki kodla aynı)
         $cache_key = 'production_schedule';
         return $this->cache->get_cached_data($cache_key, [$this, 'calculate_schedule'], [], $this->settings_helper->get_setting('cache_time', 3600), false, 'schedule');
    }

    /**
     * Üretim programını hesaplar.
     * YENİ: Her sipariş için 'actual_past_avg_seconds' ekler.
     */
    public function calculate_schedule() {
        $settings = $this->settings_helper->get_settings();
        $personnel_count = max(1, (int)$settings['personnel_count']);
        $daily_hours = max(0.1, (float)$settings['daily_hours']);
        $working_days = $settings['working_days'];

        $args = ['status' => $this->get_schedulable_statuses(), 'limit' => -1, 'orderby' => 'date', 'order' => 'ASC'];
        $open_orders = wc_get_orders($args);

        $scheduled_orders = [];
        $current_available_timestamp = time();
        $total_estimated_seconds = 0;
        $total_daily_capacity_seconds = $personnel_count * $daily_hours * 3600;
        if ($total_daily_capacity_seconds <= 0) return ['orders' => [], 'total_estimated_seconds' => 0, 'average_remaining_seconds' => 0];

        foreach ($open_orders as $order) {
            if (!$order instanceof WC_Order) continue;

            // 1. Tahmini kalan süreyi hesapla (Ayarlara göre)
            $remaining_info = $this->calculate_remaining_time_for_order($order);
            $remaining_seconds = $remaining_info['total_seconds'];
            if ($remaining_seconds <= 0) continue;
            $total_estimated_seconds += $remaining_seconds;

            // 2. Gerçek geçmiş ortalama süreyi hesapla (Bu sipariş için) - YENİ
            $actual_avg_info = $this->get_actual_past_average_duration($order->get_id());

            // 3. Tahmini tamamlanma tarihini hesapla
            $estimated_completion_date = $this->calculate_estimated_completion_date($current_available_timestamp, $remaining_seconds, $total_daily_capacity_seconds, $working_days);

            $scheduled_orders[] = [
                'order' => $order,
                'estimated_remaining_seconds' => $remaining_seconds,
                'estimated_completion_date' => $estimated_completion_date,
                'next_statuses' => $remaining_info['next_statuses'],
                'actual_past_avg_seconds' => $actual_avg_info['average_seconds'], // YENİ VERİ
                'actual_past_status_count' => $actual_avg_info['status_count'] // YENİ VERİ
            ];
            $current_available_timestamp = $estimated_completion_date ?: $current_available_timestamp;
        }

        $order_count = count($scheduled_orders);
        $average_remaining_seconds = $order_count > 0 ? round($total_estimated_seconds / $order_count) : 0;

        return ['orders' => $scheduled_orders, 'total_estimated_seconds' => $total_estimated_seconds, 'average_remaining_seconds' => $average_remaining_seconds];
    }

     /**
      * Belirli bir siparişin mevcut durumuna kadar olan geçmişteki
      * durumlarında geçirdiği gerçek ortalama süreyi hesaplar.
      * @param int $order_id
      * @return array ['total_seconds', 'status_count', 'average_seconds']
      */
     private function get_actual_past_average_duration($order_id) {
         $cache_key = 'order_actual_avg_' . $order_id;
         return $this->cache->get_cached_data($cache_key, function($id) {
             global $wpdb;
             $history = $wpdb->get_results($wpdb->prepare(
                 "SELECT status, changed_at FROM {$this->table_name} WHERE order_id = %d ORDER BY changed_at ASC",
                 $id
             ));

             if (count($history) < 2) { // Süre hesaplamak için en az 2 kayıt lazım
                 return ['total_seconds' => 0, 'status_count' => 0, 'average_seconds' => 0];
             }

             $total_duration = 0;
             $status_count = 0;
              // Mevcut (son) durumu dahil etme
             for ($i = 0; $i < count($history) - 1; $i++) {
                 $start_time = strtotime($history[$i]->changed_at . ' GMT');
                 $end_time = strtotime($history[$i+1]->changed_at . ' GMT');
                 $duration = $end_time - $start_time;
                 if ($duration > 0) {
                     $total_duration += $duration;
                     $status_count++;
                 }
             }

             $average = ($status_count > 0) ? round($total_duration / $status_count) : 0;

             return [
                 'total_seconds' => $total_duration,
                 'status_count' => $status_count,
                 'average_seconds' => $average
             ];
         }, [$order_id], 1 * HOUR_IN_SECONDS); // 1 saat önbellek
     }


    /**
     * Belirli bir sipariş için kalan tahmini süreyi hesaplar (Ayarlara göre).
     * Önceki kodla aynı.
     */
    private function calculate_remaining_time_for_order(WC_Order $order) {
        // Önceki kodla aynı mantık
         $status_durations = $this->settings_helper->get_setting('status_durations', []);
         $current_status = 'wc-' . $order->get_status();
         $total_remaining_seconds = 0; $next_statuses_in_flow = [];
         $final_statuses = $this->get_final_statuses();
         if (in_array($current_status, $final_statuses)) return ['total_seconds' => 0, 'next_statuses' => []];

         $all_statuses_ordered = array_keys(wc_get_order_statuses());
         $current_index = array_search($current_status, $all_statuses_ordered);

         if ($current_index !== false) {
             // Mevcut durumun süresini ekle (tamamı)
             if (isset($status_durations[$current_status]) && $status_durations[$current_status] > 0) {
                 $total_remaining_seconds += (int)$status_durations[$current_status];
             }
             // Sonraki durumların sürelerini ekle
             for ($i = $current_index + 1; $i < count($all_statuses_ordered); $i++) {
                 $status_key = $all_statuses_ordered[$i];
                 if (in_array($status_key, $final_statuses)) break;
                 if (isset($status_durations[$status_key]) && $status_durations[$status_key] > 0) {
                     $total_remaining_seconds += (int)$status_durations[$status_key];
                     $next_statuses_in_flow[] = wc_get_order_status_name(str_replace('wc-','',$status_key));
                 }
             }
         } else { // Mevcut durum bulunamazsa (teorik)
             $total_remaining_seconds = (int)($status_durations[$current_status] ?? 0);
         }
         return ['total_seconds' => $total_remaining_seconds, 'next_statuses' => $next_statuses_in_flow];
    }

    /**
     * Tahmini tamamlanma tarihini hesaplar (Timestamp).
     * Önceki kodla aynı, sadece bitiş timestamp'i döndürüyor.
     */
    private function calculate_estimated_completion_date($start_timestamp, $required_seconds, $daily_capacity_seconds, $working_days) {
        // Önceki kodla aynı mantık
         if ($required_seconds <= 0 || $daily_capacity_seconds <= 0 || empty($working_days)) return $start_timestamp;
         $current_timestamp = $start_timestamp; $seconds_left = $required_seconds; $safety_limit = 1000;
         while ($seconds_left > 0 && $safety_limit > 0) {
             $day_of_week = date('w', $current_timestamp);
             if (in_array((string)$day_of_week, $working_days)) {
                 $seconds_processed_today = min($seconds_left, $daily_capacity_seconds);
                 $seconds_left -= $seconds_processed_today;
                 if ($seconds_left <= 0) {
                     // İşin bittiği günün timestamp'ini döndür (başlangıç)
                     return $current_timestamp;
                 }
             }
             $current_timestamp = strtotime('+1 day', strtotime(date('Y-m-d', $current_timestamp)));
             $safety_limit--;
         }
         return ($safety_limit <= 0) ? null : $current_timestamp;
    }

    public function get_final_statuses() { return ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed']; }
    public function get_schedulable_statuses() { return array_diff(array_keys(wc_get_order_statuses()), $this->get_final_statuses()); }
    private function format_duration($seconds) { if (!is_numeric($seconds) || $seconds < 0) return '00:00:00'; $H = floor($seconds / 3600); $M = floor(($seconds % 3600) / 60); $S = $seconds % 60; return sprintf('%02d:%02d:%02d', $H, $M, $S); }
    private function format_business_hours($total_seconds) { if (!is_numeric($total_seconds) || $total_seconds <= 0) return __('0 hours', 'wc-prod-duration'); $daily_hours = max(0.1, (float)$this->settings_helper->get_setting('daily_hours', 8)); $seconds_in_business_day = $daily_hours * 3600; if ($seconds_in_business_day <= 0) return $this->format_duration($total_seconds); $days = floor($total_seconds / $seconds_in_business_day); $remaining_seconds = $total_seconds % $seconds_in_business_day; $hours = floor($remaining_seconds / 3600); $minutes = floor(($remaining_seconds % 3600) / 60); $parts = []; if ($days > 0) $parts[] = sprintf(_n('%d day', '%d days', $days, 'wc-prod-duration'), $days); if ($hours > 0) $parts[] = sprintf(_n('%d hr', '%d hrs', $hours, 'wc-prod-duration'), $hours); if ($days == 0 && $hours == 0 && $minutes > 0) $parts[] = sprintf(__('%d min', 'wc-prod-duration'), $minutes); if (empty($parts)) return __('< 1 min', 'wc-prod-duration'); return implode(' ', $parts); }

    /**
     * API Endpoint Callback: Üretim programı verisi.
     * Önceki kodla aynı.
     */
     public function get_schedule_data_api($request) {
         $schedule_data = $this->get_schedule_data(); $formatted_orders = [];
         foreach($schedule_data['orders'] as $item) {
             $order = $item['order']; if (!$order instanceof WC_Order) continue;
             $formatted_orders[] = [
                 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'status' => $order->get_status(), 'status_name' => wc_get_order_status_name($order->get_status()),
                 'order_date' => $order->get_date_created() ? $order->get_date_created()->date('c') : null, 'customer_name' => $order->get_formatted_billing_full_name(),
                 'estimated_remaining_seconds' => $item['estimated_remaining_seconds'], 'estimated_remaining_formatted' => $this->format_business_hours($item['estimated_remaining_seconds']),
                 'estimated_completion_timestamp' => $item['estimated_completion_date'], 'estimated_completion_date_iso' => $item['estimated_completion_date'] ? gmdate('c', $item['estimated_completion_date']) : null,
                 'actual_past_avg_seconds' => $item['actual_past_avg_seconds'], // Yeni eklendi
                 'actual_past_avg_formatted' => $this->format_duration($item['actual_past_avg_seconds']), // Yeni eklendi
                 'next_statuses' => $item['next_statuses'] ?? []
             ];
         }
         return new WP_REST_Response(['success' => true, 'orders' => $formatted_orders, 'summary' => ['total_orders' => count($formatted_orders), 'total_estimated_seconds' => $schedule_data['total_estimated_seconds'], 'total_estimated_formatted' => $this->format_business_hours($schedule_data['total_estimated_seconds']), 'average_remaining_seconds' => $schedule_data['average_remaining_seconds'], 'average_remaining_formatted' => $this->format_business_hours($schedule_data['average_remaining_seconds'])]], 200);
     }

} // Class WC_Production_Scheduler sonu
