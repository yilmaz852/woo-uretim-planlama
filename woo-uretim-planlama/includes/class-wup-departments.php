<?php
/**
 * Departman Yönetimi Sınıfı
 * 
 * Dinamik departman yapısı:
 * - Özel departmanlar oluşturma (Boyahane, Operasyon, Montaj vb.)
 * - WooCommerce durumlarını departmanlara bağlama
 * - İşçi sayısı ve kapasite yönetimi
 * - Simülasyon ve analiz
 */

if (!defined('ABSPATH')) {
    exit;
}

class WUP_Departments {
    
    private static $instance = null;
    const OPTION_KEY = 'wup_departments';
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin menüsüne departman sayfası ekle
        add_action('admin_menu', array($this, 'add_submenu'), 15);
        
        // AJAX işlemleri
        add_action('wp_ajax_wup_save_department', array($this, 'ajax_save_department'));
        add_action('wp_ajax_wup_delete_department', array($this, 'ajax_delete_department'));
        add_action('wp_ajax_wup_simulate_workload', array($this, 'ajax_simulate_workload'));
    }
    
    /**
     * Alt menü ekle
     */
    public function add_submenu() {
        add_submenu_page(
            'wup-report',
            __('Departmanlar', 'woo-uretim-planlama'),
            __('Departmanlar', 'woo-uretim-planlama'),
            'manage_options',
            'wup-departments',
            array($this, 'render_page')
        );
    }
    
    /**
     * Tüm departmanları al
     */
    public static function get_all() {
        $departments = get_option(self::OPTION_KEY, array());
        
        if (empty($departments)) {
            // Varsayılan departmanlar
            $departments = self::get_defaults();
            update_option(self::OPTION_KEY, $departments);
        }
        
        return $departments;
    }
    
    /**
     * Varsayılan departmanlar
     */
    public static function get_defaults() {
        return array(
            'operasyon' => array(
                'id' => 'operasyon',
                'name' => __('Operasyon', 'woo-uretim-planlama'),
                'color' => '#3498db',
                'workers' => 2,
                'base_duration' => 60, // dakika
                'statuses' => array('wc-processing', 'wc-on-hold'),
                'description' => __('Sipariş işleme ve hazırlık', 'woo-uretim-planlama')
            ),
            'uretim' => array(
                'id' => 'uretim',
                'name' => __('Üretim', 'woo-uretim-planlama'),
                'color' => '#e74c3c',
                'workers' => 3,
                'base_duration' => 180, // dakika
                'statuses' => array(),
                'description' => __('Ana üretim hattı', 'woo-uretim-planlama')
            ),
            'boyahane' => array(
                'id' => 'boyahane',
                'name' => __('Boyahane', 'woo-uretim-planlama'),
                'color' => '#9b59b6',
                'workers' => 3,
                'base_duration' => 180, // dakika (3 saat)
                'statuses' => array(),
                'description' => __('Boya ve kaplama işlemleri', 'woo-uretim-planlama')
            ),
            'montaj' => array(
                'id' => 'montaj',
                'name' => __('Montaj', 'woo-uretim-planlama'),
                'color' => '#27ae60',
                'workers' => 2,
                'base_duration' => 120, // dakika
                'statuses' => array(),
                'description' => __('Parça montaj ve birleştirme', 'woo-uretim-planlama')
            ),
            'kalite' => array(
                'id' => 'kalite',
                'name' => __('Kalite Kontrol', 'woo-uretim-planlama'),
                'color' => '#f39c12',
                'workers' => 1,
                'base_duration' => 30, // dakika
                'statuses' => array(),
                'description' => __('Son kontrol ve onay', 'woo-uretim-planlama')
            ),
            'sevkiyat' => array(
                'id' => 'sevkiyat',
                'name' => __('Sevkiyat', 'woo-uretim-planlama'),
                'color' => '#1abc9c',
                'workers' => 2,
                'base_duration' => 30, // dakika
                'statuses' => array('wc-completed'),
                'description' => __('Paketleme ve kargo', 'woo-uretim-planlama')
            )
        );
    }
    
    /**
     * Tek departman al
     */
    public static function get($id) {
        $departments = self::get_all();
        return isset($departments[$id]) ? $departments[$id] : null;
    }
    
    /**
     * Departman kaydet
     */
    public static function save($department) {
        $departments = self::get_all();
        
        $id = sanitize_key($department['id']);
        
        $departments[$id] = array(
            'id' => $id,
            'name' => sanitize_text_field($department['name']),
            'color' => sanitize_hex_color($department['color']),
            'workers' => max(1, absint($department['workers'])),
            'base_duration' => max(1, absint($department['base_duration'])),
            'statuses' => isset($department['statuses']) ? array_map('sanitize_key', (array)$department['statuses']) : array(),
            'description' => sanitize_textarea_field($department['description'])
        );
        
        update_option(self::OPTION_KEY, $departments);
        WUP_Cache::clear_all();
        
        return $departments[$id];
    }
    
    /**
     * Departman sil
     */
    public static function delete($id) {
        $departments = self::get_all();
        
        if (isset($departments[$id])) {
            unset($departments[$id]);
            update_option(self::OPTION_KEY, $departments);
            WUP_Cache::clear_all();
            return true;
        }
        
        return false;
    }
    
    /**
     * Durum için departmanı bul
     */
    public static function get_department_by_status($status) {
        $departments = self::get_all();
        $status_key = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
        
        foreach ($departments as $dept) {
            if (in_array($status_key, $dept['statuses'])) {
                return $dept;
            }
        }
        
        return null;
    }
    
    /**
     * Departmanın işlem süresini hesapla (saniye cinsinden)
     * 
     * Formül: (base_duration / workers) * 60
     * Örnek: 3 işçi ile 180dk temel süre → her işçi için 60dk
     */
    public static function get_duration($dept_id, $custom_workers = null) {
        $dept = self::get($dept_id);
        
        if (!$dept) {
            return 0;
        }
        
        $base_minutes = $dept['base_duration'];
        $configured_workers = $dept['workers'];
        $actual_workers = $custom_workers !== null ? max(1, $custom_workers) : $configured_workers;
        
        // Tam kadro ile belirlenen süre / mevcut işçi sayısı oranı
        // Daha az işçi = daha uzun süre
        // 3 işçi ile 180dk → 1 işçi ile: 180 * (3/1) = 540dk
        $adjusted_minutes = $base_minutes * ($configured_workers / $actual_workers);
        
        return round($adjusted_minutes * 60); // saniyeye çevir
    }
    
    /**
     * Departman için tek işçi süresini hesapla (dakika)
     */
    public static function get_single_worker_duration($dept_id) {
        $dept = self::get($dept_id);
        
        if (!$dept) {
            return 0;
        }
        
        // Tek işçi süresi = temel süre * işçi sayısı
        return $dept['base_duration'] * $dept['workers'];
    }
    
    /**
     * Toplam kapasite hesapla (günlük, saniye cinsinden)
     */
    public static function get_total_daily_capacity() {
        $departments = self::get_all();
        $daily_hours = WUP_Settings::get('daily_hours', 8);
        
        $total_capacity = 0;
        
        foreach ($departments as $dept) {
            // Her departman kendi işçi sayısı * günlük saat
            $dept_capacity = $dept['workers'] * $daily_hours * 3600;
            $total_capacity += $dept_capacity;
        }
        
        return $total_capacity;
    }
    
    /**
     * İş yükü simülasyonu
     */
    public static function simulate_workload($changes = array()) {
        $departments = self::get_all();
        $orders = wc_get_orders(array(
            'status' => array('processing', 'on-hold', 'pending'),
            'limit' => -1
        ));
        
        $simulation = array(
            'current' => array(),
            'simulated' => array(),
            'difference' => array()
        );
        
        // Her departman için mevcut ve simüle edilmiş durumu hesapla
        foreach ($departments as $dept_id => $dept) {
            $order_count = 0;
            
            // Bu departmana bağlı durumlardaki sipariş sayısını bul
            foreach ($orders as $order) {
                $status = 'wc-' . $order->get_status();
                if (in_array($status, $dept['statuses'])) {
                    $order_count++;
                }
            }
            
            // Mevcut durum
            $current_workers = $dept['workers'];
            $current_duration = self::get_duration($dept_id);
            $current_total = $order_count * $current_duration;
            
            // Simüle edilmiş durum
            $simulated_workers = isset($changes[$dept_id]) ? max(1, absint($changes[$dept_id])) : $current_workers;
            $simulated_duration = self::get_duration($dept_id, $simulated_workers);
            $simulated_total = $order_count * $simulated_duration;
            
            $simulation['current'][$dept_id] = array(
                'name' => $dept['name'],
                'workers' => $current_workers,
                'order_count' => $order_count,
                'duration_per_order' => $current_duration,
                'total_workload' => $current_total
            );
            
            $simulation['simulated'][$dept_id] = array(
                'name' => $dept['name'],
                'workers' => $simulated_workers,
                'order_count' => $order_count,
                'duration_per_order' => $simulated_duration,
                'total_workload' => $simulated_total
            );
            
            $simulation['difference'][$dept_id] = array(
                'workers_diff' => $simulated_workers - $current_workers,
                'duration_diff' => $simulated_duration - $current_duration,
                'workload_diff' => $simulated_total - $current_total
            );
        }
        
        return $simulation;
    }
    
    /**
     * AJAX: Departman kaydet
     */
    public function ajax_save_department() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $department = array(
            'id' => isset($_POST['dept_id']) ? sanitize_key($_POST['dept_id']) : '',
            'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'color' => isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '#3498db',
            'workers' => isset($_POST['workers']) ? absint($_POST['workers']) : 1,
            'base_duration' => isset($_POST['base_duration']) ? absint($_POST['base_duration']) : 60,
            'statuses' => isset($_POST['statuses']) ? (array)$_POST['statuses'] : array(),
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : ''
        );
        
        if (empty($department['id']) || empty($department['name'])) {
            wp_send_json_error(array('message' => __('Departman ID ve adı zorunludur.', 'woo-uretim-planlama')));
        }
        
        $saved = self::save($department);
        
        wp_send_json_success(array(
            'message' => __('Departman kaydedildi.', 'woo-uretim-planlama'),
            'department' => $saved
        ));
    }
    
    /**
     * AJAX: Departman sil
     */
    public function ajax_delete_department() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $id = isset($_POST['dept_id']) ? sanitize_key($_POST['dept_id']) : '';
        
        if (empty($id)) {
            wp_send_json_error(array('message' => __('Departman ID gerekli.', 'woo-uretim-planlama')));
        }
        
        if (self::delete($id)) {
            wp_send_json_success(array('message' => __('Departman silindi.', 'woo-uretim-planlama')));
        } else {
            wp_send_json_error(array('message' => __('Departman bulunamadı.', 'woo-uretim-planlama')));
        }
    }
    
    /**
     * AJAX: İş yükü simülasyonu
     */
    public function ajax_simulate_workload() {
        check_ajax_referer('wup_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'woo-uretim-planlama')));
        }
        
        $changes = isset($_POST['changes']) ? (array)$_POST['changes'] : array();
        
        $simulation = self::simulate_workload($changes);
        
        wp_send_json_success($simulation);
    }
    
    /**
     * Sayfa render
     */
    public function render_page() {
        $departments = self::get_all();
        $statuses = wc_get_order_statuses();
        
        WUP_UI::page_header(
            __('Departman Yönetimi', 'woo-uretim-planlama'),
            __('Departmanları oluşturun, düzenleyin ve WooCommerce durumlarını bağlayın.', 'woo-uretim-planlama')
        );
        
        // Nonce for AJAX
        wp_nonce_field('wup_nonce', 'wup_dept_nonce');
        
        echo '<div class="wup-departments-container" style="display:flex; gap:30px; flex-wrap:wrap;">';
        
        // Sol panel: Departman listesi
        echo '<div class="wup-dept-list" style="flex:1; min-width:400px;">';
        echo '<h2>' . esc_html__('Departmanlar', 'woo-uretim-planlama') . '</h2>';
        
        if (empty($departments)) {
            WUP_UI::notice(__('Henüz departman oluşturulmamış.', 'woo-uretim-planlama'), 'info');
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width:30%;">' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('İşçi', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Süre', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Tek İşçi', 'woo-uretim-planlama') . '</th>';
            echo '<th>' . esc_html__('Durumlar', 'woo-uretim-planlama') . '</th>';
            echo '<th style="width:80px;">' . esc_html__('İşlem', 'woo-uretim-planlama') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($departments as $dept) {
                $single_worker_hours = round(self::get_single_worker_duration($dept['id']) / 60, 1);
                $base_hours = round($dept['base_duration'] / 60, 1);
                $status_count = count($dept['statuses']);
                
                echo '<tr data-dept-id="' . esc_attr($dept['id']) . '">';
                echo '<td>';
                echo '<span class="wup-color-badge" style="background:' . esc_attr($dept['color']) . ';display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:8px;"></span>';
                echo '<strong>' . esc_html($dept['name']) . '</strong>';
                if (!empty($dept['description'])) {
                    echo '<br><small style="color:#666;">' . esc_html($dept['description']) . '</small>';
                }
                echo '</td>';
                echo '<td>' . esc_html($dept['workers']) . ' ' . esc_html__('kişi', 'woo-uretim-planlama') . '</td>';
                echo '<td>' . esc_html($base_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
                echo '<td style="color:#666;">' . esc_html($single_worker_hours) . ' ' . esc_html__('saat', 'woo-uretim-planlama') . '</td>';
                echo '<td>' . esc_html($status_count) . ' ' . esc_html__('durum', 'woo-uretim-planlama') . '</td>';
                echo '<td>';
                echo '<button type="button" class="button button-small wup-edit-dept" data-dept=\'' . esc_attr(wp_json_encode($dept)) . '\'>' . esc_html__('Düzenle', 'woo-uretim-planlama') . '</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
        
        // Sağ panel: Departman formu
        echo '<div class="wup-dept-form" style="flex:1; min-width:350px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<h2 id="wup-form-title">' . esc_html__('Yeni Departman Ekle', 'woo-uretim-planlama') . '</h2>';
        
        echo '<form id="wup-dept-form">';
        echo '<input type="hidden" name="dept_id" id="dept_id" value="">';
        
        echo '<p>';
        echo '<label for="dept_name"><strong>' . esc_html__('Departman Adı', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="text" name="name" id="dept_name" class="regular-text" required>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="dept_color"><strong>' . esc_html__('Renk', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="color" name="color" id="dept_color" value="#3498db">';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="dept_workers"><strong>' . esc_html__('İşçi Sayısı', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="number" name="workers" id="dept_workers" min="1" value="1" class="small-text" required>';
        echo '<span class="description"> ' . esc_html__('Bu departmanda çalışan kişi sayısı', 'woo-uretim-planlama') . '</span>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="dept_duration"><strong>' . esc_html__('Temel İşlem Süresi (dakika)', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<input type="number" name="base_duration" id="dept_duration" min="1" value="60" class="small-text" required>';
        echo '<span class="description"> ' . esc_html__('Tam kadro ile ortalama işlem süresi', 'woo-uretim-planlama') . '</span>';
        echo '</p>';
        
        echo '<p>';
        echo '<label for="dept_description"><strong>' . esc_html__('Açıklama', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<textarea name="description" id="dept_description" rows="2" class="large-text"></textarea>';
        echo '</p>';
        
        echo '<p>';
        echo '<label><strong>' . esc_html__('Bağlı Durumlar', 'woo-uretim-planlama') . '</strong></label><br>';
        echo '<span class="description">' . esc_html__('Bu departmana bağlı WooCommerce sipariş durumlarını seçin.', 'woo-uretim-planlama') . '</span><br><br>';
        
        foreach ($statuses as $key => $label) {
            echo '<label style="display:block; margin-bottom:5px;">';
            echo '<input type="checkbox" name="statuses[]" value="' . esc_attr($key) . '"> ';
            echo esc_html($label);
            echo '</label>';
        }
        echo '</p>';
        
        echo '<p style="margin-top:20px;">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Kaydet', 'woo-uretim-planlama') . '</button> ';
        echo '<button type="button" id="wup-reset-form" class="button">' . esc_html__('Temizle', 'woo-uretim-planlama') . '</button> ';
        echo '<button type="button" id="wup-delete-dept" class="button button-link-delete" style="display:none;">' . esc_html__('Sil', 'woo-uretim-planlama') . '</button>';
        echo '</p>';
        
        echo '</form>';
        echo '</div>';
        
        echo '</div>'; // container
        
        // Simülasyon Bölümü
        echo '<hr style="margin:40px 0;">';
        echo '<h2>' . esc_html__('İş Yükü Simülasyonu', 'woo-uretim-planlama') . '</h2>';
        echo '<p class="description">' . esc_html__('Personel değişikliklerinin iş yüküne etkisini simüle edin. İşçi sayılarını değiştirip "Simüle Et" butonuna tıklayın.', 'woo-uretim-planlama') . '</p>';
        
        echo '<div id="wup-simulation-container" style="margin-top:20px;">';
        echo '<table class="widefat fixed striped" style="max-width:900px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Departman', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Mevcut İşçi', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Yeni İşçi', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Sipariş', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Mevcut İş Yükü', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Simüle İş Yükü', 'woo-uretim-planlama') . '</th>';
        echo '<th>' . esc_html__('Fark', 'woo-uretim-planlama') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($departments as $dept) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($dept['name']) . '</strong></td>';
            echo '<td>' . esc_html($dept['workers']) . '</td>';
            echo '<td><input type="number" class="wup-sim-workers small-text" data-dept="' . esc_attr($dept['id']) . '" value="' . esc_attr($dept['workers']) . '" min="1" style="width:60px;"></td>';
            echo '<td class="wup-sim-orders">-</td>';
            echo '<td class="wup-sim-current">-</td>';
            echo '<td class="wup-sim-new">-</td>';
            echo '<td class="wup-sim-diff">-</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        echo '<p style="margin-top:15px;">';
        echo '<button type="button" id="wup-run-simulation" class="button button-primary">' . esc_html__('Simüle Et', 'woo-uretim-planlama') . '</button>';
        echo '<span id="wup-sim-status" class="wup-status" style="margin-left:10px;"></span>';
        echo '</p>';
        echo '</div>';
        
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
            var nonce = $('#wup_dept_nonce').val();
            
            // Form gönderimi
            $('#wup-dept-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'wup_save_department',
                    nonce: nonce,
                    dept_id: $('#dept_id').val() || $('#dept_name').val().toLowerCase().replace(/[^a-z0-9]/g, '_'),
                    name: $('#dept_name').val(),
                    color: $('#dept_color').val(),
                    workers: $('#dept_workers').val(),
                    base_duration: $('#dept_duration').val(),
                    description: $('#dept_description').val(),
                    statuses: []
                };
                
                $('input[name="statuses[]"]:checked').each(function() {
                    formData.statuses.push($(this).val());
                });
                
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
            $('.wup-edit-dept').on('click', function() {
                var dept = $(this).data('dept');
                
                $('#wup-form-title').text('<?php echo esc_js(__('Departman Düzenle', 'woo-uretim-planlama')); ?>');
                $('#dept_id').val(dept.id);
                $('#dept_name').val(dept.name);
                $('#dept_color').val(dept.color);
                $('#dept_workers').val(dept.workers);
                $('#dept_duration').val(dept.base_duration);
                $('#dept_description').val(dept.description || '');
                
                // Durumları işaretle
                $('input[name="statuses[]"]').prop('checked', false);
                if (dept.statuses) {
                    dept.statuses.forEach(function(status) {
                        $('input[name="statuses[]"][value="' + status + '"]').prop('checked', true);
                    });
                }
                
                $('#wup-delete-dept').show();
                
                $('html, body').animate({
                    scrollTop: $('#wup-dept-form').offset().top - 50
                }, 300);
            });
            
            // Formu temizle
            $('#wup-reset-form').on('click', function() {
                $('#wup-form-title').text('<?php echo esc_js(__('Yeni Departman Ekle', 'woo-uretim-planlama')); ?>');
                $('#dept_id').val('');
                $('#dept_name').val('');
                $('#dept_color').val('#3498db');
                $('#dept_workers').val(1);
                $('#dept_duration').val(60);
                $('#dept_description').val('');
                $('input[name="statuses[]"]').prop('checked', false);
                $('#wup-delete-dept').hide();
            });
            
            // Departman sil
            $('#wup-delete-dept').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Bu departmanı silmek istediğinizden emin misiniz?', 'woo-uretim-planlama')); ?>')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'wup_delete_department',
                    nonce: nonce,
                    dept_id: $('#dept_id').val()
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Bir hata oluştu.', 'woo-uretim-planlama')); ?>');
                    }
                });
            });
            
            // Simülasyon
            $('#wup-run-simulation').on('click', function() {
                var $btn = $(this);
                var $status = $('#wup-sim-status');
                var changes = {};
                
                $('.wup-sim-workers').each(function() {
                    changes[$(this).data('dept')] = $(this).val();
                });
                
                $btn.prop('disabled', true);
                $status.text('<?php echo esc_js(__('Hesaplanıyor...', 'woo-uretim-planlama')); ?>');
                
                $.post(ajaxurl, {
                    action: 'wup_simulate_workload',
                    nonce: nonce,
                    changes: changes
                }, function(response) {
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        var data = response.data;
                        
                        for (var deptId in data.current) {
                            var $row = $('input[data-dept="' + deptId + '"]').closest('tr');
                            var current = data.current[deptId];
                            var simulated = data.simulated[deptId];
                            var diff = data.difference[deptId];
                            
                            $row.find('.wup-sim-orders').text(current.order_count);
                            $row.find('.wup-sim-current').text(formatWorkload(current.total_workload));
                            $row.find('.wup-sim-new').text(formatWorkload(simulated.total_workload));
                            
                            var diffText = formatWorkload(Math.abs(diff.workload_diff));
                            if (diff.workload_diff > 0) {
                                $row.find('.wup-sim-diff').html('<span style="color:red;">+' + diffText + '</span>');
                            } else if (diff.workload_diff < 0) {
                                $row.find('.wup-sim-diff').html('<span style="color:green;">-' + diffText + '</span>');
                            } else {
                                $row.find('.wup-sim-diff').text('-');
                            }
                        }
                        
                        $status.text('<?php echo esc_js(__('Tamamlandı', 'woo-uretim-planlama')); ?>').css('color', 'green');
                    } else {
                        $status.text(response.data.message || '<?php echo esc_js(__('Hata', 'woo-uretim-planlama')); ?>').css('color', 'red');
                    }
                    
                    setTimeout(function() { $status.text(''); }, 3000);
                });
            });
            
            function formatWorkload(seconds) {
                if (!seconds || seconds <= 0) return '-';
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                if (hours > 0) {
                    return hours + ' saat ' + minutes + ' dk';
                }
                return minutes + ' dk';
            }
        });
        </script>
        <?php
    }
}
