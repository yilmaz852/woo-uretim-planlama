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
                
                // Mevcut departmanı bul
                $current_dept = WUP_Departments::get_department_by_status('wc-' . $order->get_status());
                
                // Cabinet tiplerini al
                $cabinet_types = WUP_Product_Routes::get_order_types($order);
                $cabinet_type_names = array();
                foreach ($cabinet_types as $type_id) {
                    $route = WUP_Product_Routes::get($type_id);
                    if ($route) {
                        $cabinet_type_names[] = $route['name'];
                    }
                }
                
                $schedule['orders'][] = array(
                    'order' => $order,
                    'order_id' => $order->get_id(),
                    'customer' => $order->get_formatted_billing_full_name(),
                    'status' => $order->get_status(),
                    'department' => $current_dept ? $current_dept['name'] : '-',
                    'department_color' => $current_dept ? $current_dept['color'] : '#ccc',
                    'department_workers' => $current_dept ? $current_dept['workers'] : 0,
                    'cabinet_types' => $cabinet_types,
                    'cabinet_type_names' => $cabinet_type_names,
                    'remaining_seconds' => $remaining['seconds'],
                    'remaining_formatted' => WUP_UI::format_business_hours($remaining['seconds']),
                    'next_statuses' => $remaining['next_statuses'],
                    'next_departments' => isset($remaining['departments']) ? $remaining['departments'] : array(),
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
     * Sipariş için kalan süreyi hesapla (ürün rotası ve departman bazlı)
     */
    private function calculate_remaining_time($order) {
        $current_status = 'wc-' . $order->get_status();
        
        $result = array(
            'seconds' => 0,
            'next_statuses' => array(),
            'departments' => array(),
            'cabinet_types' => array()
        );
        
        if (in_array($current_status, $this->final_statuses)) {
            return $result;
        }
        
        // Önce ürün rotalarına bak (cabinet tiplerine göre)
        $route_calculation = WUP_Product_Routes::calculate_order_duration($order);
        
        if (!empty($route_calculation['types'])) {
            // Ürün rotası varsa onu kullan
            $result['seconds'] = $route_calculation['seconds'];
            $result['departments'] = $route_calculation['departments'];
            $result['cabinet_types'] = $route_calculation['types'];
            
            // Departman isimlerini next_statuses'a ekle (görsellik için)
            foreach ($route_calculation['departments'] as $dept_id) {
                $dept = WUP_Departments::get($dept_id);
                if ($dept) {
                    $result['next_statuses'][] = $dept['name'];
                }
            }
            
            return $result;
        }
        
        // Ürün rotası yoksa eski sistemi kullan (durum bazlı)
        $all_statuses = array_keys(wc_get_order_statuses());
        $current_index = array_search($current_status, $all_statuses);
        
        if ($current_index === false) {
            return $result;
        }
        
        // Mevcut durum için departman kontrolü
        $current_dept = WUP_Departments::get_department_by_status($current_status);
        if ($current_dept) {
            $duration = WUP_Departments::get_duration($current_dept['id']);
            $result['seconds'] += $duration;
            $result['departments'][] = $current_dept['name'];
        } else {
            // Departman yoksa eski sistemden al
            $current_duration = WUP_Settings::get_status_duration($current_status);
            $result['seconds'] += $current_duration;
        }
        
        // Sonraki durumların sürelerini ekle
        for ($i = $current_index + 1; $i < count($all_statuses); $i++) {
            $status = $all_statuses[$i];
            
            if (in_array($status, $this->final_statuses)) {
                break;
            }
            
            // Departman kontrolü
            $dept = WUP_Departments::get_department_by_status($status);
            if ($dept) {
                $duration = WUP_Departments::get_duration($dept['id']);
                if ($duration > 0) {
                    $result['seconds'] += $duration;
                    $result['next_statuses'][] = wc_get_order_status_name(str_replace('wc-', '', $status));
                    if (!in_array($dept['name'], $result['departments'])) {
                        $result['departments'][] = $dept['name'];
                    }
                }
            } else {
                // Departman yoksa eski sistemden al
                $duration = WUP_Settings::get_status_duration($status);
                if ($duration > 0) {
                    $result['seconds'] += $duration;
                    $status_name = wc_get_order_status_name(str_replace('wc-', '', $status));
                    $result['next_statuses'][] = $status_name;
                }
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
            echo '<th style="width:70px;">' . esc_html__('Sipariş', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Müşteri', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Cabinet Tipi', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Geçmiş Ort.', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Kalan Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Tahmini Bitiş', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Akış', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($schedule['orders'] as $item) {
                $order_url = admin_url('post.php?post=' . $item['order_id'] . '&action=edit');
                $status_name = wc_get_order_status_name($item['status']);
                
                echo '<tr>';
                echo '<td><a href="' . esc_url($order_url) . '">#' . esc_html($item['order_id']) . '</a></td>';
                echo '<td>' . esc_html($item['customer']) . '</td>';
                
                // Cabinet tipi
                echo '<td>';
                if (!empty($item['cabinet_type_names'])) {
                    foreach ($item['cabinet_types'] as $type_id) {
                        $route = WUP_Product_Routes::get($type_id);
                        if ($route) {
                            echo '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:' . esc_attr($route['color']) . ';color:#fff;font-size:0.8em;margin:1px;">' . esc_html($route['name']) . '</span> ';
                        }
                    }
                } else {
                    echo '<span style="color:#999;">-</span>';
                }
                echo '</td>';
                
                echo '<td>' . esc_html($status_name) . '</td>';
                echo '<td>';
                if ($item['department'] !== '-') {
                    echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr($item['department_color']) . ';margin-right:5px;"></span>';
                }
                echo esc_html($item['department']);
                echo '</td>';
                echo '<td>' . esc_html($item['actual_avg_formatted']) . '</td>';
                echo '<td>' . esc_html($item['remaining_formatted']) . '</td>';
                echo '<td>' . esc_html($item['completion_formatted']) . '</td>';
                echo '<td style="font-size:0.8em;">' . esc_html(implode(' → ', $item['next_statuses'])) . '</td>';
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
     * Departman/İşçi özeti (yeni departman sisteminden)
     */
    private function render_department_summary() {
        $departments = WUP_Departments::get_all();
        
        // Sadece yapılandırılmış departmanları göster
        $configured_depts = array();
        foreach ($departments as $dept) {
            if ($dept['base_duration'] > 0) {
                $configured_depts[] = $dept;
            }
        }
        
        if (empty($configured_depts)) {
            echo '<p class="description">' . esc_html__('Departman ayarları için', 'woo-uretim-planlama') . ' <a href="' . esc_url(admin_url('admin.php?page=wup-departments')) . '">' . esc_html__('Departmanlar', 'woo-uretim-planlama') . '</a> ' . esc_html__('sayfasını ziyaret edin.', 'woo-uretim-planlama') . '</p>';
            return;
        }
        
        echo '<h2>' . esc_html__('Departman Kapasitesi', 'woo-uretim-planlama') . '</h2>';
        echo '<p class="description">' . esc_html__('Departman detayları için', 'woo-uretim-planlama') . ' <a href="' . esc_url(admin_url('admin.php?page=wup-departments')) . '">' . esc_html__('Departmanlar', 'woo-uretim-planlama') . '</a> ' . esc_html__('sayfasını kullanın.', 'woo-uretim-planlama') . '</p>';
        
        echo '<table class="widefat fixed striped" style="max-width:800px; margin-top:15px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('İşçi Sayısı', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('İşlem Süresi', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Tek İşçi Süresi', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Bağlı Durumlar', 'woo-uretim-planlama') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        $total_workers = 0;
        $status_names = wc_get_order_statuses();
        
        foreach ($configured_depts as $dept) {
            $total_workers += $dept['workers'];
            $single_worker_hours = round(WUP_Departments::get_single_worker_duration($dept['id']) / 60, 1);
            $configured_hours = round($dept['base_duration'] / 60, 1);
            
            // Bağlı durumları listele
            $linked_statuses = array();
            foreach ($dept['statuses'] as $status_key) {
                if (isset($status_names[$status_key])) {
                    $linked_statuses[] = $status_names[$status_key];
                }
            }
            
            echo '<tr>';
            echo '<td>';
            echo '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . esc_attr($dept['color']) . ';margin-right:8px;"></span>';
            echo '<strong>' . esc_html($dept['name']) . '</strong>';
            echo '</td>';
            echo '<td>' . esc_html($dept['workers']) . ' ' . esc_html__('kişi', 'woo-uretim-planlama') . '</td>';
            echo '<td>' . esc_html($configured_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '<td style="color:#666;">' . esc_html($single_worker_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '<td style="font-size:0.85em;">' . (empty($linked_statuses) ? '-' : esc_html(implode(', ', $linked_statuses))) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '<tfoot><tr>';
        echo '<th>' . esc_html__('Toplam', 'woo-uretim-planlama') . '</th>';
        echo '<th colspan="4">' . esc_html($total_workers) . ' ' . esc_html__('işçi', 'woo-uretim-planlama') . '</th>';
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
