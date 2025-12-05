/**
 * Admin JavaScript for WooCommerce Production and Status Duration Report
 */
jQuery(document).ready(function($) {

    // --- Genel Ayarlar ve Yardımcı Fonksiyonlar ---

    // Global scope'da Chart.js tooltip formatlayıcı
    window.wc_prod_duration_format_tooltip_label = function(context) {
        var label = context.dataset.label || '';
        // Analiz sayfasındaki line/bar chart'lar için status ismini al
        if (!label && context.chart?.data?.datasets?.[context.datasetIndex]?.label) {
            label = context.chart.data.datasets[context.datasetIndex].label;
        }
        // Rapor sayfasındaki bar chart için status ismini al (etiketten)
        else if (!label && context.chart?.data?.labels?.[context.dataIndex] && context.chart?.config?.status_names) {
             const statusKey = context.chart.data.labels[context.dataIndex];
             label = context.chart.config.status_names[statusKey] || statusKey;
        }

        if (label) label += ': ';

        var value = context.raw;
        if (typeof value === 'number') {
            var hours = Math.floor(value / 3600);
            var minutes = Math.floor((value % 3600) / 60);
            var secs = Math.round(value % 60);
            label += (hours < 10 ? '0' + hours : hours) + ':' +
                     (minutes < 10 ? '0' + minutes : minutes) + ':' +
                     (secs < 10 ? '0' + secs : secs);
        } else { label += value; }
        return label;
    };

    // Genel Chart.js render fonksiyonu
    window.wc_prod_duration_render_chart = function(chartConfig) {
        if (typeof Chart === 'undefined' || !chartConfig || !chartConfig.element_id) return;
        var ctx = document.getElementById(chartConfig.element_id);
        if (!ctx) return;
        ctx = ctx.getContext('2d');

        // Tooltip callback'ini ayarla
        if (chartConfig.options?.plugins?.tooltip?.callbacks?.label === 'js:wc_prod_duration_format_tooltip_label') {
            chartConfig.options.plugins.tooltip.callbacks.label = window.wc_prod_duration_format_tooltip_label;
        }

        // Grafiği oluştur veya güncelle (varsa)
        let existingChart = Chart.getChart(ctx);
        if (existingChart) {
            existingChart.destroy();
        }

        const chart = new Chart(ctx, {
            type: chartConfig.type,
            data: { labels: chartConfig.labels, datasets: chartConfig.datasets },
            options: chartConfig.options
        });
        // Rapor sayfasındaki grafik için durum isimlerini config'e ekle (tooltip'te kullanmak için)
         if(chartConfig.element_id === 'durationChart' && chartConfig.status_names) {
             chart.config.status_names = chartConfig.status_names;
         }
    };

    // Tarih alanları için yardımcı fonksiyon
    const setDefaultDates = (startInputName, endInputName, daysAgo = 30) => {
        const startDateInput = $(`input[name="${startInputName}"]`);
        const endDateInput = $(`input[name="${endInputName}"]`);
        if (startDateInput.length && startDateInput.val() === '') {
            const pastDate = new Date(); pastDate.setDate(pastDate.getDate() - daysAgo);
            startDateInput.val(pastDate.toISOString().split('T')[0]);
        }
        if (endDateInput.length && endDateInput.val() === '') {
            const today = new Date(); endDateInput.val(today.toISOString().split('T')[0]);
        }
    };

    // --- Sayfa Bazlı İşlemler ---
    const bodyClasses = $('body').attr('class');

    // Rapor Sayfası
    if (bodyClasses.includes('toplevel_page_wc-production-report')) {
        setDefaultDates('start_date', 'end_date');
        $('#export-csv-form').on('submit', function(e) {
            $(this).find('input[type="hidden"][name!="export_csv"]').remove();
            $('.wc-status-filter-form input, .wc-status-filter-form select').each(function() {
                const name = $(this).attr('name'); const value = $(this).val();
                if (name && value && name !== 'page' && name !== 'paged') {
                    $('<input>').attr({ type: 'hidden', name: name, value: value }).appendTo('#export-csv-form');
                }
            });
        });
        // Rapor grafiği (inline data ile tetiklenir)
        if (typeof wc_duration_chart_data !== 'undefined') {
            wc_prod_duration_render_chart(wc_duration_chart_data);
        }
    }

    // Analiz Sayfası
    if (bodyClasses.includes('production-reports_page_wc-status-duration-analysis')) {
        setDefaultDates('chart_start_date', 'chart_end_date');
        // Analiz grafikleri (inline data ile tetiklenir)
        if (typeof wc_prod_trend_chart_data !== 'undefined') wc_prod_duration_render_chart(wc_prod_trend_chart_data);
        if (typeof wc_prod_dist_chart_data !== 'undefined') wc_prod_duration_render_chart(wc_prod_dist_chart_data);
        if (typeof wc_prod_weekday_chart_data !== 'undefined') wc_prod_duration_render_chart(wc_prod_weekday_chart_data);
    }

    // Ayarlar Sayfası
    if (bodyClasses.includes('production-reports_page_wc-prod-duration-settings')) {
         const handleAjaxAction = (buttonId, statusId, actionName, nonce, confirmMsgKey, successMsgKey, errorMsgKey, deletedMsgKey = null) => {
            $('#' + buttonId).on('click', function() {
                if (confirmMsgKey && !confirm(wc_prod_duration_data.text[confirmMsgKey])) {
                    return;
                }
                const $button = $(this); const $status = $('#' + statusId); const originalText = $button.text();
                $status.text(wc_prod_duration_data.text.processing).removeClass('success error').addClass('loading');
                $button.prop('disabled', true);

                $.ajax({
                    url: wc_prod_duration_data.ajax_url, type: 'POST',
                    data: { action: actionName, nonce: nonce },
                    success: function(response) {
                        if (response.success) {
                            let message = wc_prod_duration_data.text[successMsgKey];
                            if (deletedMsgKey && response.data?.message) {
                                // Silinen kayıt sayısını mesaja ekle
                                const countMatch = response.data.message.match(/\d+/);
                                if (countMatch) {
                                     message = wc_prod_duration_data.text[deletedMsgKey].replace('%d', countMatch[0]);
                                }
                            }
                            $status.text(message).removeClass('loading error').addClass('success');
                        } else {
                            const errorMsg = response.data?.message || 'Unknown error';
                            $status.text(wc_prod_duration_data.text[errorMsgKey].replace('%s', errorMsg)).removeClass('loading success').addClass('error');
                        }
                    },
                    error: function(jqXHR, textStatus) {
                        $status.text(wc_prod_duration_data.text.ajax_error + ' (' + textStatus + ')').removeClass('loading success').addClass('error');
                        console.error("AJAX Error:", textStatus, jqXHR.responseText);
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalText);
                        setTimeout(function() { $status.text('').removeClass('loading success error'); }, 8000);
                    }
                });
            });
        };

        handleAjaxAction('clear-cache', 'cache-clear-status', 'wc_status_duration_clear_cache', wc_prod_duration_data.clear_cache_nonce, null, 'cache_cleared', 'error_occurred');
        handleAjaxAction('clear-old-data', 'data-clear-status', 'wc_status_duration_clear_data', wc_prod_duration_data.clear_data_nonce, 'confirm_delete', 'data_deleted', 'error_occurred', 'data_deleted'); // Deleted msg key eklendi
    }

    // Takvim Sayfası - FullCalendar başlatma kodu artık ilgili PHP sınıfının `calendar_script` metodunda.

}); // jQuery(document).ready sonu