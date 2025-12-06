<?php
/**
 * Plugin Name: WooCommerce Üretim Planlama
 * Plugin URI: https://github.com/yilmaz852/woo-uretim-planlama
 * Description: WooCommerce siparişleri için üretim planlama, takvim, analiz ve raporlama eklentisi.
 * Version: 1.0.0
 * Author: Yilmaz
 * Author URI: https://github.com/yilmaz852
 * Text Domain: woo-uretim-planlama
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.7
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Sabitleri tanımla
define('WUP_VERSION', '1.0.0');
define('WUP_PLUGIN_FILE', __FILE__);
define('WUP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WUP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * WooCommerce aktif mi kontrol et
 */
function wup_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            echo esc_html__('WooCommerce Üretim Planlama eklentisi için WooCommerce gereklidir.', 'woo-uretim-planlama');
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Eklentiyi başlat
 */
function wup_init() {
    if (!wup_check_woocommerce()) {
        return;
    }
    
    // Sınıfları yükle
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-cache.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-settings.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-ui.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-departments.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-product-routes.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-dashboard.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-analytics.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-scheduler.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-calendar.php';
    require_once WUP_PLUGIN_PATH . 'includes/class-wup-main.php';
    
    // Departman yönetimini başlat
    WUP_Departments::get_instance();
    
    // Ürün rotaları yönetimini başlat
    WUP_Product_Routes::get_instance();
    
    // Ana sınıfı başlat
    WUP_Main::get_instance();
}
add_action('plugins_loaded', 'wup_init', 10);

/**
 * Aktivasyon hook
 */
function wup_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wup_status_history';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(50) NOT NULL,
        changed_at DATETIME NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT 0,
        note TEXT,
        INDEX idx_order_id (order_id),
        INDEX idx_status (status),
        INDEX idx_changed_at (changed_at)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    
    // Varsayılan ayarları kaydet
    $default_settings = array(
        'personnel_count' => 1,
        'daily_hours' => 8,
        'working_days' => array('1', '2', '3', '4', '5'),
        'status_durations' => array(),
        'notifications_enabled' => 0,
        'notification_threshold' => 24,
        'notification_email' => get_option('admin_email'),
        'cache_duration' => 3600
    );
    
    if (!get_option('wup_settings')) {
        update_option('wup_settings', $default_settings);
    }
    
    update_option('wup_version', WUP_VERSION);
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wup_activate');

/**
 * Deaktivasyon hook
 */
function wup_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wup_deactivate');

/**
 * Dil dosyalarını yükle
 */
function wup_load_textdomain() {
    load_plugin_textdomain('woo-uretim-planlama', false, dirname(WUP_PLUGIN_BASENAME) . '/languages');
}
add_action('init', 'wup_load_textdomain');

/**
 * HPOS uyumluluğu
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
