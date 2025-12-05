<?php
if (!defined('ABSPATH')) exit; // Direct access not allowed

// Gerekli Sınıfları Yükle
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-prod-settings-helper.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-status-duration-ui.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-status-duration-cache.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-status-duration-analytics.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-production-scheduler.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-order-calendar.php';
require_once WC_PROD_DURATION_PATH . 'includes/class-wc-status-duration-dashboard.php';

/**
 * Ana Eklenti Sınıfı
 */
class WC_Status_Duration_Report {
    private static $instance;
    private $table_name;
    public $cache;
    public $settings_helper;
    public $analytics;
    public $scheduler;
    public $calendar;
    public $dashboard;
    private $version;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'order_status_history';
        $this->version = WC_PROD_DURATION_VERSION;

        $this->cache = new WC_Status_Duration_Cache();
        $this->settings_helper = new WC_Prod_Settings_Helper();
        $this->analytics = new WC_Status_Duration_Analytics($this->table_name, $this->settings_helper, $this->cache);
        $this->scheduler = new WC_Production_Scheduler($this->table_name, $this->settings_helper, $this->cache);
        $this->calendar = new WC_Order_Calendar($this->scheduler); // Scheduler'a bağımlı
        $this->dashboard = new WC_Status_Duration_Dashboard($this->table_name, $this->cache);

