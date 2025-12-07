/**
 * WooCommerce Üretim Planlama - Admin JavaScript
 */

(function($) {
    'use strict';
    
    // DOM hazır olduğunda
    $(document).ready(function() {
        
        // Sayfa türünü belirle
        var bodyClass = $('body').attr('class') || '';
        
        // Rapor sayfası
        if (bodyClass.indexOf('toplevel_page_wup-report') !== -1) {
            initReportPage();
        }
        
        // Analiz sayfası
        if (bodyClass.indexOf('uretim-planlama_page_wup-analytics') !== -1) {
            initAnalyticsPage();
        }
        
        // Takvim sayfası
        if (bodyClass.indexOf('uretim-planlama_page_wup-calendar') !== -1) {
            initCalendarPage();
        }
        
        // Ayarlar sayfası
        if (bodyClass.indexOf('uretim-planlama_page_wup-settings') !== -1) {
            initSettingsPage();
        }
        
        // Varsayılan tarih değerleri
        initDefaultDates();
    });
    
    /**
     * Varsayılan tarih değerlerini ayarla
     */
    function initDefaultDates() {
        $('input[type="date"]').each(function() {
            var $input = $(this);
            if ($input.val() === '') {
                var name = $input.attr('name') || '';
                
                if (name.indexOf('start') !== -1) {
                    // 30 gün önce
                    var date = new Date();
                    date.setDate(date.getDate() - 30);
                    $input.val(formatDate(date));
                } else if (name.indexOf('end') !== -1) {
                    // Bugün
                    $input.val(formatDate(new Date()));
                }
            }
        });
    }
    
    /**
     * Tarihi YYYY-MM-DD formatına çevir
     */
    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }
    
    /**
     * Süreyi saat formatında göster
     */
    function formatHours(hours) {
        if (!hours || hours < 0) return '0 saat';
        
        var h = Math.floor(hours);
        var m = Math.round((hours - h) * 60);
        
        if (h === 0 && m > 0) {
            return m + ' dk';
        } else if (m === 0) {
            return h + ' saat';
        }
        return h + ' saat ' + m + ' dk';
    }
    
    /**
     * Süreyi formatla (HH:MM:SS)
     */
    function formatDuration(seconds) {
        if (!seconds || seconds < 0) return '00:00:00';
        
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = Math.floor(seconds % 60);
        
        return String(hours).padStart(2, '0') + ':' +
               String(minutes).padStart(2, '0') + ':' +
               String(secs).padStart(2, '0');
    }
    
    /**
     * Rapor sayfası
     */
    function initReportPage() {
        // Rapor grafiği
        if (typeof wupReportData !== 'undefined' && typeof Chart !== 'undefined') {
            var ctx = document.getElementById('reportChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: wupReportData.labels,
                        datasets: [{
                            label: 'Ortalama Süre (saniye)',
                            data: wupReportData.data,
                            backgroundColor: wupReportData.colors,
                            borderColor: wupReportData.colors,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return formatDuration(context.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Saniye'
                                }
                            }
                        }
                    }
                });
            }
        }
    }
    
    /**
     * Analiz sayfası
     */
    function initAnalyticsPage() {
        // Trend grafiği (Saat cinsinden)
        if (typeof wupTrendData !== 'undefined' && typeof Chart !== 'undefined') {
            var ctx = document.getElementById('trendChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: wupTrendData.labels,
                        datasets: wupTrendData.datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + formatHours(context.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Saat'
                                }
                            }
                        }
                    }
                });
            }
        }
        
        // Dağılım grafiği
        if (typeof wupDistributionData !== 'undefined' && typeof Chart !== 'undefined') {
            var ctx = document.getElementById('distributionChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: wupDistributionData.labels,
                        datasets: [{
                            data: wupDistributionData.data,
                            backgroundColor: wupDistributionData.colors
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
        }
        
        // Haftalık grafik (Saat cinsinden)
        if (typeof wupWeekdayData !== 'undefined' && typeof Chart !== 'undefined') {
            var ctx = document.getElementById('weekdayChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: wupWeekdayData.labels,
                        datasets: wupWeekdayData.datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + formatHours(context.raw);
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Saat'
                                }
                            }
                        }
                    }
                });
            }
        }
    }
    
    /**
     * Takvim sayfası
     */
    function initCalendarPage() {
        if (typeof FullCalendar === 'undefined' || typeof wupCalendarConfig === 'undefined') {
            console.error('FullCalendar veya config bulunamadı');
            return;
        }
        
        var calendarEl = document.getElementById('wup-calendar');
        if (!calendarEl) {
            return;
        }
        
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: wupCalendarConfig.locale,
            firstDay: wupCalendarConfig.firstDay,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            buttonText: {
                today: wupCalendarConfig.texts.today,
                month: wupCalendarConfig.texts.month,
                week: wupCalendarConfig.texts.week,
                day: wupCalendarConfig.texts.day,
                list: wupCalendarConfig.texts.list
            },
            events: {
                url: wupCalendarConfig.apiUrl,
                method: 'GET',
                extraParams: function() {
                    return { _wpnonce: wupCalendarConfig.nonce };
                },
                failure: function() {
                    alert(wupCalendarConfig.texts.error);
                }
            },
            loading: function(isLoading) {
                calendarEl.style.opacity = isLoading ? 0.5 : 1;
            },
            eventClick: function(info) {
                info.jsEvent.preventDefault();
                if (info.event.url) {
                    window.open(info.event.url, '_blank');
                }
            },
            eventDidMount: function(info) {
                var props = info.event.extendedProps || {};
                var tooltip = info.event.title;
                
                if (props.status_name) {
                    tooltip += '\n' + wupCalendarConfig.texts.status + ' ' + props.status_name;
                }
                if (props.remaining) {
                    tooltip += '\n' + wupCalendarConfig.texts.remaining + ' ' + props.remaining;
                }
                
                info.el.setAttribute('title', tooltip);
            }
        });
        
        calendar.render();
    }
    
    /**
     * Ayarlar sayfası
     */
    function initSettingsPage() {
        // Önbellek temizle
        $('#wup-clear-cache').on('click', function() {
            var $btn = $(this);
            var $status = $('#wup-cache-status');
            
            $btn.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').text(wupData.texts.processing);
            
            $.ajax({
                url: wupData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wup_clear_cache',
                    nonce: wupData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('loading error').addClass('success').text(response.data.message);
                    } else {
                        $status.removeClass('loading success').addClass('error').text(response.data.message || wupData.texts.error);
                    }
                },
                error: function() {
                    $status.removeClass('loading success').addClass('error').text(wupData.texts.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    setTimeout(function() {
                        $status.text('').removeClass('loading success error');
                    }, 5000);
                }
            });
        });
        
        // Eski veri sil
        $('#wup-clear-old-data').on('click', function() {
            if (!confirm(wupData.texts.confirmDelete)) {
                return;
            }
            
            var $btn = $(this);
            var $status = $('#wup-data-status');
            
            $btn.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').text(wupData.texts.processing);
            
            $.ajax({
                url: wupData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wup_clear_old_data',
                    nonce: wupData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('loading error').addClass('success').text(response.data.message);
                    } else {
                        $status.removeClass('loading success').addClass('error').text(response.data.message || wupData.texts.error);
                    }
                },
                error: function() {
                    $status.removeClass('loading success').addClass('error').text(wupData.texts.error);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    setTimeout(function() {
                        $status.text('').removeClass('loading success error');
                    }, 5000);
                }
            });
        });
    }
    
})(jQuery);
