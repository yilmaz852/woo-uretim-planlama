<?php
/**
 * Eklenti ayarlarını yönetmek için yardımcı sınıf
 */

if (!defined('ABSPATH')) exit;

class WC_Prod_Settings_Helper {

    const SETTINGS_KEY = 'wc_prod_duration_settings'; // Option name in wp_options table
    private $settings = [];

    public function __construct() {
        // Ayarları yükle, yoksa varsayılanları kullan
        $this->settings = get_option(self::SETTINGS_KEY, $this->get_default_settings());
    }

    /**
     * Tüm ayarları dizi olarak al.
     * @return array
     */
    public function get_settings() {
        // Her ihtimale karşı varsayılanlarla birleştir, eksik anahtar olmasın
        return wp_parse_args($this->settings, $this->get_default_settings());
    }

    /**
     * Belirli bir ayar değerini al.
     * @param string $key Ayar anahtarı.
     * @param mixed $default Anahtar bulunamazsa dönecek varsayılan değer.
     * @return mixed Ayar değeri veya varsayılan.
     */
    public function get_setting($key, $default = null) {
        $defaults = $this->get_default_settings();
        $value = isset($this->settings[$key]) ? $this->settings[$key] : ($default ?? $defaults[$key] ?? null);

        // Özel durum: status_durations her zaman array olmalı
        if ($key === 'status_durations' && !is_array($value)) {
            return $defaults['status_durations'];
        }
        // Özel durum: working_days her zaman array olmalı
        if ($key === 'working_days' && !is_array($value)) {
            return $defaults['working_days'];
        }

        return $value;
    }

    /**
     * Eklenti için varsayılan ayarları tanımla.
     * @return array
     */
    public function get_default_settings() {
        return [
            'personnel_count'           => 1,
            'daily_hours'               => 8.0,
            'working_days'              => ['1', '2', '3', '4', '5'], // Monday-Friday
            'status_durations'          => $this->get_default_status_durations(), // Durumları dinamik al
            'notifications_enabled'     => 0,
            'notification_threshold'    => 24,
            'notification_emails'       => get_option('admin_email'),
            'cache_time'                => 3600, // 1 hour
        ];
    }

     /**
     * WooCommerce durumları için varsayılan süreleri (0) oluşturur.
     * @return array
     */
    private function get_default_status_durations() {
        $durations = [];
        if (function_exists('wc_get_order_statuses')) {
            $statuses = wc_get_order_statuses();
            foreach (array_keys($statuses) as $status_key) {
                $durations[$status_key] = 0; // Varsayılan 0 saniye
            }
        }
        return $durations;
    }


    /**
     * Eklenti aktif edildiğinde veya güncellendiğinde varsayılan ayarları kaydeder/günceller.
     */
    public function save_default_settings() {
        $current_settings = get_option(self::SETTINGS_KEY);
        $defaults = $this->get_default_settings();

        if ($current_settings === false) {
            // İlk kurulum, tüm varsayılanları kaydet
            update_option(self::SETTINGS_KEY, $defaults);
        } else {
            // Mevcut ayarlara eksik varsayılanları ekle (güncelleme durumu)
            $updated_settings = wp_parse_args($current_settings, $defaults);

             // Status durations için özel kontrol: Yeni eklenen WC durumları için 0 ekle
             if (isset($updated_settings['status_durations']) && is_array($updated_settings['status_durations'])) {
                 $default_durations = $this->get_default_status_durations();
                 foreach ($default_durations as $status_key => $default_value) {
                     if (!isset($updated_settings['status_durations'][$status_key])) {
                         $updated_settings['status_durations'][$status_key] = $default_value;
                     }
                 }
             } else {
                 // status_durations hiç yoksa veya array değilse varsayılanı ata
                 $updated_settings['status_durations'] = $this->get_default_status_durations();
             }


            // Eğer ayarlar değiştiyse güncelle
            if ($updated_settings != $current_settings) {
                update_option(self::SETTINGS_KEY, $updated_settings);
            }
        }
    }


    /**
     * Ayarları kaydetmeden önce doğrular ve temizler (sanitize).
     * WordPress Settings API tarafından kullanılır.
     * @param array $input Kaydedilmek istenen ham ayar verisi.
     * @return array Doğrulanmış ve temizlenmiş ayar verisi.
     */
    public function validate_settings($input) {
        $output = $this->get_settings(); // Başlangıç olarak mevcut ayarları al
        $input = (array) $input; // Gelen verinin array olduğundan emin ol

        $output['personnel_count'] = isset($input['personnel_count']) ? max(1, absint($input['personnel_count'])) : 1;
        $output['daily_hours'] = isset($input['daily_hours']) ? max(0.1, floatval($input['daily_hours'])) : 8.0;
        $output['working_days'] = isset($input['working_days']) && is_array($input['working_days'])
                                  ? array_map('sanitize_text_field', $input['working_days'])
                                  : ['1', '2', '3', '4', '5']; // Default if not set or not array

        // Durum Süreleri
        if (isset($input['status_durations']) && is_array($input['status_durations'])) {
             $valid_statuses = array_keys(wc_get_order_statuses());
             $sanitized_durations = [];
             foreach ($input['status_durations'] as $status_key => $duration) {
                 // Gelen anahtarın geçerli bir WC durumu olduğundan emin ol
                 if (in_array($status_key, $valid_statuses) && is_numeric($duration)) {
                    $sanitized_durations[sanitize_key($status_key)] = max(0, absint($duration)); // Negatif olamaz
                 }
             }
             // Eksik durumlar için 0 ekle
             foreach ($valid_statuses as $valid_key) {
                 if (!isset($sanitized_durations[$valid_key])) {
                     $sanitized_durations[$valid_key] = 0;
                 }
             }
             $output['status_durations'] = $sanitized_durations;
        } else {
             // Eğer hiç gelmediyse veya array değilse, varsayılanı koru
             $output['status_durations'] = $this->get_setting('status_durations');
        }

        $output['notifications_enabled'] = isset($input['notifications_enabled']) ? 1 : 0;
        $output['notification_threshold'] = isset($input['notification_threshold']) ? max(1, absint($input['notification_threshold'])) : 24;
        $output['cache_time'] = isset($input['cache_time']) ? max(60, absint($input['cache_time'])) : 3600; // Minimum 1 dakika

        // E-postalar
        if (isset($input['notification_emails'])) {
            $emails_raw = sanitize_textarea_field($input['notification_emails']); // Sanitize for potential line breaks etc.
            $emails_arr = array_map('trim', preg_split('/[\s,]+/', $emails_raw)); // Virgül veya boşlukla ayır
            $valid_emails = array_filter($emails_arr, 'is_email');
            $output['notification_emails'] = implode(', ', $valid_emails); // Virgül + boşluk ile birleştir
        } else {
             $output['notification_emails'] = get_option('admin_email'); // Varsayılan admin e-postası
        }

        return $output;
    }