        $this->add_hooks();
    }

    private function add_hooks() {
        add_action('init', [$this, 'load_textdomain']);
        register_activation_hook(WC_PROD_DURATION_FILE, [$this, 'activate_plugin']); // Ana dosya yolu ile
        register_deactivation_hook(WC_PROD_DURATION_FILE, [$this, 'deactivate_plugin']);

        add_action('plugins_loaded', [$this, 'check_version'], 1); // Daha erken kontrol
        add_action('admin_init', [$this, 'init_settings']);
        add_action('woocommerce_order_status_changed', [$this, 'log_status_change'], 10, 4);
        add_action('add_meta_boxes_shop_order', [$this, 'add_order_meta_boxes']); // Daha spesifik hook
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_csv_export']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        add_action('wp_ajax_wc_status_duration_clear_data', [$this, 'ajax_clear_old_data']);
        add_action('wp_ajax_wc_status_duration_clear_cache', [$this, 'ajax_clear_cache']);

        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Ayarlar kaydedildiğinde önbelleği temizle
        add_action('update_option_' . WC_Prod_Settings_Helper::SETTINGS_KEY, [$this, 'clear_relevant_cache_on_settings_update'], 10, 0); // 0 argüman alır
    }

    public function load_textdomain() {
        load_plugin_textdomain('wc-prod-duration', false, dirname(plugin_basename(WC_PROD_DURATION_FILE)) . '/languages');
    }

    public function activate_plugin() {
        $this->create_table();
        $this->settings_helper->save_default_settings();
        update_option('wc_status_duration_version', $this->version);
        flush_rewrite_rules();
    }

    public function deactivate_plugin() {
        flush_rewrite_rules();
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        // changed_at için index ve NOT NULL DEFAULT '000...' eklendi
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(100) NOT NULL,
            changed_at DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            meta LONGTEXT,
            INDEX idx_order_id (order_id),
            INDEX idx_status (status),
            INDEX idx_changed_at (changed_at),
            INDEX idx_order_status_time (order_id, status, changed_at)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function check_version() {
        $installed_version = get_option('wc_status_duration_version');
        if (!$installed_version || version_compare($installed_version, $this->version, '<')) {
            $this->update_plugin_version($installed_version);
            update_option('wc_status_duration_version', $this->version);
        }
    }

    public function update_plugin_version($old_version) {
         global $wpdb;
         if (!$old_version || version_compare($old_version, '3.1.0', '<')) {
             // Gerekirse veritabanı veya ayar güncellemeleri
             $this->create_table(); // İndeksleri vb. kontrol et/ekle
             $this->settings_helper->save_default_settings(); // Yeni ayarları ekle
             // Meta sütununun tipini LONGTEXT yap (daha fazla veri için)
              $wpdb->query("ALTER TABLE {$this->table_name} MODIFY COLUMN meta LONGTEXT");
         }
    }

    public function init_settings() {
        // Ayar kaydı ve alan tanımlamaları (önceki kodla aynı)
        register_setting('wc_prod_duration_settings_group', WC_Prod_Settings_Helper::SETTINGS_KEY, [$this->settings_helper, 'validate_settings']);
        $settings_page = 'wc_prod_duration_settings';
        // Sections
        add_settings_section('wc_prod_general_section', __('General Production Settings', 'wc-prod-duration'), [$this->settings_helper, 'render_general_section'], $settings_page);
        add_settings_section('wc_prod_status_duration_section', __('Estimated Duration Per Status (seconds)', 'wc-prod-duration'), [$this->settings_helper, 'render_status_duration_section'], $settings_page);
        add_settings_section('wc_prod_notification_section', __('Notification Settings', 'wc-prod-duration'), [$this->settings_helper, 'render_notification_section'], $settings_page);
        add_settings_section('wc_prod_performance_section', __('Performance and Data Management', 'wc-prod-duration'), [$this->settings_helper, 'render_performance_section'], $settings_page);
        // Fields (General)
        add_settings_field('personnel_count', __('Number of Personnel', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_general_section', ['id' => 'personnel_count', 'type' => 'number', 'default' => 1, 'desc' => __('Number of active personnel in production.', 'wc-prod-duration')]);
        add_settings_field('daily_hours', __('Daily Working Hours', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_general_section', ['id' => 'daily_hours', 'type' => 'number', 'step' => 0.5, 'default' => 8, 'desc' => __('Average daily working hours per personnel.', 'wc-prod-duration')]);
        add_settings_field('working_days', __('Working Days of the Week', 'wc-prod-duration'), [$this->settings_helper, 'render_working_days_field'], $settings_page, 'wc_prod_general_section');
        // Fields (Durations)
        add_settings_field('status_durations', __('Estimated Durations', 'wc-prod-duration'), [$this->settings_helper, 'render_status_durations_field'], $settings_page, 'wc_prod_status_duration_section');
        // Fields (Notifications)
        add_settings_field('notifications_enabled', __('Enable Notifications', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_notification_section', ['id' => 'notifications_enabled', 'type' => 'checkbox', 'desc' => __('Enable overdue status notifications.', 'wc-prod-duration')]);
        add_settings_field('notification_threshold', __('Max Duration Threshold (hours)', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_notification_section', ['id' => 'notification_threshold', 'type' => 'number', 'default' => 24, 'desc' => __('Send notification if an order stays in the same status longer than this duration.', 'wc-prod-duration')]);
        add_settings_field('notification_emails', __('Notification Emails', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_notification_section', ['id' => 'notification_emails', 'type' => 'text', 'desc' => __('Comma-separated email addresses. Default: admin email.', 'wc-prod-duration'), 'placeholder' => get_option('admin_email')]);
        // Fields (Performance)
        add_settings_field('cache_time', __('Cache Duration (seconds)', 'wc-prod-duration'), [$this->settings_helper, 'render_field'], $settings_page, 'wc_prod_performance_section', ['id' => 'cache_time', 'type' => 'number', 'default' => 3600, 'desc' => __('How long report data is cached. Clear cache for changes to take effect immediately.', 'wc-prod-duration')]);
    }

    public function log_status_change($order_id, $old_status, $new_status, $order) {
        global $wpdb;

        // Aynı durum değişikliğini çok kısa sürede tekrar loglama
        $last_log = $wpdb->get_row($wpdb->prepare(
            "SELECT status, changed_at FROM {$this->table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order_id
        ));
        if ($last_log && $last_log->status === $new_status && (time() - strtotime($last_log->changed_at . ' GMT')) < 5) {
             return;
        }

        $meta = [
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'previous_status' => $old_status,
            'source' => defined('DOING_AJAX') && DOING_AJAX ? 'ajax' : (defined('WP_CLI') && WP_CLI ? 'cli' : (is_admin() ? 'admin' : 'frontend')),
        ];

        $inserted = $wpdb->insert($this->table_name, [
            'order_id'    => $order_id,
            'status'      => $new_status, // 'wc-' olmadan gelir
            'changed_at'  => current_time('mysql', 1), // GMT
            'meta'        => wp_json_encode($meta),
        ]);

        if ($inserted) {
            // İlgili önbellekleri temizle (daha hedefli)
            $this->cache->delete_cache('order_meta_' . $order_id);
            $this->cache->delete_cache('order_history_api_' . $order_id);
            $this->cache->delete_cache('order_actual_avg_' . $order_id); // Program sayfası için
            $this->cache->clear_cache_group('report_data');
            $this->cache->clear_cache_group('analytics');
            $this->cache->clear_cache_group('schedule');
            $this->cache->clear_cache_group('dashboard_widget'); // Widget önbelleği
        } else {
             error_log("WC_Status_Duration_Report: Failed to log status change for order ID {$order_id}. DB Error: " . $wpdb->last_error);
        }

        // Bildirim kontrolü
        if ($this->settings_helper->get_setting('notifications_enabled', 0)) {
            $this->check_duration_thresholds($order_id, $old_status, $new_status, $order);
        }
    }

    public function check_duration_thresholds($order_id, $old_status, $new_status, $order) {
        // Bildirim mantığı (önceki kodla aynı)
         global $wpdb;
         $prev_timestamp_gmt = $wpdb->get_var($wpdb->prepare(
             "SELECT MAX(changed_at) FROM {$this->table_name} WHERE order_id = %d AND status = %s AND changed_at < NOW()",
             $order_id, $old_status
         ));
         if ($prev_timestamp_gmt) {
             $duration = time() - strtotime($prev_timestamp_gmt . ' GMT');
             $threshold_hours = (int)$this->settings_helper->get_setting('notification_threshold', 24);
             $threshold_seconds = $threshold_hours * 3600;
             if ($threshold_seconds > 0 && $duration > $threshold_seconds) {
                 $this->send_threshold_notification($order_id, $old_status, $new_status, $duration);
             }
         }
    }

    private function send_threshold_notification($order_id, $old_status, $new_status, $duration) {
        // Bildirim gönderme mantığı (önceki kodla aynı)
         $emails_raw = $this->settings_helper->get_setting('notification_emails', get_option('admin_email'));
         $email_list = array_map('trim', preg_split('/[\s,]+/', $emails_raw));
         $valid_emails = array_filter($email_list, 'is_email');
         if (empty($valid_emails)) return;

         $order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
         $subject = sprintf(__('Order #%s exceeded duration in status "%s"', 'wc-prod-duration'), $order_id, wc_get_order_status_name($old_status));
         $message = sprintf(
             __('Order #%s stayed in status "%s" for %s before changing to "%s".', 'wc-prod-duration'),
             '<a href="' . esc_url($order_url) . '">#' . esc_html($order_id) . '</a>',
             esc_html(wc_get_order_status_name($old_status)),
             '<strong>' . esc_html($this->format_duration($duration)) . '</strong>',
             esc_html(wc_get_order_status_name($new_status))
         );
         $message .= "<br><br>" . sprintf(__('Threshold was set to %d hours.', 'wc-prod-duration'), (int)$this->settings_helper->get_setting('notification_threshold', 24));
         $message .= "<br><br>" . sprintf(__('View Order: %s', 'wc-prod-duration'), '<a href="' . esc_url($order_url) . '">' . esc_url($order_url) . '</a>');
         $headers = ['Content-Type: text/html; charset=UTF-8'];
         wp_mail($valid_emails, $subject, $message, $headers);
    }

    public function add_order_meta_boxes($post) { // Hook parametresi post objesidir
        // Meta kutusu ekleme (önceki kodla aynı)
        add_meta_box('order_status_duration_history', __('Order Status History & Durations', 'wc-prod-duration'), [$this, 'render_order_history_meta_box'], 'shop_order', 'normal', 'default');
        add_meta_box('order_production_info', __('Production Info', 'wc-prod-duration'), [$this->scheduler, 'render_order_production_meta_box'], 'shop_order', 'side', 'default');
    }

    public function render_order_history_meta_box($post) {
        // Meta kutusu içeriği (önceki kodla aynı, GMT->Local çevrimi eklendi)
         global $wpdb;
         $order_id = $post->ID;
         $results = $this->cache->get_cached_data('order_meta_' . $order_id, function($order_id_arg) use ($wpdb) {
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT status, changed_at FROM {$this->table_name} WHERE order_id = %d ORDER BY changed_at ASC",
                    $order_id_arg
                ));
            }, [$order_id]);

         if (empty($results)) { echo '<p>' . esc_html__('No status history data found.', 'wc-prod-duration') . '</p>'; return; }

         echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__('Status', 'wc-prod-duration') . '</th><th>' . esc_html__('Transition Time (Local)', 'wc-prod-duration') . '</th><th>' . esc_html__('Duration in This Status', 'wc-prod-duration') . '</th></tr></thead><tbody>';
         $status_totals = [];
         for ($i = 0; $i < count($results); $i++) {
            $row = $results[$i]; $duration_str = '-'; $duration_sec = 0; $status_key = $row->status;
            $local_time = get_date_from_gmt($row->changed_at, get_option('date_format') . ' ' . get_option('time_format')); // Yerel saate çevir

            if (isset($results[$i + 1])) {
                $diff = strtotime($results[$i + 1]->changed_at . ' GMT') - strtotime($row->changed_at . ' GMT');
                if ($diff > 0) { $duration_sec = $diff; $duration_str = $this->format_duration($duration_sec); }
            } else {
                $diff = time() - strtotime($row->changed_at . ' GMT');
                 if ($diff > 0) { $duration_sec = $diff; $duration_str = $this->format_duration($duration_sec) . ' (' . __('ongoing', 'wc-prod-duration') . ')'; }
            }
             if (!isset($status_totals[$status_key])) $status_totals[$status_key] = 0;
             $status_totals[$status_key] += $duration_sec;

            echo '<tr><td>' . esc_html(wc_get_order_status_name($status_key) ?: $status_key) . '</td><td>' . esc_html($local_time) . '</td><td>' . esc_html($duration_str) . '</td></tr>';
         }
         echo '</tbody></table>';
         echo '<h4>' . esc_html__('Total Time Spent Per Status:', 'wc-prod-duration') . '</h4><ul>';
         if (empty($status_totals)) { echo '<li>' . esc_html__('No duration data available.', 'wc-prod-duration') . '</li>'; }
         else { foreach($status_totals as $status => $total_seconds) { if ($total_seconds > 0) { echo '<li><strong>' . esc_html(wc_get_order_status_name($status) ?: $status) . ':</strong> ' . $this->format_duration($total_seconds) . '</li>'; } } }
         echo '</ul>';
    }

    private function format_duration($seconds) {
        // Süre formatlama (önceki kodla aynı)
         if (!is_numeric($seconds) || $seconds < 0) return '00:00:00';
        $H = floor($seconds / 3600); $M = floor(($seconds % 3600) / 60); $S = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $H, $M, $S);
    }

    public function add_admin_menu() {
        // Menü ekleme (önceki kodla aynı)
        $main_slug = 'wc-production-report';
        add_menu_page(__('Production Reports', 'wc-prod-duration'), __('Production Reports', 'wc-prod-duration'), 'manage_woocommerce', $main_slug, [$this, 'render_report_page'], 'dashicons-chart-line', 56);
        add_submenu_page($main_slug, __('Status Duration Report', 'wc-prod-duration'), __('Duration Report', 'wc-prod-duration'), 'manage_woocommerce', $main_slug, [$this, 'render_report_page']);
        add_submenu_page($main_slug, __('Production Schedule', 'wc-prod-duration'), __('Schedule', 'wc-prod-duration'), 'manage_woocommerce', 'wc-production-schedule', [$this->scheduler, 'render_schedule_page']);
        add_submenu_page($main_slug, __('Order Calendar', 'wc-prod-duration'), __('Calendar', 'wc-prod-duration'), 'manage_woocommerce', 'wc-order-calendar', [$this->calendar, 'render_calendar_page']);
        add_submenu_page($main_slug, __('Advanced Analysis', 'wc-prod-duration'), __('Analysis', 'wc-prod-duration'), 'manage_woocommerce', 'wc-status-duration-analysis', [$this, 'render_analysis_page']);
        add_submenu_page($main_slug, __('Settings', 'wc-prod-duration'), __('Settings', 'wc-prod-duration'), 'manage_options', 'wc-prod-duration-settings', [$this, 'render_settings_page']);
    }

    /**
     * Ana Rapor Sayfasını (Durum Süre Raporu) render et.
     * YENİ: Mevcut durum iş yükü tablosu eklendi.
     */
    public function render_report_page() {
        global $wpdb;
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        $selected_status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : ''; // 'wc-' olmadan

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Order Status Duration Report', 'wc-prod-duration') . '</h1>';

        // Filtreleme Formu (önceki kodla aynı)
        echo '<form method="get" class="wc-status-filter-form">';
        echo '<input type="hidden" name="page" value="wc-production-report">';
        echo '<div class="filter-row">';
        echo '<label for="start_date">' . esc_html__('Start Date:', 'wc-prod-duration') . '</label>';
        echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($start_date) . '" style="max-width: 150px;"> ';
        echo '<label for="end_date">' . esc_html__('End Date:', 'wc-prod-duration') . '</label>';
        echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($end_date) . '" style="max-width: 150px;"> ';
        WC_Status_Duration_UI::render_status_filter_dropdown($selected_status_filter); // 'wc-' olmadan alır/gönderir
        echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'wc-prod-duration') . '">';
        echo '</div></form>';

        // Butonlar (önceki kodla aynı)
        echo '<div class="report-actions" style="margin-top: 15px; margin-bottom: 20px;">';
        echo '<form method="post" id="export-csv-form" style="display:inline-block; margin-right: 10px;"><input type="hidden" name="export_csv" value="1">';
        echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Export CSV', 'wc-prod-duration') . '"></form>';
        echo '<a href="' . esc_url(add_query_arg(['refresh_report' => time()], remove_query_arg('refresh_report'))) . '" class="button">' . esc_html__('Refresh Report', 'wc-prod-duration') . '</a>';
        echo '</div>';

        // 1. Geçmiş Veri İstatistikleri
        $report_data = $this->calculate_report_data($start_date, $end_date, $selected_status_filter);
        $stats = $report_data['stats'] ?? [];

        echo '<h2>' . esc_html__('Historical Status Duration Statistics', 'wc-prod-duration') . '</h2>';
        if (empty($stats)) {
            WC_Status_Duration_UI::show_notice(__('No historical data found for the selected filters.', 'wc-prod-duration'), 'warning');
        } else {
            // İstatistik Tablosu (önceki kodla aynı)
            echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__('Status', 'wc-prod-duration') . '</th><th>' . esc_html__('Average Duration', 'wc-prod-duration') . '</th><th>' . esc_html__('Min Duration', 'wc-prod-duration') . '</th><th>' . esc_html__('Max Duration', 'wc-prod-duration') . '</th><th>' . esc_html__('Transitions Count', 'wc-prod-duration') . '</th></tr></thead><tbody>';
            foreach ($stats as $status_key => $data) { // status_key 'wc-' içerir
                 $status_filter_key = 'wc-' . $selected_status_filter; // Filtre anahtarını 'wc-' li yap
                 if (empty($selected_status_filter) || $status_filter_key === $status_key) {
                    echo '<tr><td>' . esc_html(wc_get_order_status_name($status_key) ?: $status_key) . '</td><td>' . $this->format_duration($data['avg']) . '</td><td>' . $this->format_duration($data['min']) . '</td><td>' . $this->format_duration($data['max']) . '</td><td>' . number_format_i18n($data['count']) . '</td></tr>';
                 }
            }
            echo '</tbody></table>';

            // Grafik (önceki kodla aynı, veri aktarımı güncellendi)
            if(count($stats) > 1 && empty($selected_status_filter)) {
                 $chart_labels = []; $chart_data_avg = []; $status_names_for_chart = [];
                 foreach ($stats as $status_key => $data) {
                     $status_name = wc_get_order_status_name($status_key) ?: $status_key;
                     $chart_labels[] = $status_key;
                     $chart_data_avg[] = $data['avg'];
                     $status_names_for_chart[$status_key] = $status_name;
                 }
                 echo '<div class="chart-container" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px; position: relative; height:40vh; width:80vw; max-width: 900px;"><h2>' . esc_html__('Average Duration per Status', 'wc-prod-duration') . '</h2><canvas id="durationChart"></canvas></div>';
                 $chart_js_data = ['type' => 'bar', 'element_id' => 'durationChart', 'labels' => $chart_labels, 'datasets' => [['label' => __('Average Duration (sec)', 'wc-prod-duration'), 'data' => $chart_data_avg, 'backgroundColor' => 'rgba(54, 162, 235, 0.6)', 'borderColor' => 'rgba(54, 162, 235, 1)', 'borderWidth' => 1]], 'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => __('Seconds', 'wc-prod-duration')]]], 'plugins' => ['legend' => ['display' => false], 'title' => ['display' => true, 'text' => __('Average Duration per Status', 'wc-prod-duration')], 'tooltip' => ['callbacks' => ['label' => 'js:wc_prod_duration_format_tooltip_label']]]], 'status_names' => $status_names_for_chart ]; // Status names for tooltip
                 wp_add_inline_script('wc-prod-duration-admin-script', 'const wc_duration_chart_data = ' . wp_json_encode($chart_js_data) . '; wc_prod_duration_render_chart(wc_duration_chart_data);', 'before');
            }
        }

        echo '<hr style="margin: 30px 0;">';

        // 2. Mevcut Durumlara Göre İş Yükü (YENİ BÖLÜM)
        echo '<h2>' . esc_html__('Current Workload by Status', 'wc-prod-duration') . '</h2>';
        $current_workload = $this->get_current_workload_by_status($stats); // $stats (ortalamalar) kullanılır

        if (empty($current_workload)) {
             WC_Status_Duration_UI::show_notice(__('No open orders found or average duration data missing.', 'wc-prod-duration'), 'info');
        } else {
             echo '<p>' . esc_html__('Estimated total workload for orders currently in each status, based on historical average durations.', 'wc-prod-duration') . '</p>';
             echo '<table class="widefat fixed striped"><thead><tr>';
             echo '<th>' . esc_html__('Status', 'wc-prod-duration') . '</th>';
             echo '<th>' . esc_html__('Current Orders', 'wc-prod-duration') . '</th>';
             echo '<th>' . esc_html__('Historical Avg. Duration', 'wc-prod-duration') . '</th>';
             echo '<th>' . esc_html__('Estimated Total Workload', 'wc-prod-duration') . '</th>';
             echo '</tr></thead><tbody>';

             $total_workload_all_statuses = 0;
             foreach ($current_workload as $status_key => $data) {
                 // Sadece seçili durum veya tüm durumlar gösterilsin
                 $status_filter_key = 'wc-' . $selected_status_filter;
                 if (empty($selected_status_filter) || $status_filter_key === $status_key) {
                     echo '<tr>';
                     echo '<td>' . esc_html(wc_get_order_status_name($status_key) ?: $status_key) . '</td>';
                     echo '<td>' . number_format_i18n($data['order_count']) . '</td>';
                     echo '<td>' . $this->format_duration($data['avg_duration']) . '</td>';
                     echo '<td><strong>' . $this->format_duration($data['total_workload']) . '</strong></td>';
                     echo '</tr>';
                     $total_workload_all_statuses += $data['total_workload'];
                 }
             }
             echo '</tbody><tfoot><tr>';
             echo '<th colspan="3" style="text-align:right;">' . esc_html__('Total Estimated Workload (All Shown Statuses):', 'wc-prod-duration') . '</th>';
             echo '<th><strong>' . $this->format_duration($total_workload_all_statuses) . '</strong></th>';
             echo '</tr></tfoot></table>';
        }


        echo '</div>'; // .wrap sonu
    }

    /**
     * Rapor verisini hesaplar (önbellekleme ile).
     * Önceki kodla aynı, sadece filtre anahtarı düzeltildi.
     */
    private function calculate_report_data($start_date, $end_date, $selected_status_filter) {
        $cache_key_base = 'report_data';
        $filters = ['start' => $start_date, 'end' => $end_date, 'status' => $selected_status_filter]; // 'wc-' olmadan
        $cache_key = $cache_key_base . '_' . md5(wp_json_encode($filters));
        $force_refresh = isset($_GET['refresh_report']);

        return $this->cache->get_cached_data(
            $cache_key,
            function($filters_arg) {
                global $wpdb;
                $where_clauses = ['1=1'];
                if (!empty($filters_arg['start'])) { $start_gmt = get_gmt_from_date($filters_arg['start'] . ' 00:00:00'); $where_clauses[] = $wpdb->prepare("a.changed_at >= %s", $start_gmt); }
                if (!empty($filters_arg['end'])) { $end_gmt = get_gmt_from_date($filters_arg['end'] . ' 23:59:59'); $where_clauses[] = $wpdb->prepare("a.changed_at <= %s", $end_gmt); }
                if (!empty($filters_arg['status'])) {
                     // SQL için 'wc-' prefix ekle
                     $status_sql_filter = 'wc-' . $filters_arg['status'];
                     $where_clauses[] = $wpdb->prepare("a.status = %s", $status_sql_filter);
                 }
                $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

                $sql = "SELECT a.status AS from_status, -- 'wc-' içerir
                               TIMESTAMPDIFF(SECOND, a.changed_at, (SELECT MIN(b.changed_at) FROM {$this->table_name} b WHERE b.order_id = a.order_id AND b.changed_at > a.changed_at)) AS duration_seconds
                        FROM {$this->table_name} a {$where_sql}
                        HAVING duration_seconds IS NOT NULL AND duration_seconds > 0";
                $results = $wpdb->get_results($sql);

                $durations = [];
                foreach ($results as $row) {
                    $status_key = $row->from_status; // 'wc-' içerir
                    if (!isset($durations[$status_key])) $durations[$status_key] = [];
                    $durations[$status_key][] = (int)$row->duration_seconds;
                }
                $stats = [];
                foreach ($durations as $status => $list) {
                    if (!empty($list)) { $count = count($list); $stats[$status] = ['avg' => round(array_sum($list) / $count), 'min' => min($list), 'max' => max($list), 'count' => $count]; }
                }
                uksort($stats, function($a, $b) { $name_a = wc_get_order_status_name($a) ?: $a; $name_b = wc_get_order_status_name($b) ?: $b; return strnatcasecmp($name_a, $name_b); });
                return ['stats' => $stats];
            },
            [$filters], $this->settings_helper->get_setting('cache_time', 3600), $force_refresh, 'report_data'
        );
    }

    /**
     * Mevcut açık siparişlerin durumlarına göre tahmini iş yükünü hesaplar.
     * @param array $historical_stats calculate_report_data'dan gelen ortalama süreleri içeren dizi.
     * @return array [status_key => ['order_count', 'avg_duration', 'total_workload']]
     */
     private function get_current_workload_by_status($historical_stats) {
         global $wpdb;
         $workload = [];

         // 1. Mevcut açık siparişlerin durumlarına göre sayısını al
         $final_statuses = $this->scheduler->get_final_statuses(); // Scheduler'dan alalım
         $final_statuses_sql = "'" . implode("','", array_map('esc_sql', $final_statuses)) . "'";
         $sql = "SELECT p.post_status, COUNT(p.ID) as order_count
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status NOT IN ({$final_statuses_sql})
                 GROUP BY p.post_status";

         $current_counts = $wpdb->get_results($sql, OBJECT_K); // post_status key olacak şekilde

         if (empty($current_counts)) {
             return [];
         }

         // 2. Her durum için iş yükünü hesapla
         foreach ($current_counts as $status_key => $data) { // status_key 'wc-' içerir
             if (isset($historical_stats[$status_key])) {
                 $avg_duration = $historical_stats[$status_key]['avg'];
                 $order_count = (int)$data->order_count;
                 $total_workload_seconds = $order_count * $avg_duration;

                 $workload[$status_key] = [
                     'order_count' => $order_count,
                     'avg_duration' => $avg_duration,
                     'total_workload' => $total_workload_seconds,
                 ];
             }
         }
         // İş yüküne göre büyükten küçüğe sırala (opsiyonel)
         uasort($workload, function($a, $b) { return $b['total_workload'] <=> $a['total_workload']; });

         return $workload;
     }


    public function render_analysis_page() {
        // Analiz sayfası render (önceki kodla aynı, Analytics sınıfı çağrılır)
        echo '<div class="wrap"><h1>' . esc_html__('Advanced Order Status Analysis', 'wc-prod-duration') . '</h1>';
        echo '<p>' . esc_html__('This section analyzes trends in order status durations, transitions between statuses, and other metrics over time.', 'wc-prod-duration') . '</p>';
        $this->analytics->render_advanced_charts();
        echo '</div>';
    }

    public function render_settings_page() {
        // Ayarlar sayfası render (önceki kodla aynı, butonlar ve form)
        echo '<div class="wrap"><h1>' . esc_html__('Plugin Settings', 'wc-prod-duration') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('wc_prod_duration_settings_group');
        do_settings_sections('wc_prod_duration_settings');
        // Performans Butonları
        echo '<h2>' . esc_html__('Performance and Data Management', 'wc-prod-duration') . '</h2><p>' . esc_html__('Tools to optimize plugin performance and manage historical data.', 'wc-prod-duration') . '</p><table class="form-table"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Cache', 'wc-prod-duration') . '</th><td><button type="button" id="clear-cache" class="button">' . esc_html__('Clear Report Cache', 'wc-prod-duration') . '</button><p class="description">' . esc_html__('Clears cached report and analysis data.', 'wc-prod-duration') . '</p><span id="cache-clear-status" class="status-message"></span></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Old Data', 'wc-prod-duration') . '</th><td><button type="button" id="clear-old-data" class="button button-danger">' . esc_html__('Delete History Data Older Than 6 Months', 'wc-prod-duration') . '</button><p class="description"><strong>' . esc_html__('Warning:', 'wc-prod-duration') . '</strong> ' . esc_html__('This action cannot be undone! Permanently deletes order status history records older than 6 months.', 'wc-prod-duration') . '</p><span id="data-clear-status" class="status-message"></span></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Settings', 'wc-prod-duration'));
        echo '</form></div>';
    }

    public function enqueue_admin_scripts($hook) {
        // Script ve stil yükleme (önceki kodla aynı, screen ID kontrolü önemli)
         $screen = get_current_screen(); if (!$screen) return;
         $plugin_pages = ['toplevel_page_wc-production-report', 'production-reports_page_wc-production-schedule', 'production-reports_page_wc-order-calendar', 'production-reports_page_wc-status-duration-analysis', 'production-reports_page_wc-prod-duration-settings', 'shop_order'];
         if (in_array($screen->id, $plugin_pages) || ($hook === 'post.php' && $screen->post_type === 'shop_order') || ($hook === 'post-new.php' && $screen->post_type === 'shop_order')) {
             wp_enqueue_style('wc-prod-duration-admin-style', WC_PROD_DURATION_URL . 'assets/css/admin-style.css', [], $this->version);
             $deps = ['jquery'];
             if (in_array($screen->id, ['toplevel_page_wc-production-report', 'production-reports_page_wc-status-duration-analysis'])) { wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true); $deps[] = 'chart-js'; }
             if ($screen->id === 'production-reports_page_wc-order-calendar') { wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js', [], '6.1.11', true); $deps[] = 'fullcalendar'; }
             wp_enqueue_script('wc-prod-duration-admin-script', WC_PROD_DURATION_URL . 'assets/js/admin-script.js', $deps, $this->version, true);
             wp_localize_script('wc-prod-duration-admin-script', 'wc_prod_duration_data', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'clear_cache_nonce' => wp_create_nonce('wc_status_duration_clear_cache'),
                'clear_data_nonce' => wp_create_nonce('wc_status_duration_clear_data'),
                'rest_nonce' => wp_create_nonce('wp_rest'), // REST API için nonce
                'rest_api_url' => rest_url('wc-prod-duration/v1/'), // Calendar için base URL
                'text' => [ 'processing' => __('Processing...', 'wc-prod-duration'), 'cache_cleared' => __('Cache cleared successfully!', 'wc-prod-duration'), 'data_deleted' => __('%d old records deleted successfully.', 'wc-prod-duration'), 'error_occurred' => __('An error occurred: %s', 'wc-prod-duration'), 'ajax_error' => __('AJAX request failed.', 'wc-prod-duration'), 'confirm_delete' => __('WARNING!\n\nThis action will PERMANENTLY delete all order status history data older than 6 months.\n\nThis cannot be undone.\n\nAre you sure?', 'wc-prod-duration'), ]
            ]);
         }
    }

    public function ajax_clear_cache() {
        // AJAX Önbellek temizleme (önceki kodla aynı)
        check_ajax_referer('wc_status_duration_clear_cache', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Insufficient permissions.', 'wc-prod-duration')], 403); }
        $this->cache->clear_all_plugin_cache();
        wp_send_json_success(['message' => __('Cache cleared.', 'wc-prod-duration')]);
    }

    public function ajax_clear_old_data() {
        // AJAX Eski veri temizleme (önceki kodla aynı)
        check_ajax_referer('wc_status_duration_clear_data', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Insufficient permissions.', 'wc-prod-duration')], 403); }
        global $wpdb; $date_threshold_gmt = gmdate('Y-m-d H:i:s', strtotime('-6 months'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE changed_at < %s", $date_threshold_gmt));
        if ($deleted === false) { wp_send_json_error(['message' => __('Database error:', 'wc-prod-duration') . ' ' . $wpdb->last_error]); }
        else { $this->cache->clear_all_plugin_cache(); wp_send_json_success(['message' => sprintf(__('%d old records deleted.', 'wc-prod-duration'), $deleted)]); }
    }

    public function handle_csv_export() {
        // CSV Export (önceki kodla aynı)
        if (!isset($_POST['export_csv']) || !current_user_can('manage_woocommerce')) return;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : ''; $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : ''; $selected_status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $_GET['refresh_report'] = true; $report_data = $this->calculate_report_data($start_date, $end_date, $selected_status); unset($_GET['refresh_report']); $stats = $report_data['stats'] ?? [];
        if (empty($stats)) wp_die(__('No data to export.', 'wc-prod-duration'));
        $filename = 'status_duration_report_' . date('Y-m-d') . '.csv'; header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=' . $filename); header('Pragma: no-cache'); header('Expires: 0');
        $output = fopen('php://output', 'w'); fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, [__('Status'), __('Average Duration (HH:MM:SS)'), __('Min Duration (HH:MM:SS)'), __('Max Duration (HH:MM:SS)'), __('Transitions Count'), __('Average Duration (Seconds)'), __('Min Duration (Seconds)'), __('Max Duration (Seconds)')]);
        foreach ($stats as $status => $data) { if (empty($selected_status) || 'wc-'.$selected_status === $status) { fputcsv($output, [wc_get_order_status_name($status) ?: $status, $this->format_duration($data['avg']), $this->format_duration($data['min']), $this->format_duration($data['max']), $data['count'], $data['avg'], $data['min'], $data['max']]); } }
        fclose($output); exit;
    }

    public function register_rest_routes() {
        // REST API Rotaları (önceki kodla aynı, calendar eklendi)
        $namespace = 'wc-prod-duration/v1';
        register_rest_route($namespace, '/report', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_report_data_api'], 'permission_callback' => [$this, 'check_api_permission'], 'args' => ['start_date' => [], 'end_date' => [], 'status' => []]]);
        register_rest_route($namespace, '/orders/(?P<id>\d+)/history', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_order_history_api'], 'permission_callback' => [$this, 'check_api_permission'], 'args' => ['id' => ['validate_callback' => function($param){ return is_numeric($param) && $param > 0; }]]]);
        register_rest_route($namespace, '/schedule', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this->scheduler, 'get_schedule_data_api'], 'permission_callback' => [$this, 'check_api_permission'], 'args' => ['status' => []]]);
        register_rest_route($namespace, '/calendar', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this->calendar, 'get_calendar_events_api'], 'permission_callback' => [$this, 'check_api_permission'], 'args' => ['start' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'], 'end' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']]]); // Calendar için
    }

    public function check_api_permission() {
        // API İzin kontrolü (önceki kodla aynı)
        return current_user_can('manage_woocommerce');
    }

    public function get_report_data_api($request) {
        // API Rapor verisi (önceki kodla aynı)
        $params = $request->get_params(); $start_date = $params['start_date'] ?? ''; $end_date = $params['end_date'] ?? ''; $selected_status = $params['status'] ?? '';
        $report_data = $this->calculate_report_data($start_date, $end_date, $selected_status); $stats = $report_data['stats'] ?? []; $formatted_stats = [];
        foreach($stats as $status => $data) { $formatted_stats[$status] = $data; $formatted_stats[$status]['formatted'] = ['avg' => $this->format_duration($data['avg']), 'min' => $this->format_duration($data['min']), 'max' => $this->format_duration($data['max'])]; $formatted_stats[$status]['status_name'] = wc_get_order_status_name($status) ?: $status; }
        if (empty($formatted_stats)) return new WP_REST_Response(['success' => false, 'message' => __('No data found', 'wc-prod-duration')], 404);
        return new WP_REST_Response(['success' => true, 'stats' => $formatted_stats], 200);
    }

    public function get_order_history_api($request) {
        // API Sipariş geçmişi (önceki kodla aynı, GMT ve ISO formatı eklendi)
         $order_id = (int) $request['id']; global $wpdb;
         $history = $this->cache->get_cached_data('order_history_api_' . $order_id, function ($order_id_arg) use ($wpdb) {
             $results = $wpdb->get_results($wpdb->prepare("SELECT id, status, changed_at, meta FROM {$this->table_name} WHERE order_id = %d ORDER BY changed_at ASC", $order_id_arg)); if (empty($results)) return null;
             $calculated_history = [];
             for ($i = 0; $i < count($results); $i++) {
                 $row = $results[$i]; $duration_sec = 0; $duration_formatted = null; $changed_at_iso = gmdate('c', strtotime($row->changed_at . ' GMT'));
                 if (isset($results[$i + 1])) { $diff = strtotime($results[$i + 1]->changed_at . ' GMT') - strtotime($row->changed_at . ' GMT'); if ($diff > 0) { $duration_sec = $diff; $duration_formatted = $this->format_duration($diff); } }
                 else { $diff = time() - strtotime($row->changed_at . ' GMT'); if ($diff > 0) { $duration_sec = $diff; $duration_formatted = $this->format_duration($diff) . ' (' . __('ongoing', 'wc-prod-duration') . ')'; } }
                 $meta_data = !empty($row->meta) ? json_decode($row->meta, true) : null;
                 $calculated_history[] = ['id' => (int) $row->id, 'status' => $row->status, 'status_name' => wc_get_order_status_name($row->status) ?: $row->status, 'changed_at_gmt' => $row->changed_at, 'changed_at_iso' => $changed_at_iso, 'duration_seconds' => $duration_sec, 'duration_formatted' => $duration_formatted, 'meta' => $meta_data];
             } return $calculated_history;
         }, [$order_id]);
         if (is_null($history)) return new WP_REST_Response(['success' => false, 'message' => __('Order history not found.', 'wc-prod-duration')], 404);
         return new WP_REST_Response(['success' => true, 'order_id' => $order_id, 'history' => $history], 200);
    }

     /**
      * Ayarlar güncellendiğinde ilgili önbellekleri temizler.
      */
     public function clear_relevant_cache_on_settings_update() {
         $this->cache->clear_cache_group('schedule');
         $this->cache->clear_cache_group('analytics');
         $this->cache->clear_cache_group('report_data');
         $this->cache->clear_cache_group('dashboard_widget');
         // Belki daha spesifik temizleme yapılabilir ama şimdilik bu gruplar yeterli.
     }

} // Class WC_Status_Duration_Report sonu
