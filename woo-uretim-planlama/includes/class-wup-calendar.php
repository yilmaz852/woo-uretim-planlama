<?php
/**
 * Takvim sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Calendar {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // REST API endpoint
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * REST API routes
     */
    public function register_routes() {
        register_rest_route('wup/v1', '/calendar', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_events'),
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ));
    }
    
    /**
     * Takvim olaylarını al (REST API)
     */
    public function get_events($request) {
        $start = $request->get_param('start');
        $end = $request->get_param('end');
        
        $scheduler = WUP_Scheduler::get_instance();
        $schedule = $scheduler->get_schedule();
        
        $events = array();
        
        if (!empty($schedule['orders'])) {
            $start_ts = $start ? strtotime($start) : null;
            $end_ts = $end ? strtotime($end) : null;
            
            foreach ($schedule['orders'] as $item) {
                $completion = $item['completion_date'];
                
                if (!$completion) {
                    continue;
                }
                
                // Tarih aralığı kontrolü
                if ($start_ts && $end_ts && ($completion < $start_ts || $completion >= $end_ts)) {
                    continue;
                }
                
                $order = $item['order'];
                $order_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                $status_name = wc_get_order_status_name($order->get_status());
                
                $events[] = array(
                    'id' => $order->get_id(),
                    'title' => sprintf('#%s - %s', $order->get_id(), $item['customer']),
                    'start' => gmdate('c', $completion),
                    'url' => $order_url,
                    'allDay' => true,
                    'backgroundColor' => $this->get_status_color($order->get_status()),
                    'extendedProps' => array(
                        'status' => $order->get_status(),
                        'status_name' => $status_name,
                        'remaining' => $item['remaining_formatted']
                    )
                );
            }
        }
        
        return new WP_REST_Response($events, 200);
    }
    
    /**
     * Durum rengini al
     */
    private function get_status_color($status) {
        $colors = array(
            'pending' => '#f6c23e',
            'processing' => '#4e73df',
            'on-hold' => '#858796',
            'completed' => '#1cc88a',
            'cancelled' => '#e74a3b',
            'refunded' => '#36b9cc',
            'failed' => '#e74a3b'
        );
        
        return isset($colors[$status]) ? $colors[$status] : '#858796';
    }
    
    /**
     * Takvim sayfasını render et
     */
    public function render_page() {
        WUP_UI::page_header(
            __('Sipariş Takvimi', 'woo-uretim-planlama'),
            __('Siparişlerin tahmini tamamlanma tarihlerine göre takvim görünümü.', 'woo-uretim-planlama')
        );
        
        echo '<div id="wup-calendar-container">';
        echo '<div id="wup-calendar"></div>';
        echo '</div>';
        
        // FullCalendar konfigürasyonu
        $config = array(
            'apiUrl' => rest_url('wup/v1/calendar'),
            'nonce' => wp_create_nonce('wp_rest'),
            'locale' => substr(get_locale(), 0, 2),
            'firstDay' => intval(get_option('start_of_week', 1)),
            'texts' => array(
                'today' => __('Bugün', 'woo-uretim-planlama'),
                'month' => __('Ay', 'woo-uretim-planlama'),
                'week' => __('Hafta', 'woo-uretim-planlama'),
                'day' => __('Gün', 'woo-uretim-planlama'),
                'list' => __('Liste', 'woo-uretim-planlama'),
                'status' => __('Durum:', 'woo-uretim-planlama'),
                'remaining' => __('Kalan:', 'woo-uretim-planlama'),
                'error' => __('Takvim verileri yüklenemedi!', 'woo-uretim-planlama')
            )
        );
        
        echo '<script>var wupCalendarConfig = ' . wp_json_encode($config) . ';</script>';
        
        WUP_UI::page_footer();
    }
}
