<?php
/**
 * UI (Kullanıcı Arayüzü) yardımcıları için sınıf
 */

if (!defined('ABSPATH')) exit;

class WC_Status_Duration_UI {

    /**
     * Sipariş durumları için bir dropdown filtresi oluşturur.
     * @param string $selected_status Mevcut seçili durum anahtarı (örn. 'processing').
     */
    public static function render_status_filter_dropdown($selected_status = '') {
        $statuses = wc_get_order_statuses(); // 'wc-' prefixli key'ler döner
        $field_name = 'status';
        $field_id = 'status_filter';

        echo '<label for="' . esc_attr($field_id) . '" class="screen-reader-text">' . esc_html__('Filter by status', 'wc-prod-duration') . '</label>';
        echo '<select name="' . esc_attr($field_name) . '" id="' . esc_attr($field_id) . '">';
        echo '<option value="">' . esc_html__('All Statuses', 'wc-prod-duration') . '</option>';

        // Gelen $selected_status 'wc-' içermiyorsa ekle
        if (!empty($selected_status) && strpos($selected_status, 'wc-') !== 0) {
            $selected_status_key = 'wc-' . $selected_status;
        } else {
            $selected_status_key = $selected_status;
        }


        foreach ($statuses as $key => $label) {
            // Değer olarak 'wc-' olmadan gönderelim (raporlama/filtreleme tutarlılığı için)
            $value = str_replace('wc-', '', $key);
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_status_key, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Sayfalama linklerini oluşturur (WordPress'in paginate_links fonksiyonunu kullanır).
     * @param int $total_items Toplam öğe sayısı.
     * @param int $per_page Sayfa başına öğe sayısı.
     */
    public static function render_pagination($total_items, $per_page = 20) {
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_pages = ceil($total_items / $per_page);

        if ($total_pages <= 1) {
            return; // Sayfalama gerekmiyorsa gösterme
        }

        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items, 'wc-prod-duration'), number_format_i18n($total_items)) . '</span>';

        $page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%'), // Temel URL
            'format' => '', // Sayfa numarası formatı (?paged=%#% yerine base'de belirtildi)
            'prev_text' => __('&laquo;', 'default'), // Önceki sayfa metni (WordPress çevirisi)
            'next_text' => __('&raquo;', 'default'), // Sonraki sayfa metni
            'total' => $total_pages,
            'current' => $current_page,
            'add_args' => false, // Mevcut query string'leri koru
            'type' => 'plain', // 'list', 'array' veya 'plain' olabilir
        ]);

        if ($page_links) {
            echo '<span class="pagination-links">' . $page_links . '</span>';
        }

        echo '</div>';
    }

    /**
     * Yönetici panelinde bir bildirim mesajı gösterir.
     * @param string $message Gösterilecek mesaj.
     * @param string $type Bildirim türü ('info', 'success', 'warning', 'error').
     * @param bool $is_dismissible Kapatılabilir mi?
     */
    public static function show_notice($message, $type = 'info', $is_dismissible = true) {
        $class = 'notice notice-' . sanitize_key($type);
        if ($is_dismissible) {
            $class .= ' is-dismissible';
        }
        echo '<div class="' . esc_attr($class) . '">';
        echo '<p>' . wp_kses_post($message) . '</p>'; // Mesaj içinde HTML'e izin ver
        echo '</div>';
    }

    /**
     * Chart.js grafikleri için renk paleti döndürür.
     * @param int $count İstenen renk sayısı.
     * @return array Renk kodları dizisi.
     */
    public static function get_chart_colors($count = 10) {
        // Daha fazla renk eklenebilir veya bir kütüphane kullanılabilir
        $colors = [
            '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796',
            '#6f42c1', '#fd7e14', '#20c997', '#f8f9fc', '#5a5c69', '#d1d3e2',
            '#4e73dfaa', '#1cc88aaa', '#36b9ccaa', '#f6c23eaa', '#e74a3baa', '#858796aa', // Biraz transparan
        ];

        // İstenen sayıda rengi döndür, gerekirse renkleri tekrarla
        $palette = [];
        for ($i = 0; $i < $count; $i++) {
            $palette[] = $colors[$i % count($colors)];
        }
        return $palette;
    }
} // Class WC_Status_Duration_UI sonu
