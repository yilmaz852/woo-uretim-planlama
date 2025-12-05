<?php
/**
 * Sipariş Takvimi Modülü
 */

if (!defined('ABSPATH')) exit;

class WC_Order_Calendar {

    private $scheduler;

    public function __construct(WC_Production_Scheduler $scheduler) {
         $this->scheduler = $scheduler;
    }

    /**
     * Takvim admin sayfasını render eder.
     */
    public function render_calendar_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Order Calendar', 'wc-prod-duration') . '</h1>';
        echo '<p>' . esc_html__('Calendar view based on estimated order completion dates.', 'wc-prod-duration') . '</p>';
        echo '<div id="order-calendar-container" style="margin-top: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4;"><div id="order-calendar"></div></div>';
        echo '</div>';
        add_action('admin_footer', [$this, 'calendar_script']); // JS'i footer'a ekle
    }

    /**
     * FullCalendar'ı başlatan JavaScript kodunu ekler.
     * YENİ: Veriyi AJAX ile yükler.
     */
    public function calendar_script() {
        $screen = get_current_screen();
        // Screen ID'yi kontrol et (WordPress versiyonlarına göre değişebilir, tarayıcı inspector ile kontrol edin)
        if (!$screen || !in_array($screen->id, ['production-reports_page_wc-order-calendar', 'woocommerce_page_wc-order-calendar'])) {
             return;
        }
        // REST API URL'sini ve nonce'ı JS'e aktar (ana eklenti dosyasında localize ediliyor)
        // $api_url = rest_url('wc-prod-duration/v1/calendar');
        // $rest_nonce = wp_create_nonce('wp_rest');
        ?>
        <style>
            #order-calendar-container { max-width: 1200px; margin-left: auto; margin-right: auto;}
            #order-calendar { font-size: 14px; }
            .fc-event { cursor: pointer; border: 1px solid #bbb; font-size: 0.9em;}
            .fc-event-title { white-space: normal; padding: 2px 4px;}
            /* Tooltip stilleri (JS ile eklenecekse tippy.js önerilir) */
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('order-calendar');
            if (!calendarEl || typeof FullCalendar === 'undefined' || typeof wc_prod_duration_data === 'undefined') {
                 console.error("Calendar dependencies missing (Element, FullCalendar, or wc_prod_duration_data).");
                 if(calendarEl) calendarEl.innerHTML = '<p style=\"color:red;\">Calendar library or required data is missing. Check browser console.</p>';
                 return;
             }

            var calendarApiUrl = wc_prod_duration_data.rest_api_url + 'calendar'; // API URL'si localize edilmiş veriden
            var restNonce = wc_prod_duration_data.rest_nonce; // Nonce localize edilmiş veriden

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
                events: {
                    url: calendarApiUrl,
                    method: 'GET',
                    extraParams: function() { return { _wpnonce: restNonce }; }, // Nonce'ı gönder
                    failure: function(error) {
                        console.error("Error fetching calendar events:", error);
                        // Kullanıcıya hata mesajı göster (daha iyi bir UI ile)
                        alert('<?php echo esc_js(__('Error fetching calendar events! Check console for details.', 'wc-prod-duration')); ?>');
                    },
                    // Gelen veriyi FullCalendar'ın beklediği formata dönüştür (API zaten doğru formatta dönmeli)
                    // eventDataTransform: function(eventData) { return eventData; }
                },
                loading: function(isLoading) {
                    calendarEl.style.opacity = isLoading ? 0.5 : 1; // Basit yükleniyor efekti
                },
                editable: false,
                selectable: false,
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    if (info.event.url) window.open(info.event.url, "_blank");
                },
                eventDidMount: function(info) {
                    // Tooltip (Tippy.js önerilir, yoksa basit title)
                    let tooltipContent = '<strong>' + info.event.title + '</strong><br>';
                    if(info.event.extendedProps?.status_name) tooltipContent += '<?php echo esc_js(__('Status:', 'wc-prod-duration')); ?> ' + info.event.extendedProps.status_name + '<br>';
                    if(info.event.extendedProps?.remaining_formatted) tooltipContent += '<?php echo esc_js(__('Est. Remaining:', 'wc-prod-duration')); ?> ' + info.event.extendedProps.remaining_formatted;
                    info.el.setAttribute('title', tooltipContent.replace(/<br>/g, '\n').replace(/<.*?>/g, '')); // Basit title için HTML'i kaldır
                    // if (typeof tippy !== 'undefined') tippy(info.el, { content: tooltipContent, allowHTML: true });
                },
                 locale: '<?php echo substr(get_locale(), 0, 2); ?>',
                 buttonText: { today: '<?php echo esc_js(__('Today')); ?>', month: '<?php echo esc_js(__('Month')); ?>', week: '<?php echo esc_js(__('Week')); ?>', day: '<?php echo esc_js(__('Day')); ?>', list: '<?php echo esc_js(__('List')); ?>' },
                 firstDay: <?php echo (int)get_option('start_of_week', 1); ?>
            });
            calendar.render();
        });
        </script>
        <?php
    }

    /**
     * API Endpoint Callback: Takvim olaylarını döndürür.
     * FullCalendar'ın event source olarak kullanması için.
     * YENİ: API callback'i olarak eklendi.
     */
    public function get_calendar_events_api($request) {
        // İzin kontrolü (REST route tanımında yapılıyor)

        // FullCalendar'dan gelen tarih aralığı (isteğe bağlı filtreleme için)
        $start_param = $request->get_param('start'); // Örn: 2025-04-01T00:00:00Z
        $end_param = $request->get_param('end');     // Örn: 2025-05-13T00:00:00Z

        // Scheduler'dan program verisini al (önbellekli)
        $schedule_data = $this->scheduler->get_schedule_data();
        $calendar_events = [];

        if (!empty($schedule_data['orders'])) {
            $start_ts = $start_param ? strtotime($start_param) : null;
            $end_ts = $end_param ? strtotime($end_param) : null;

            foreach ($schedule_data['orders'] as $item) {
                $completion_ts = $item['estimated_completion_date']; // Bu Unix timestamp olmalı
                if ($completion_ts) {
                    // Tarih aralığı filtresi (varsa uygula)
                    if ($start_ts && $end_ts && ($completion_ts < $start_ts || $completion_ts >= $end_ts)) {
                        continue; // Aralığın dışındaysa atla
                    }

                    $order = $item['order'];
                    if (!$order instanceof WC_Order) continue;

                    $order_link = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                    $event_title = sprintf('#%s - %s', $order->get_id(), $order->get_formatted_billing_full_name());

                    $calendar_events[] = [
                        'id'            => $order->get_id(),
                        'title'         => $event_title,
                        'start'         => gmdate('c', $completion_ts), // ISO 8601 GMT zorunlu!
                        'url'           => $order_link,
                        'allDay'        => true, // Tam günlük olay
                        'extendedProps' => [
                            'status'            => $order->get_status(),
                            'status_name'       => wc_get_order_status_name($order->get_status()),
                            'remaining_seconds' => $item['estimated_remaining_seconds'],
                            'remaining_formatted'=> $this->scheduler->format_business_hours($item['estimated_remaining_seconds']), // Scheduler'dan format fonksiyonunu kullan
                        ],
                        // 'backgroundColor' => '#...', // Duruma göre renk
                    ];
                }
            }
        }

        // FullCalendar doğrudan olay dizisini bekler
        return new WP_REST_Response($calendar_events, 200);
    }

} // Class WC_Order_Calendar sonu
