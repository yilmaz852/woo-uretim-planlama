<?php
/**
 * Ürün Tipi ve Üretim Rotaları Sınıfı
 * 
 * Cabinet üretimi için:
 * - Shaker: Sadece Montaj
 * - SM (Semi-Custom): Boyahane + Montaj
 * - Frameless: Üretim + Boyahane + Montaj + Kalite + Sevkiyat
 * 
 * Her ürün tipi için farklı departman rotası tanımlanabilir.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Product_Routes {
    
    private static $instance = null;
    const OPTION_KEY = 'wup_product_routes';
    const CATEGORY_MAP_KEY = 'wup_category_route_map';
    const PRODUCT_META_KEY = '_wup_cabinet_type';
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin menüsüne sayfa ekle
        add_action('admin_menu', array($this, 'add_submenu'), 16);
        
        // AJAX işlemleri
        add_action('wp_ajax_wup_save_product_route', array($this, 'ajax_save_route'));
        add_action('wp_ajax_wup_delete_product_route', array($this, 'ajax_delete_route'));
        add_action('wp_ajax_wup_save_category_mapping', array($this, 'ajax_save_category_mapping'));
        
        // WooCommerce ürün sayfasına meta box ekle
        add_action('add_meta_boxes', array($this, 'add_product_meta_box'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        
        // Ürün listesine sütun ekle
        add_filter('manage_edit-product_columns', array($this, 'add_product_column'));
        add_action('manage_product_posts_custom_column', array($this, 'render_product_column'), 10, 2);
    }
    
    /**
     * Alt menü ekle
     */
    public function add_submenu() {
        add_submenu_page(
            'wup-report',
            __('Ürün Rotaları', 'woo-uretim-planlama'),
            __('Ürün Rotaları', 'woo-uretim-planlama'),
            'manage_options',
            'wup-routes',
            array($this, 'render_page')
        );
    }
    
    /**
     * Tüm rotaları al
     */
    public static function get_all() {
        $routes = get_option(self::OPTION_KEY, array());
        
        if (empty($routes)) {
            $routes = self::get_defaults();
            update_option(self::OPTION_KEY, $routes);
        }
        
        return $routes;
    }
    
    /**
     * Varsayılan cabinet rotaları
     */
    public static function get_defaults() {
        return array(
            'shaker' => array(
                'id' => 'shaker',
                'name' => __('Shaker', 'woo-uretim-planlama'),
                'description' => __('Sadece montaj gerektiren hazır çerçeveli dolaplar', 'woo-uretim-planlama'),
                'color' => '#27ae60',
                'departments' => array('montaj', 'kalite', 'sevkiyat'),
                'multiplier' => 1.0 // Süre çarpanı
            ),
            'sm' => array(
                'id' => 'sm',
                'name' => __('SM (Semi-Custom)', 'woo-uretim-planlama'),
                'description' => __('Boyama gerektiren yarı özel dolaplar', 'woo-uretim-planlama'),
                'color' => '#9b59b6',
                'departments' => array('boyahane', 'montaj', 'kalite', 'sevkiyat'),
                'multiplier' => 1.0
            ),
            'frameless' => array(
                'id' => 'frameless',
                'name' => __('Frameless', 'woo-uretim-planlama'),
                'description' => __('Sıfırdan üretilen çerçevesiz modern dolaplar', 'woo-uretim-planlama'),
                'color' => '#e74c3c',
                'departments' => array('uretim', 'boyahane', 'montaj', 'kalite', 'sevkiyat'),
                'multiplier' => 1.5 // %50 daha uzun sürer
            ),
            'custom' => array(
                'id' => 'custom',
                'name' => __('Özel Üretim', 'woo-uretim-planlama'),
                'description' => __('Tamamen özel tasarımlı projeler', 'woo-uretim-planlama'),
                'color' => '#f39c12',
                'departments' => array('operasyon', 'uretim', 'boyahane', 'montaj', 'kalite', 'sevkiyat'),
                'multiplier' => 2.0 // 2 kat daha uzun sürer
            )
        );
    }
    
    /**
     * Tek rota al
     */
    public static function get($id) {
        $routes = self::get_all();
        return isset($routes[$id]) ? $routes[$id] : null;
    }
    
    /**
     * Rota kaydet
     */
    public static function save($route) {
        $routes = self::get_all();
        
        $id = sanitize_key($route['id']);
        
        $routes[$id] = array(
            'id' => $id,
            'name' => sanitize_text_field($route['name']),
            'description' => sanitize_textarea_field($route['description']),
            'color' => sanitize_hex_color($route['color']),
            'departments' => isset($route['departments']) ? array_map('sanitize_key', (array)$route['departments']) : array(),
            'multiplier' => isset($route['multiplier']) ? max(0.1, floatval($route['multiplier'])) : 1.0
        );
        
        update_option(self::OPTION_KEY, $routes);
        WUP_Cache::clear_all();
        
        return $routes[$id];
    }
    
    /**
     * Rota sil
     */
    public static function delete($id) {
        $routes = self::get_all();
        
        if (isset($routes[$id])) {
            unset($routes[$id]);
            update_option(self::OPTION_KEY, $routes);
            WUP_Cache::clear_all();
            return true;
        }
        
        return false;
    }
    
    /**
     * Kategori-Rota eşleştirmelerini al
     */
    public static function get_category_mappings() {
        return get_option(self::CATEGORY_MAP_KEY, array());
    }
    
    /**
     * Kategori-Rota eşleştirmesini kaydet
     */
    public static function save_category_mapping($category_id, $route_id) {
        $mappings = self::get_category_mappings();
        
        if (empty($route_id)) {
            unset($mappings[$category_id]);
        } else {
            $mappings[$category_id] = sanitize_key($route_id);
        }
        
        update_option(self::CATEGORY_MAP_KEY, $mappings);
        WUP_Cache::clear_all();
        
        return $mappings;
    }
    
    /**
     * Ürün için cabinet tipini al
     * Önce ürün meta'ya bakar, yoksa kategorisine bakar
     */
    public static function get_product_type($product_id) {
        // Önce ürün meta'ya bak
        $meta_type = get_post_meta($product_id, self::PRODUCT_META_KEY, true);
        
        if ($meta_type) {
            return $meta_type;
        }
        
        // Meta yoksa kategoriden belirle
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return '';
        }
        
        $category_ids = $product->get_category_ids();
        $mappings = self::get_category_mappings();
        
        // Kategorileri kontrol et
        foreach ($category_ids as $cat_id) {
            if (isset($mappings[$cat_id])) {
                return $mappings[$cat_id];
            }
            
            // Alt kategori ise parent'ı kontrol et
            $parent_id = wp_get_term_taxonomy_parent_id($cat_id, 'product_cat');
            while ($parent_id) {
                if (isset($mappings[$parent_id])) {
                    return $mappings[$parent_id];
                }
                $parent_id = wp_get_term_taxonomy_parent_id($parent_id, 'product_cat');
            }
        }
        
        return '';
    }
    
    /**
     * Sipariş için tüm cabinet tiplerini al
     */
    public static function get_order_types($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return array();
        }
        
        $types = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $type = self::get_product_type($product_id);
            
            if ($type && !in_array($type, $types)) {
                $types[] = $type;
            }
        }
        
        return $types;
    }
    
    /**
     * Sipariş için toplam üretim süresini hesapla
     * Birden fazla cabinet tipi varsa en uzun rotayı kullanır
     */
    public static function calculate_order_duration($order) {
        $types = self::get_order_types($order);
        
        if (empty($types)) {
            // Tip belirlenmemişse varsayılan hesaplama
            return self::get_default_duration();
        }
        
        $max_duration = 0;
        $all_departments = array();
        
        foreach ($types as $type_id) {
            $route = self::get($type_id);
            
            if (!$route) {
                continue;
            }
            
            $route_duration = 0;
            
            foreach ($route['departments'] as $dept_id) {
                $dept_duration = WUP_Departments::get_duration($dept_id);
                $route_duration += $dept_duration;
                
                if (!in_array($dept_id, $all_departments)) {
                    $all_departments[] = $dept_id;
                }
            }
            
            // Süre çarpanını uygula
            $route_duration = $route_duration * $route['multiplier'];
            
            if ($route_duration > $max_duration) {
                $max_duration = $route_duration;
            }
        }
        
        return array(
            'seconds' => round($max_duration),
            'departments' => $all_departments,
            'types' => $types
        );
    }
    
    /**
     * Varsayılan süre hesaplama (tip belirlenmemişse)
     */
    private static function get_default_duration() {
        $departments = WUP_Departments::get_all();
        $total = 0;
        $dept_list = array();
        
        foreach ($departments as $dept) {
            if ($dept['base_duration'] > 0) {
                $total += WUP_Departments::get_duration($dept['id']);
                $dept_list[] = $dept['id'];
            }
        }
        
        return array(
            'seconds' => $total,
            'departments' => $dept_list,
            'types' => array()
        );
    }
    
    /**
     * Rota için toplam süreyi hesapla (dakika cinsinden)
     */
    public static function get_route_duration($route_id) {
        $route = self::get($route_id);
        
        if (!$route) {
            return 0;
        }
        
        $total_minutes = 0;
        
        foreach ($route['departments'] as $dept_id) {
            $dept = WUP_Departments::get($dept_id);
            if ($dept) {
                $total_minutes += $dept['base_duration'];
            }
        }
        
        return round($total_minutes * $route['multiplier']);
    }
    
    /**
     * Rota için tek işçi süresini hesapla (dakika cinsinden)
     */
    public static function get_route_single_worker_duration($route_id) {
        $route = self::get($route_id);
        
        if (!$route) {
            return 0;
        }
        
        $total_minutes = 0;
        
        foreach ($route['departments'] as $dept_id) {
            $single_worker = WUP_Departments::get_single_worker_duration($dept_id);
            $total_minutes += $single_worker;
        }
        
        return round($total_minutes * $route['multiplier']);
    }
    
    /**
     * WooCommerce ürün meta box
     */
    public function add_product_meta_box() {
        add_meta_box(
            'wup_cabinet_type',
            __('Cabinet Tipi (Üretim Planlama)', 'woo-uretim-planlama'),
            array($this, 'render_product_meta_box'),
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Ürün meta box render
     */
    public function render_product_meta_box($post) {
        // Ürün meta'daki değer
        $meta_type = get_post_meta($post->ID, self::PRODUCT_META_KEY, true);
        // Etkili tip (kategori dahil)
        $effective_type = self::get_product_type($post->ID);
        $routes = self::get_all();
        
        wp_nonce_field('wup_product_type_nonce', 'wup_product_type_nonce');
        
        // Kategoriden gelen tip varsa bilgi göster
        if (empty($meta_type) && $effective_type) {
            $route = self::get($effective_type);
            if ($route) {
                echo '<div style="background:#e8f5e9;padding:8px;margin-bottom:10px;border-radius:4px;border-left:3px solid #27ae60;">';
                echo '<strong>' . esc_html__('Kategoriden:', 'woo-uretim-planlama') . '</strong> ';
                echo '<span style="color:' . esc_attr($route['color']) . ';font-weight:bold;">' . esc_html($route['name']) . '</span>';
                echo '</div>';
            }
        }
        
        echo '<p class="description">' . esc_html__('Manuel seçim yapabilir veya kategoriden otomatik belirlenebilir.', 'woo-uretim-planlama') . '</p>';
        
        echo '<select name="wup_cabinet_type" id="wup_cabinet_type" style="width:100%;">';
        echo '<option value="">' . esc_html__('-- Kategoriden Otomatik --', 'woo-uretim-planlama') . '</option>';
        
        foreach ($routes as $route) {
            $selected = ($meta_type === $route['id']) ? 'selected' : '';
            $duration = self::get_route_duration($route['id']);
            $hours = round($duration / 60, 1);
            
            echo '<option value="' . esc_attr($route['id']) . '" ' . $selected . '>';
            echo esc_html($route['name']) . ' (' . esc_html($hours) . ' saat)';
            echo '</option>';
        }
        
        echo '</select>';
        
        if ($effective_type) {
            $route = self::get($effective_type);
            if ($route) {
                echo '<p style="margin-top:10px;"><strong>' . esc_html__('Departman Akışı:', 'woo-uretim-planlama') . '</strong></p>';
                echo '<p style="font-size:0.9em;">';
                $dept_names = array();
                foreach ($route['departments'] as $dept_id) {
                    $dept = WUP_Departments::get($dept_id);
                    if ($dept) {
                        $dept_names[] = '<span style="color:' . esc_attr($dept['color']) . ';">' . esc_html($dept['name']) . '</span>';
                    }
                }
                echo implode(' → ', $dept_names);
                echo '</p>';
            }
        }
    }
    
    /**
     * Ürün meta kaydet
     */
    public function save_product_meta($post_id) {
        if (!isset($_POST['wup_product_type_nonce']) || !wp_verify_nonce($_POST['wup_product_type_nonce'], 'wup_product_type_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $type = isset($_POST['wup_cabinet_type']) ? sanitize_key($_POST['wup_cabinet_type']) : '';
        
        if ($type) {
            update_post_meta($post_id, self::PRODUCT_META_KEY, $type);
        } else {
            delete_post_meta($post_id, self::PRODUCT_META_KEY);
        }
    }
    
    /**
     * Ürün listesine sütun ekle
     */
    public function add_product_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'sku') {
                $new_columns['cabinet_type'] = __('Cabinet Tipi', 'woo-uretim-planlama');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Ürün sütun render
     */
    public function render_product_column($column, $post_id) {
        if ($column !== 'cabinet_type') {
            return;
        }
        
        $type_id = self::get_product_type($post_id);
        
        if ($type_id) {
            $route = self::get($type_id);
            if ($route) {
                echo '<span style="padding:2px 8px;border-radius:3px;background:' . esc_attr($route['color']) . ';color:#fff;font-size:0.85em;">';
                echo esc_html($route['name']);
                echo '</span>';
            } else {
                echo '-';
            }
        } else {
            echo '<span style="color:#999;">-</span>';
        }
    }
    
    /**
     * AJAX: Rota kaydet
     */
    public function ajax_save_route() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $route = array(
            'id' => isset($_POST['route_id']) ? sanitize_key($_POST['route_id']) : '',
            'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'color' => isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '#3498db',
            'departments' => isset($_POST['departments']) ? (array)$_POST['departments'] : array(),
            'multiplier' => isset($_POST['multiplier']) ? floatval($_POST['multiplier']) : 1.0
        );
        
        if (empty($route['id']) || empty($route['name'])) {
            wp_send_json_error(array('message' => __('Rota ID ve adı zorunludur.', 'woo-uretim-planlama')));
        }
        
        $saved = self::save($route);
        
        wp_send_json_success(array(
            'message' => __('Rota kaydedildi.', 'woo-uretim-planlama'),
            'route' => $saved
        ));
    }
    
    /**
     * AJAX: Rota sil
     */
    public function ajax_delete_route() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $id = isset($_POST['route_id']) ? sanitize_key($_POST['route_id']) : '';
        
        if (empty($id)) {
            wp_send_json_error(array('message' => __('Rota ID gerekli.', 'woo-uretim-planlama')));
        }
        
        if (self::delete($id)) {
            wp_send_json_success(array('message' => __('Rota silindi.', 'woo-uretim-planlama')));
        } else {
            wp_send_json_error(array('message' => __('Rota bulunamadı.', 'woo-uretim-planlama')));
        }
    }
    
    /**
     * AJAX: Kategori eşleştirmesi kaydet
     */
    public function ajax_save_category_mapping() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $mappings = isset($_POST['mappings']) ? (array)$_POST['mappings'] : array();
        
        // Tüm eşleştirmeleri temizle ve yeniden kaydet
        delete_option(self::CATEGORY_MAP_KEY);
        
        $saved_mappings = array();
        foreach ($mappings as $cat_id => $route_id) {
            $cat_id = absint($cat_id);
            $route_id = sanitize_key($route_id);
            
            if ($cat_id && $route_id) {
                $saved_mappings[$cat_id] = $route_id;
            }
        }
        
        update_option(self::CATEGORY_MAP_KEY, $saved_mappings);
        WUP_Cache::clear_all();
        
        wp_send_json_success(array(
            'message' => __('Kategori eşleştirmeleri kaydedildi.', 'woo-uretim-planlama'),
            'count' => count($saved_mappings)
        ));
    }
    
    /**
     * Sayfa render
     */
    public function render_page() {
        $routes = self::get_all();
        $departments = WUP_Departments::get_all();
        
        WUP_UI::page_header(
            __('Ürün Rotaları (Cabinet Tipleri)', 'woo-uretim-planlama'),
            __('Cabinet tiplerine göre üretim akışlarını tanımlayın. Her tip farklı departman rotası izleyebilir.', 'woo-uretim-planlama')
        );
        
        // Nonce for AJAX
        wp_nonce_field('wup_nonce', 'wup_route_nonce');
        
        echo '<div class="wup-routes-container" style="display:flex; gap:30px; flex-wrap:wrap;">';
        
        // Sol panel: Rota listesi
        echo '<div class="wup-route-list" style="flex:1; min-width:500px;">';
        echo '<h2>' . esc_html__('Cabinet Tipleri', 'woo-uretim-planlama') . '</h2>';
        
        if (empty($routes)) {
            WUP_UI::notice(__('Henüz üretim rotası oluşturulmamış.', 'woo-uretim-planlama'), 'info');
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width:25%;">' . esc_html__('Tip', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Departman Akışı', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('Çarpan', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('İşlem', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($routes as $route) {
                $total_duration = self::get_route_duration($route['id']);
                $hours = round($total_duration / 60, 1);
                
                // Departman isimlerini al
                $dept_badges = array();
                foreach ($route['departments'] as $dept_id) {
                    $dept = WUP_Departments::get($dept_id);
                    if ($dept) {
                        $dept_badges[] = '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:' . esc_attr($dept['color']) . ';color:#fff;font-size:0.8em;margin:1px;">' . esc_html($dept['name']) . '</span>';
                    }
                }
                
                echo '<tr data-route-id="' . esc_attr($route['id']) . '">';
                echo '<td>';
                echo '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:' . esc_attr($route['color']) . ';margin-right:8px;"></span>';
                echo '<strong>' . esc_html($route['name']) . '</strong>';
                if (!empty($route['description'])) {
                    echo '<br><small style="color:#666;">' . esc_html($route['description']) . '</small>';
                }
                echo '</td>';
                echo '<td>' . implode(' → ', $dept_badges) . '</td>';
                echo '<td>' . esc_html($hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
                echo '<td>×' . esc_html($route['multiplier']) . '</td>';
                echo '<td>';
                echo '<button type="button" class="button button-small wup-edit-route" data-route=\'' . esc_attr(wp_json_encode($route)) . '\'>' . esc_html__('Düzenle', 'woo-uretim-planlama') . '</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
        
        // Sağ panel: Rota formu
        echo '<div class="wup-route-form" style="flex:0 0 400px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<h2 id="wup-route-form-title">' . esc_html__('Yeni Tip Ekle', 'woo-uretim-planlama') . '</h2>';
        
        echo '<form id="wup-route-form">';
        echo '<input type="hidden" name="route_id" id="route_id" value="">';
        
        echo '<p>';
        echo '<label for="route_name"><strong>' . esc_html__('Tip Adı', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="text" name="name" id="route_name" class="regular-text" placeholder="Örn: Shaker, Frameless" required>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="route_color"><strong>' . esc_html__('Renk', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="color" name="color" id="route_color" value="#3498db">';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="route_multiplier"><strong>' . esc_html__('Süre Çarpanı', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="number" name="multiplier" id="route_multiplier" step="0.1" min="0.1" max="10" value="1.0" class="small-text">';
        echo '<span class="description"> ' . esc_html__('Örn: 1.5 = %50 daha uzun', 'woo-uretim-planlama') . '</span>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="route_description"><strong>' . esc_html__('Açıklama', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<textarea name="description" id="route_description" rows="2" class="large-text"></textarea>';
        echo '</p>';
        
        echo '<p>';
        echo '<label><strong>' . esc_html__('Departman Akışı (Sıralı)', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<span class="description">' . esc_html__('Bu tipin geçeceği departmanları sırasıyla seçin.', 'woo-uretim-planlama') . '</span><br><br>';
        
        $order = 1;
        foreach ($departments as $dept) {
            echo '<label style="display:block; margin-bottom:8px;">';
            echo '<input type="checkbox" name="departments[]" value="' . esc_attr($dept['id']) . '" class="wup-dept-checkbox"> ';
            echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr($dept['color']) . ';margin:0 5px;"></span>';
            echo esc_html($dept['name']);
            echo ' <small style="color:#666;">(' . esc_html($dept['base_duration']) . ' dk)</small>';
            echo '</label>';
        }
        echo '</p>';
        
        echo '<p style="margin-top:20px;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Kaydet', 'woo-uretim-planlama') . '</button> ';
        echo '<button type="button" id="wup-reset-route-form" class="button">' . esc_html__('Temizle', 'woo-uretim-planlama') . '</button> ';
        echo '<button type="button" id="wup-delete-route" class="button button-link-delete" style="display:none;">' . esc_html__('Sil', 'woo-uretim-planlama') . '</button>';
        echo '</p>';
        
        echo '</form>';
        echo '</div>';
        
        echo '</div>'; // container
        
        // Karşılaştırma Tablosu
        echo '<hr style="margin:40px 0;">';
        echo '<h2>' . esc_html__('Tip Karşılaştırması', 'woo-uretim-planlama') . '</h2>';
        echo '<p class="description">' . esc_html__('Farklı cabinet tiplerinin üretim sürelerini karşılaştırın.', 'woo-uretim-planlama') . '</p>';
        
        echo '<table class="widefat fixed striped" style="max-width:700px; margin-top:15px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Tip', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Departman Sayısı', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Temel Süre', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Çarpan', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Toplam Süre', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Tek İşçi Süresi', 'woo-uretim-planlama') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($routes as $route) {
            $base_duration = 0;
            foreach ($route['departments'] as $dept_id) {
                $dept = WUP_Departments::get($dept_id);
                if ($dept) {
                    $base_duration += $dept['base_duration'];
                }
            }
            
            $total_duration = self::get_route_duration($route['id']);
            $single_worker = self::get_route_single_worker_duration($route['id']);
            
            echo '<tr>';
            echo '<td>';
            echo '<span style="display:inline-block;padding:3px 8px;border-radius:3px;background:' . esc_attr($route['color']) . ';color:#fff;">';
            echo esc_html($route['name']);
            echo '</span>';
            echo '</td>';
            echo '<td>' . count($route['departments']) . '</td>';
            echo '<td>' . round($base_duration / 60, 1) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '<td>×' . esc_html($route['multiplier']) . '</td>';
            echo '<td><strong>' . round($total_duration / 60, 1) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</strong></td>';
            echo '<td style="color:#666;">' . round($single_worker / 60, 1) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Kategori Eşleştirme Bölümü
        echo '<hr style="margin:40px 0;">';
        echo '<h2>' . esc_html__('Ürün Kategorisi Eşleştirme', 'woo-uretim-planlama') . '</h2>';
        echo '<p class="description">' . esc_html__('WooCommerce ürün kategorilerini cabinet tiplerine bağlayın. Böylece ürünleri tek tek işaretlemenize gerek kalmaz.', 'woo-uretim-planlama') . '</p>';
        
        // WooCommerce kategorilerini al
        $product_categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        $category_mappings = self::get_category_mappings();
        
        if (!is_wp_error($product_categories) && !empty($product_categories)) {
            echo '<form id="wup-category-mapping-form">';
            echo '<table class="widefat fixed striped" style="max-width:700px; margin-top:15px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Ürün Kategorisi', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:200px;">' . esc_html__('Cabinet Tipi', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('Ürün Sayısı', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($product_categories as $category) {
                $current_route = isset($category_mappings[$category->term_id]) ? $category_mappings[$category->term_id] : '';
                $indent = '';
                
                // Alt kategori ise girintili göster
                if ($category->parent > 0) {
                    $indent = '— ';
                }
                
                echo '<tr>';
                echo '<td>';
                echo esc_html($indent . $category->name);
                if ($category->parent > 0) {
                    $parent = get_term($category->parent, 'product_cat');
                    if ($parent && !is_wp_error($parent)) {
                        echo ' <small style="color:#666;">(' . esc_html($parent->name) . ' altı)</small>';
                    }
                }
                echo '</td>';
                echo '<td>';
                echo '<select name="category_mapping[' . esc_attr($category->term_id) . ']" class="wup-category-route-select">';
                echo '<option value="">' . esc_html__('-- Eşleştirme Yok --', 'woo-uretim-planlama') . '</option>';
                
                foreach ($routes as $route) {
                    $selected = ($current_route === $route['id']) ? 'selected' : '';
                    echo '<option value="' . esc_attr($route['id']) . '" ' . $selected . ' style="color:' . esc_attr($route['color']) . ';">';
                    echo esc_html($route['name']);
                    echo '</option>';
                }
                
                echo '</select>';
                echo '</td>';
                echo '<td>' . esc_html($category->count) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '<p style="margin-top:15px;">';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Eşleştirmeleri Kaydet', 'woo-uretim-planlama') . '</button>';
            echo '</p>';
            echo '</form>';
        } else {
            WUP_UI::notice(__('Henüz ürün kategorisi oluşturulmamış. WooCommerce → Ürünler → Kategoriler bölümünden kategori ekleyin.', 'woo-uretim-planlama'), 'warning');
        }
        
        // JavaScript
        $this->render_scripts();
        
        WUP_UI::page_footer();
    }
    
    /**
     * JavaScript kodları
     */
    private function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var nonce = $('#wup_route_nonce').val();
            
            // Form gönderimi
            $('#wup-route-form').on('submit', function(e) {
                e.preventDefault();
                
                var departments = [];
                $('input[name="departments[]"]:checked').each(function() {
                    departments.push($(this).val());
                });
                
                var formData = {
                    action: 'wup_save_product_route',
                    nonce: nonce,
                    route_id: $('#route_id').val() || $('#route_name').val().toLowerCase().replace(/[^a-z0-9]/g, '_'),
                    name: $('#route_name').val(),
                    color: $('#route_color').val(),
                    multiplier: $('#route_multiplier').val(),
                    description: $('#route_description').val(),
                    departments: departments
                };
                
                $.post(ajaxurl, formData, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Bir hata oluştu.', 'woo-uretim-planlama')); ?>');
                    }
                });
            });
            
            // Düzenle butonu
            $('.wup-edit-route').on('click', function() {
                var route = $(this).data('route');
                
                $('#wup-route-form-title').text('<?php echo esc_js(__('Tip Düzenle', 'woo-uretim-planlama')); ?>');
                $('#route_id').val(route.id);
                $('#route_name').val(route.name);
                $('#route_color').val(route.color);
                $('#route_multiplier').val(route.multiplier);
                $('#route_description').val(route.description || '');
                
                // Departmanları işaretle
                $('input[name="departments[]"]').prop('checked', false);
                if (route.departments) {
                    route.departments.forEach(function(dept) {
                        $('input[name="departments[]"][value="' + dept + '"]').prop('checked', true);
                    });
                }
                
                $('#wup-delete-route').show();
                
                $('html, body').animate({
                    scrollTop: $('#wup-route-form').offset().top - 50
                }, 300);
            });
            
            // Formu temizle
            $('#wup-reset-route-form').on('click', function() {
                $('#wup-route-form-title').text('<?php echo esc_js(__('Yeni Tip Ekle', 'woo-uretim-planlama')); ?>');
                $('#route_id').val('');
                $('#route_name').val('');
                $('#route_color').val('#3498db');
                $('#route_multiplier').val(1.0);
                $('#route_description').val('');
                $('input[name="departments[]"]').prop('checked', false);
                $('#wup-delete-route').hide();
            });
            
            // Rota sil
            $('#wup-delete-route').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Bu rotayı silmek istediğinizden emin misiniz?', 'woo-uretim-planlama')); ?>')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'wup_delete_product_route',
                    nonce: nonce,
                    route_id: $('#route_id').val()
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Bir hata oluştu.', 'woo-uretim-planlama')); ?>');
                    }
                });
            });
            
            // Kategori eşleştirme form gönderimi
            $('#wup-category-mapping-form').on('submit', function(e) {
                e.preventDefault();
                
                var mappings = {};
                $('select.wup-category-route-select').each(function() {
                    var catId = $(this).attr('name').match(/\[(\d+)\]/)[1];
                    var routeId = $(this).val();
                    if (routeId) {
                        mappings[catId] = routeId;
                    }
                });
                
                $.post(ajaxurl, {
                    action: 'wup_save_category_mapping',
                    nonce: nonce,
                    mappings: mappings
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Bir hata oluştu.', 'woo-uretim-planlama')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