    //--------------------------------------------------------------------------
    // Ayar Sayfası Render Fonksiyonları
    //--------------------------------------------------------------------------

    public function render_general_section() {
        echo '<p>' . esc_html__('Basic settings determining production capacity and working calendar.', 'wc-prod-duration') . '</p>';
    }
    public function render_status_duration_section() {
        echo '<p>' . esc_html__('Enter the estimated processing time in seconds for each WooCommerce order status. These durations will be used for the production schedule and estimated completion times.', 'wc-prod-duration') . '</p>';
    }
    public function render_notification_section() {
        echo '<p>' . esc_html__('Notification settings regarding how long orders stay in a particular status.', 'wc-prod-duration') . '</p>';
    }
    public function render_performance_section() {
        echo '<p>' . esc_html__('Caching and data retention settings affecting plugin performance.', 'wc-prod-duration') . '</p>';
        // Butonlar burada render edilmez, ana sınıfın settings_page render fonksiyonunda edilir.
    }

    /**
     * Çoğu ayar alanı için genel HTML render fonksiyonu.
     * @param array $args Alan argümanları (id, type, default, desc, step, placeholder).
     */
    public function render_field($args) {
        $option_name = self::SETTINGS_KEY . '[' . $args['id'] . ']';
        // Değeri alırken get_setting kullan, böylece varsayılanlar doğru yüklenir
        $value = $this->get_setting($args['id']);
        $type = $args['type'] ?? 'text';
        $id = esc_attr($args['id']);
        $name = esc_attr($option_name);

        switch ($type) {
            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" step="%s" />',
                    $id, $name, esc_attr($value),
                    esc_attr($args['min'] ?? 0),
                    esc_attr($args['step'] ?? 1)
                );
                break;
            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s />',
                    $id, $name, checked(1, $value, false)
                );
                break;
            case 'text':
            default:
                 printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
                    $id, $name, esc_attr($value),
                    esc_attr($args['placeholder'] ?? '')
                );
                break;
        }
         if (!empty($args['desc'])) {
            printf('<p class="description">%s</p>', esc_html($args['desc']));
        }
    }

    /**
     * Çalışma günleri için özel HTML render fonksiyonu.
     */
    public function render_working_days_field() {
        $option_name = self::SETTINGS_KEY . '[working_days]';
        $selected_days = $this->get_setting('working_days'); // Varsayılanları içerir
        $days = [
            '1' => __('Monday'), '2' => __('Tuesday'), '3' => __('Wednesday'),
            '4' => __('Thursday'), '5' => __('Friday'), '6' => __('Saturday'), '0' => __('Sunday')
        ];

        echo '<fieldset>';
        foreach ($days as $value => $label) {
             printf(
                '<label style="margin-right: 15px; display: inline-block;"><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
                esc_attr($option_name),
                esc_attr($value),
                checked(in_array((string)$value, $selected_days, true), true, false), // Strict comparison
                esc_html($label)
            );
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Select the days of the week when production is active.', 'wc-prod-duration') . '</p>';
    }

    /**
     * Durum süreleri için özel HTML render fonksiyonu.
     */
    public function render_status_durations_field() {
        $option_base_name = self::SETTINGS_KEY . '[status_durations]';
        $saved_durations = $this->get_setting('status_durations'); // Varsayılanları içerir
        $all_statuses = wc_get_order_statuses();

        echo '<table class="form-table wc-status-durations-table"><tbody>';
        foreach ($all_statuses as $status_key => $status_name) {
            $duration_value = $saved_durations[$status_key] ?? 0; // Eksikse 0 ata
            $field_name = $option_base_name . '[' . esc_attr($status_key) . ']';
            $field_id = esc_attr($status_key);

             echo '<tr>';
             echo '<th scope="row"><label for="' . $field_id . '">' . esc_html($status_name) . '</label></th>';
             echo '<td>';
             printf(
                '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="0" step="1" /> %s',
                $field_id, $field_name, esc_attr($duration_value), esc_html__('seconds', 'wc-prod-duration')
            );
             echo '</td>';
             echo '</tr>';
        }
         echo '</tbody></table>';
         echo '<p class="description">' . esc_html__('Enter the estimated average completion time for each status in seconds. E.g., 1 hour = 3600 seconds.', 'wc-prod-duration') . '</p>';
    }
} // Class WC_Prod_Settings_Helper sonu