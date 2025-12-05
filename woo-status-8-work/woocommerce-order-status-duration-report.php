<?php
/*
Plugin Name: WooCommerce Üretim ve Durum Süre Raporu
Description: WooCommerce siparişlerinin her statüde ne kadar kaldığını analiz eder, raporlar, filtreler, üretim programı ve takvim oluşturur, CSV olarak dışa aktarır.
Version: 3.1.0
Author: Yilmaz
Text Domain: wc-prod-duration
Domain Path: /languages
Requires at least: 5.8
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 8.7
*/

// Doğrudan erişimi engelle
if (!defined('ABSPATH')) exit;

// WooCommerce kontrolü (daha güvenilir)
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . esc_html__('WooCommerce Production and Status Duration Report plugin requires WooCommerce to be installed and active.', 'wc-prod-duration') . '</p></div>';
        });
        // Eklentinin geri kalanının yüklenmesini durdurmak için bir yol (örn. bir flag ayarlamak)
        define('WC_PROD_DURATION_WC_MISSING', true);
    } else {
         // WooCommerce varsa eklentiyi başlat
         if (!defined('WC_PROD_DURATION_WC_MISSING')) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-wc-status-duration-report.php';
            WC_Status_Duration_Report::get_instance();
         }
    }
}, 0); // WooCommerce'den önce çalışması için öncelik 0

// Sabitleri Tanımla (Eğer WC varsa tanımlanacak şekilde ayarlanabilir)
if (!defined('WC_PROD_DURATION_VERSION')) {
    define('WC_PROD_DURATION_VERSION', '3.1.0');
    define('WC_PROD_DURATION_PATH', plugin_dir_path(__FILE__));
    define('WC_PROD_DURATION_URL', plugin_dir_url(__FILE__));
    define('WC_PROD_DURATION_FILE', __FILE__); // Ana dosya yolu
}

// Ana sınıfı sadece WooCommerce aktifse yükle ve başlat
// Bu kısım yukarıdaki action içine taşındı.
