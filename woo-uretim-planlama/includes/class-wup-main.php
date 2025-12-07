<?php
/**
 * Ana eklenti sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Main {
    
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
        
        // Alt modülleri başlat
        WUP_Dashboard::get_instance();
        WUP_Calendar::get_instance();
        
        // Hooklar
        $this->init_hooks();
    }
    
    /**
     * Hookları başlat
     */
    private function init_hooks() {
        // Admin menüsü
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Admin stilleri ve scriptleri
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Sipariş durumu değişikliği
        add_action('woocommerce_order_status_changed', array($this, 'log_status_change'), 10, 4);
        
        // Sipariş meta box
        add_action('add_meta_boxes_shop_order', array($this, 'add_meta_boxes'));
        
        // Ayarlar kaydetme
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX işlemleri
        add_action('wp_ajax_wup_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_wup_clear_old_data', array($this, 'ajax_clear_old_data'));
        
        // CSV Export
        add_action('admin_init', array($this, 'handle_csv_export'));
        
        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Admin menüsü
     */
    public function add_admin_menu() {
        $main_slug = 'wup-report';
        
        add_menu_page(
            __('Üretim Planlama', 'woo-uretim-planlama'),
            __('Üretim Planlama', 'woo-uretim-planlama'),
            'manage_woocommerce',
            $main_slug,
            array($this, 'render_report_page'),
            'dashicons-chart-line',
            56
        );
        
        add_submenu_page(
            $main_slug,
            __('Durum Raporu', 'woo-uretim-planlama'),
            __('Durum Raporu', 'woo-uretim-planlama'),
            'manage_woocommerce',
            $main_slug,
            array($this, 'render_report_page')
        );
        
        add_submenu_page(
            $main_slug,
            __('Üretim Programı', 'woo-uretim-planlama'),
            __('Program', 'woo-uretim-planlama'),
            'manage_woocommerce',
            'wup-schedule',
            array(WUP_Scheduler::get_instance(), 'render_page')
        );
        
        add_submenu_page(
            $main_slug,
            __('Takvim', 'woo-uretim-planlama'),
            __('Takvim', 'woo-uretim-planlama'),
            'manage_woocommerce',
            'wup-calendar',
            array(WUP_Calendar::get_instance(), 'render_page')
        );
        
        add_submenu_page(
            $main_slug,
            __('Analiz', 'woo-uretim-planlama'),
            __('Analiz', 'woo-uretim-planlama'),
            'manage_woocommerce',
            'wup-analytics',
            array(WUP_Analytics::get_instance(), 'render_page')
        );
        
        add_submenu_page(
            $main_slug,
            __('Ayarlar', 'woo-uretim-planlama'),
            __('Ayarlar', 'woo-uretim-planlama'),
            'manage_options',
            'wup-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Script ve stilleri yükle
     */
    public function enqueue_scripts($hook) {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        $plugin_pages = array(
            'toplevel_page_wup-report',
            'uretim-planlama_page_wup-schedule',
            'uretim-planlama_page_wup-calendar',
            'uretim-planlama_page_wup-analytics',
            'uretim-planlama_page_wup-settings',
            'shop_order'
        );
        
        if (!in_array($screen->id, $plugin_pages)) {
            return;
        }
        
        // CSS
        wp_enqueue_style('wup-admin', WUP_PLUGIN_URL . 'assets/css/admin.css', array(), WUP_VERSION);
        
        // Chart.js (rapor ve analiz sayfaları için)
        if (in_array($screen->id, array('toplevel_page_wup-report', 'uretim-planlama_page_wup-analytics'))) {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true);
        }
        
        // FullCalendar (takvim sayfası için)
        if ($screen->id === 'uretim-planlama_page_wup-calendar') {
            wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js', array(), '6.1.11', true);
        }
        
        // Ana JS
        wp_enqueue_script('wup-admin', WUP_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WUP_VERSION, true);
        
        wp_localize_script('wup-admin', 'wupData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wup_nonce'),
            'texts' => array(
                'processing' => __('İşleniyor...', 'woo-uretim-planlama'),
                'success' => __('Başarılı!', 'woo-uretim-planlama'),
                'error' => __('Hata oluştu', 'woo-uretim-planlama'),
                'confirmDelete' => __('Bu işlem geri alınamaz. Devam etmek istiyor musunuz?', 'woo-uretim-planlama')
            )
        ));
    }
    
    /**
     * Sipariş durumu değişikliğini kaydet
     */
    public function log_status_change($order_id, $old_status, $new_status, $order) {
        global $wpdb;
        
        // Aynı duruma geçişi loglama
        if ($old_status === $new_status) {
            return;
        }
        
        // Son 5 saniye içinde aynı değişiklik var mı?
        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
             WHERE order_id = %d AND status = %s 
             AND changed_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
             LIMIT 1",
            $order_id, $new_status
        ));
        
        if ($recent) {
            return;
        }
        
        $wpdb->insert($this->table_name, array(
            'order_id' => $order_id,
            'status' => $new_status,
            'changed_at' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'note' => sprintf(__('Durumdan değişti: %s', 'woo-uretim-planlama'), $old_status)
        ));
        
        // Cache temizle
        WUP_Cache::clear_group('schedule');
        WUP_Cache::clear_group('dashboard');
        
        // Bildirim kontrolü
        if (WUP_Settings::get('notifications_enabled')) {
            $this->check_notification($order_id, $old_status, $new_status);
        }
    }
    
    /**
     * Bildirim kontrolü
     */
    private function check_notification($order_id, $old_status, $new_status) {
        global $wpdb;
        
        $last_change = $wpdb->get_var($wpdb->prepare(
            "SELECT changed_at FROM {$this->table_name} 
             WHERE order_id = %d AND status = %s 
             ORDER BY changed_at DESC LIMIT 1",
            $order_id, $old_status
        ));
        
        if (!$last_change) {
            return;
        }
        
        $duration = time() - strtotime($last_change);
        $threshold = WUP_Settings::get('notification_threshold', 24) * 3600;
        
        if ($duration > $threshold) {
            $this->send_notification($order_id, $old_status, $duration);
        }
    }
    
    /**
     * Bildirim gönder
     */
    private function send_notification($order_id, $status, $duration) {
        $email = WUP_Settings::get('notification_email');
        
        if (!is_email($email)) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] Sipariş #%d uzun süredir %s durumunda', 'woo-uretim-planlama'),
            get_bloginfo('name'),
            $order_id,
            wc_get_order_status_name($status)
        );
        
        $order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
        
        $message = sprintf(
            __("Sipariş #%d, %s durumunda %s süre kaldı.\n\nSipariş: %s", 'woo-uretim-planlama'),
            $order_id,
            wc_get_order_status_name($status),
            WUP_UI::format_duration($duration),
            $order_url
        );
        
        wp_mail($email, $subject, $message);
    }
    
    /**
     * Sipariş meta box
     */
    public function add_meta_boxes() {
        add_meta_box(
            'wup_order_history',
            __('Durum Geçmişi', 'woo-uretim-planlama'),
            array($this, 'render_history_meta_box'),
            'shop_order',
            'normal',
            'default'
        );
        
        add_meta_box(
            'wup_production_info',
            __('Üretim Bilgisi', 'woo-uretim-planlama'),
            array(WUP_Scheduler::get_instance(), 'render_order_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }
    
    /**
     * Geçmiş meta box içeriği
     */
    public function render_history_meta_box($post) {
        global $wpdb;
        
        $order_id = $post->ID;
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE order_id = %d 
             ORDER BY changed_at ASC",
            $order_id
        ));
        
        if (empty($history)) {
            echo '<p>' . esc_html__('Durum geçmişi bulunamadı.', 'woo-uretim-planlama') . '</p>';
            return;
        }
        
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Tarih', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Süre', 'woo-uretim-planlama') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        $status_names = wc_get_order_statuses();
        
        for ($i = 0; $i < count($history); $i++) {
            $row = $history[$i];
            $status_key = strpos($row->status, 'wc-') === 0 ? $row->status : 'wc-' . $row->status;
            $status_name = isset($status_names[$status_key]) ? $status_names[$status_key] : $row->status;
            
            $local_time = get_date_from_gmt($row->changed_at);
            
            // Süre hesapla
            $duration = '-';
            if (isset($history[$i + 1])) {
                $diff = strtotime($history[$i + 1]->changed_at) - strtotime($row->changed_at);
                if ($diff > 0) {
                    $duration = WUP_UI::format_duration($diff);
                }
            } else {
                $diff = time() - strtotime($row->changed_at);
                if ($diff > 0) {
                    $duration = WUP_UI::format_duration($diff) . ' (' . __('devam ediyor', 'woo-uretim-planlama') . ')';
                }
            }
            
            echo '<tr>';
            echo '<td>' . esc_html($status_name) . '</td>';
            echo '<td>' . esc_html(WUP_UI::format_date(strtotime($local_time))) . '</td>';
            echo '<td>' . esc_html($duration) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Ayarları kaydet
     */
    public function register_settings() {
        register_setting('wup_settings_group', 'wup_settings', array(
            'sanitize_callback' => array('WUP_Settings', 'validate')
        ));
    }
    
    /**
     * Rapor sayfası
     */
    public function render_report_page() {
        global $wpdb;
        
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        WUP_UI::page_header(
            __('Sipariş Durum Raporu', 'woo-uretim-planlama'),
            __('Siparişlerin her durumda geçirdiği sürelerin analizi.', 'woo-uretim-planlama')
        );
        
        // Filtre formu
        echo '<form method="get" class="wup-filter-form">';
        echo '<input type="hidden" name="page" value="wup-report">';
        
        echo '<div class="filter-row">';
        echo '<label for="start_date">' . esc_html__('Başlangıç:', 'woo-uretim-planlama') . '</label>';
        echo '<input type="date" name="start_date" id="start_date" value="' . esc_attr($start_date) . '">';
        
        echo '<label for="end_date">' . esc_html__('Bitiş:', 'woo-uretim-planlama') . '</label>';
        echo '<input type="date" name="end_date" id="end_date" value="' . esc_attr($end_date) . '">';
        
        echo '<label for="status">' . esc_html__('Durum:', 'woo-uretim-planlama') . '</label>';
        WUP_UI::status_dropdown('status', $status_filter);
        
        echo '<button type="submit" class="button">' . esc_html__('Filtrele', 'woo-uretim-planlama') . '</button>';
        echo '</div>';
        echo '</form>';
        
        // Eylem butonları
        echo '<div class="wup-actions">';
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('wup_export_csv', 'wup_export_nonce');
        echo '<input type="hidden" name="wup_export_csv" value="1">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('CSV İndir', 'woo-uretim-planlama') . '</button>';
        echo '</form>';
        echo '<a href="' . esc_url(add_query_arg('refresh', time())) . '" class="button">' . esc_html__('Yenile', 'woo-uretim-planlama') . '</a>';
        echo '</div>';
        
        // Cache temizle
        if (isset($_GET['refresh'])) {
            WUP_Cache::clear_group('report');
        }
        
        // Rapor verisini al
        $stats = $this->get_report_stats($start_date, $end_date, $status_filter);
        
        // İstatistik tablosu
        echo '<h2>' . esc_html__('Durum Süre İstatistikleri', 'woo-uretim-planlama') . '</h2>';
        
        if (empty($stats)) {
            WUP_UI::notice(__('Seçilen filtreler için veri bulunamadı.', 'woo-uretim-planlama'), 'warning');
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Ortalama', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Minimum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Maksimum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Geçiş Sayısı', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($stats as $status => $data) {
                echo '<tr>';
                echo '<td>' . esc_html($data['name']) . '</td>';
                echo '<td>' . WUP_UI::format_duration($data['avg']) . '</td>';
                echo '<td>' . WUP_UI::format_duration($data['min']) . '</td>';
                echo '<td>' . WUP_UI::format_duration($data['max']) . '</td>';
                echo '<td>' . number_format_i18n($data['count']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            
            // Grafik
            if (count($stats) > 1 && empty($status_filter)) {
                echo '<div class="wup-chart-container">';
                echo '<h2>' . esc_html__('Durum Bazında Ortalama Süre', 'woo-uretim-planlama') . '</h2>';
                echo '<canvas id="reportChart"></canvas>';
                echo '</div>';
                
                $chart_data = array(
                    'labels' => array(),
                    'data' => array(),
                    'colors' => WUP_UI::get_chart_colors(count($stats))
                );
                
                foreach ($stats as $data) {
                    $chart_data['labels'][] = $data['name'];
                    $chart_data['data'][] = $data['avg'];
                }
                
                echo '<script>var wupReportData = ' . wp_json_encode($chart_data) . ';</script>';
            }
        }
        
        // Mevcut iş yükü
        echo '<hr style="margin: 30px 0;">';
        echo '<h2>' . esc_html__('Mevcut İş Yükü', 'woo-uretim-planlama') . '</h2>';
        
        $workload = $this->get_current_workload($stats);
        
        if (empty($workload)) {
            WUP_UI::notice(__('Açık sipariş bulunamadı.', 'woo-uretim-planlama'), 'info');
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Durum', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Sipariş Sayısı', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Ortalama Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Tahmini İş Yükü', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            $total_workload = 0;
            
            foreach ($workload as $item) {
                echo '<tr>';
                echo '<td>' . esc_html($item['name']) . '</td>';
                echo '<td>' . number_format_i18n($item['count']) . '</td>';
                echo '<td>' . WUP_UI::format_duration($item['avg']) . '</td>';
                echo '<td><strong>' . WUP_UI::format_business_hours($item['total']) . '</strong></td>';
                echo '</tr>';
                $total_workload += $item['total'];
            }
            
            echo '</tbody>';
            echo '<tfoot><tr>';
            echo '<th colspan="3" style="text-align:right;">' . esc_html__('Toplam İş Yükü:', 'woo-uretim-planlama') . '</th>';
            echo '<th><strong>' . WUP_UI::format_business_hours($total_workload) . '</strong></th>';
            echo '</tr></tfoot>';
            echo '</table>';
        }
        
        WUP_UI::page_footer();
    }
    
    /**
     * Rapor istatistiklerini al
     */
    private function get_report_stats($start_date, $end_date, $status_filter) {
        $cache_key = 'report_' . md5($start_date . $end_date . $status_filter);
        
        return WUP_Cache::get($cache_key, function() use ($start_date, $end_date, $status_filter) {
            global $wpdb;
            
            $where = array('1=1');
            $params = array();
            
            if ($start_date) {
                $where[] = 'DATE(h.changed_at) >= %s';
                $params[] = $start_date;
            }
            
            if ($end_date) {
                $where[] = 'DATE(h.changed_at) <= %s';
                $params[] = $end_date;
            }
            
            if ($status_filter) {
                $where[] = 'h.status = %s';
                $params[] = $status_filter;
            }
            
            $where_sql = implode(' AND ', $where);
            
            $query = "SELECT h.status,
                            TIMESTAMPDIFF(SECOND, h.changed_at, 
                                (SELECT MIN(h2.changed_at) FROM {$this->table_name} h2 
                                 WHERE h2.order_id = h.order_id AND h2.changed_at > h.changed_at)
                            ) as duration
                      FROM {$this->table_name} h
                      WHERE {$where_sql}
                      HAVING duration IS NOT NULL AND duration > 0";
            
            if (!empty($params)) {
                $query = $wpdb->prepare($query, $params);
            }
            
            $results = $wpdb->get_results($query);
            
            $durations = array();
            foreach ($results as $row) {
                $status = $row->status;
                if (!isset($durations[$status])) {
                    $durations[$status] = array();
                }
                $durations[$status][] = intval($row->duration);
            }
            
            $stats = array();
            $status_names = wc_get_order_statuses();
            
            foreach ($durations as $status => $list) {
                if (empty($list)) {
                    continue;
                }
                
                $status_key = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
                $status_name = isset($status_names[$status_key]) ? $status_names[$status_key] : $status;
                
                $count = count($list);
                $stats[$status] = array(
                    'name' => $status_name,
                    'avg' => round(array_sum($list) / $count),
                    'min' => min($list),
                    'max' => max($list),
                    'count' => $count
                );
            }
            
            // İsme göre sırala
            uasort($stats, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            return $stats;
        }, WUP_Settings::get('cache_duration', 3600));
    }
    
    /**
     * Mevcut iş yükünü hesapla
     */
    private function get_current_workload($stats) {
        global $wpdb;
        
        $scheduler = WUP_Scheduler::get_instance();
        $final_statuses = $scheduler->get_final_statuses();
        $final_sql = "'" . implode("','", array_map('esc_sql', $final_statuses)) . "'";
        
        $counts = $wpdb->get_results(
            "SELECT post_status, COUNT(ID) as order_count
             FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
             AND post_status NOT IN ({$final_sql})
             GROUP BY post_status",
            OBJECT_K
        );
        
        if (empty($counts)) {
            return array();
        }
        
        $workload = array();
        $status_names = wc_get_order_statuses();
        
        foreach ($counts as $status => $data) {
            $status_key = str_replace('wc-', '', $status);
            $avg = isset($stats[$status_key]) ? $stats[$status_key]['avg'] : 0;
            
            if ($avg <= 0) {
                // Stats'tan bulunamazsa, settings'ten al
                $avg = WUP_Settings::get_status_duration($status);
            }
            
            $count = intval($data->order_count);
            $total = $count * $avg;
            
            $status_name = isset($status_names[$status]) ? $status_names[$status] : $status;
            
            $workload[$status] = array(
                'name' => $status_name,
                'count' => $count,
                'avg' => $avg,
                'total' => $total
            );
        }
        
        // İş yüküne göre sırala (büyükten küçüğe)
        uasort($workload, function($a, $b) {
            return $b['total'] - $a['total'];
        });
        
        return $workload;
    }
    
    /**
     * Ayarlar sayfası
     */
    public function render_settings_page() {
        // Ayarlar kaydedildiyse
        if (isset($_POST['wup_settings']) && check_admin_referer('wup_settings_nonce', 'wup_nonce')) {
            WUP_Settings::save($_POST['wup_settings']);
            WUP_UI::notice(__('Ayarlar kaydedildi.', 'woo-uretim-planlama'), 'success');
        }
        
        $settings = WUP_Settings::get_all();
        
        WUP_UI::page_header(__('Eklenti Ayarları', 'woo-uretim-planlama'));
        
        echo '<form method="post">';
        wp_nonce_field('wup_settings_nonce', 'wup_nonce');
        
        // Genel Ayarlar
        echo '<h2>' . esc_html__('Genel Üretim Ayarları', 'woo-uretim-planlama') . '</h2>';
        echo '<table class="form-table">';
        
        // Personel sayısı - departmanlardan otomatik hesaplanıyor
        $total_workers = 0;
        $departments = WUP_Departments::get_all();
        foreach ($departments as $dept) {
            $total_workers += $dept['workers'];
        }
        
        echo '<tr>';
        echo '<th>' . esc_html__('Toplam Personel Sayısı', 'woo-uretim-planlama') . '</th>';
        echo '<td>';
        echo '<strong style="font-size:18px; color:#0073aa;">' . esc_html($total_workers) . '</strong> ' . esc_html__('kişi', 'woo-uretim-planlama');
        echo '<p class="description">' . esc_html__('Bu değer departman ayarlarından otomatik hesaplanır.', 'woo-uretim-planlama') . ' ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=wup-departments')) . '">' . esc_html__('Departmanları Yönet →', 'woo-uretim-planlama') . '</a></p>';
        echo '</td>';
        echo '</tr>';
        
        // Günlük çalışma saati
        echo '<tr>';
        echo '<th><label for="daily_hours">' . esc_html__('Günlük Çalışma Saati', 'woo-uretim-planlama') . '</label></th>';
        echo '<td><input type="number" name="wup_settings[daily_hours]" id="daily_hours" value="' . esc_attr($settings['daily_hours']) . '" min="0.5" max="24" step="0.5" class="small-text">';
        echo '<p class="description">' . esc_html__('Personel başına günlük ortalama çalışma saati.', 'woo-uretim-planlama') . '</p></td>';
        echo '</tr>';
        
        // Çalışma günleri
        echo '<tr>';
        echo '<th>' . esc_html__('Çalışma Günleri', 'woo-uretim-planlama') . '</th>';
        echo '<td><fieldset>';
        
        $days = array(
            '1' => __('Pazartesi', 'woo-uretim-planlama'),
            '2' => __('Salı', 'woo-uretim-planlama'),
            '3' => __('Çarşamba', 'woo-uretim-planlama'),
            '4' => __('Perşembe', 'woo-uretim-planlama'),
            '5' => __('Cuma', 'woo-uretim-planlama'),
            '6' => __('Cumartesi', 'woo-uretim-planlama'),
            '0' => __('Pazar', 'woo-uretim-planlama')
        );
        
        foreach ($days as $value => $label) {
            $checked = in_array($value, $settings['working_days']) ? 'checked' : '';
            echo '<label style="margin-right:15px;"><input type="checkbox" name="wup_settings[working_days][]" value="' . esc_attr($value) . '" ' . $checked . '> ' . esc_html($label) . '</label>';
        }
        
        echo '</fieldset></td>';
        echo '</tr>';
        
        echo '</table>';
        
        // Departman Özet Bilgisi
        echo '<h2>' . esc_html__('Departman Özeti', 'woo-uretim-planlama') . '</h2>';
        echo '<div style="background:#f0f0f1; padding:15px; border-radius:5px; max-width:800px; margin-bottom:20px;">';
        echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__('Departman ayarları artık merkezi olarak yönetiliyor.', 'woo-uretim-planlama') . '</strong></p>';
        
        $departments = WUP_Departments::get_all();
        if (!empty($departments)) {
            echo '<table class="widefat fixed striped" style="margin-top:10px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('İşçi', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Temel Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Bağlı Durumlar', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($departments as $dept) {
                echo '<tr>';
                echo '<td><span style="display:inline-block; width:12px; height:12px; background:' . esc_attr($dept['color']) . '; border-radius:2px; margin-right:5px;"></span>' . esc_html($dept['name']) . '</td>';
                echo '<td>' . esc_html($dept['workers']) . ' ' . esc_html__('kişi', 'woo-uretim-planlama') . '</td>';
                echo '<td>' . esc_html($dept['base_duration']) . ' ' . esc_html__('dakika', 'woo-uretim-planlama') . '</td>';
                echo '<td>';
                if (!empty($dept['statuses'])) {
                    $status_names = array();
                    foreach ($dept['statuses'] as $status) {
                        $name = wc_get_order_status_name(str_replace('wc-', '', $status));
                        if ($name) {
                            $status_names[] = $name;
                        }
                    }
                    echo esc_html(implode(', ', $status_names));
                } else {
                    echo '<span style="color:#999;">-</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '<p style="margin:15px 0 0 0;"><a href="' . esc_url(admin_url('admin.php?page=wup-departments')) . '" class="button button-primary">' . esc_html__('Departmanları Düzenle', 'woo-uretim-planlama') . '</a></p>';
        echo '</div>';
        
        // Bildirim Ayarları
        echo '<h2>' . esc_html__('Bildirim Ayarları', 'woo-uretim-planlama') . '</h2>';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th><label for="notifications_enabled">' . esc_html__('Bildirimleri Etkinleştir', 'woo-uretim-planlama') . '</label></th>';
        echo '<td><input type="checkbox" name="wup_settings[notifications_enabled]" id="notifications_enabled" value="1" ' . checked(1, $settings['notifications_enabled'], false) . '>';
        echo '<p class="description">' . esc_html__('Uzun süren durumlar için e-posta bildirimi gönder.', 'woo-uretim-planlama') . '</p></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th><label for="notification_threshold">' . esc_html__('Eşik Süresi (Saat)', 'woo-uretim-planlama') . '</label></th>';
        echo '<td><input type="number" name="wup_settings[notification_threshold]" id="notification_threshold" value="' . esc_attr($settings['notification_threshold']) . '" min="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Bu süreden uzun kalan siparişler için bildirim gönderilir.', 'woo-uretim-planlama') . '</p></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th><label for="notification_email">' . esc_html__('Bildirim E-postası', 'woo-uretim-planlama') . '</label></th>';
        echo '<td><input type="email" name="wup_settings[notification_email]" id="notification_email" value="' . esc_attr($settings['notification_email']) . '" class="regular-text"></td>';
        echo '</tr>';
        
        echo '</table>';
        
        // Performans Ayarları
        echo '<h2>' . esc_html__('Performans', 'woo-uretim-planlama') . '</h2>';
        echo '<table class="form-table">';
        
        // Cache duration'ı dakika olarak göster
        $cache_minutes = intval($settings['cache_duration'] / 60);
        
        echo '<tr>';
        echo '<th><label for="cache_duration">' . esc_html__('Önbellek Süresi (Dakika)', 'woo-uretim-planlama') . '</label></th>';
        echo '<td><input type="number" name="wup_settings[cache_duration]" id="cache_duration" value="' . esc_attr($cache_minutes) . '" min="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Rapor verilerinin önbellekte tutulma süresi.', 'woo-uretim-planlama') . '</p></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Önbellek Yönetimi', 'woo-uretim-planlama') . '</th>';
        echo '<td>';
        echo '<button type="button" id="wup-clear-cache" class="button">' . esc_html__('Önbelleği Temizle', 'woo-uretim-planlama') . '</button>';
        echo '<span id="wup-cache-status" class="wup-status"></span>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Eski Veri', 'woo-uretim-planlama') . '</th>';
        echo '<td>';
        echo '<button type="button" id="wup-clear-old-data" class="button button-link-delete">' . esc_html__('6 Aydan Eski Veriyi Sil', 'woo-uretim-planlama') . '</button>';
        echo '<span id="wup-data-status" class="wup-status"></span>';
        echo '<p class="description" style="color:red;">' . esc_html__('Bu işlem geri alınamaz!', 'woo-uretim-planlama') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        submit_button(__('Ayarları Kaydet', 'woo-uretim-planlama'));
        
        echo '</form>';
        
        WUP_UI::page_footer();
    }
    
    /**
     * AJAX: Önbellek temizle
     */
    public function ajax_clear_cache() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        WUP_Cache::clear_all();
        wp_send_json_success(array('message' => __('Önbellek temizlendi.', 'woo-uretim-planlama')));
    }
    
    /**
     * AJAX: Eski veri sil
     */
    public function ajax_clear_old_data() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        global $wpdb;
        
        $threshold = gmdate('Y-m-d H:i:s', strtotime('-6 months'));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE changed_at < %s",
            $threshold
        ));
        
        if ($deleted === false) {
            wp_send_json_error(array('message' => __('Veritabanı hatası.', 'woo-uretim-planlama')));
        }
        
        WUP_Cache::clear_all();
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d kayıt silindi.', 'woo-uretim-planlama'), $deleted)
        ));
    }
    
    /**
     * CSV Export
     */
    public function handle_csv_export() {
        if (!isset($_POST['wup_export_csv'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['wup_export_nonce'], 'wup_export_csv')) {
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        $stats = $this->get_report_stats($start_date, $end_date, $status_filter);
        
        if (empty($stats)) {
            wp_die(__('Dışa aktarılacak veri yok.', 'woo-uretim-planlama'));
        }
        
        $filename = 'uretim-rapor-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Başlıklar
        fputcsv($output, array(
            __('Durum', 'woo-uretim-planlama'),
            __('Ortalama (SS:DD:SS)', 'woo-uretim-planlama'),
            __('Minimum (SS:DD:SS)', 'woo-uretim-planlama'),
            __('Maksimum (SS:DD:SS)', 'woo-uretim-planlama'),
            __('Geçiş Sayısı', 'woo-uretim-planlama'),
            __('Ortalama (Saniye)', 'woo-uretim-planlama')
        ));
        
        // Veriler
        foreach ($stats as $data) {
            fputcsv($output, array(
                $data['name'],
                WUP_UI::format_duration($data['avg']),
                WUP_UI::format_duration($data['min']),
                WUP_UI::format_duration($data['max']),
                $data['count'],
                $data['avg']
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wup/v1', '/report', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_report'),
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ));
        
        register_rest_route('wup/v1', '/orders/(?P<id>\d+)/history', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_order_history'),
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ));
    }
    
    /**
     * API: Rapor verisi
     */
    public function api_get_report($request) {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $status = $request->get_param('status');
        
        $stats = $this->get_report_stats($start_date, $end_date, $status);
        
        return new WP_REST_Response(array(
            'success' => true,
            'stats' => $stats
        ), 200);
    }
    
    /**
     * API: Sipariş geçmişi
     */
    public function api_get_order_history($request) {
        global $wpdb;
        
        $order_id = intval($request['id']);
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE order_id = %d 
             ORDER BY changed_at ASC",
            $order_id
        ));
        
        if (empty($history)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Geçmiş bulunamadı.', 'woo-uretim-planlama')
            ), 404);
        }
        
        $status_names = wc_get_order_statuses();
        $formatted = array();
        
        for ($i = 0; $i < count($history); $i++) {
            $row = $history[$i];
            $status_key = strpos($row->status, 'wc-') === 0 ? $row->status : 'wc-' . $row->status;
            
            $duration = null;
            if (isset($history[$i + 1])) {
                $duration = strtotime($history[$i + 1]->changed_at) - strtotime($row->changed_at);
            }
            
            $formatted[] = array(
                'id' => intval($row->id),
                'status' => $row->status,
                'status_name' => isset($status_names[$status_key]) ? $status_names[$status_key] : $row->status,
                'changed_at' => $row->changed_at,
                'duration_seconds' => $duration,
                'duration_formatted' => $duration ? WUP_UI::format_duration($duration) : null
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'order_id' => $order_id,
            'history' => $formatted
        ), 200);
    }
}
