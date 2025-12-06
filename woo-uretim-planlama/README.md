# WooCommerce Üretim Planlama

WooCommerce siparişleri için kapsamlı üretim planlama, takvim, analiz ve raporlama eklentisi. Özellikle **Kitchen Cabinet** üreticileri için tasarlandı.

## Özellikler

### 📊 Durum Raporu
- Siparişlerin her durumda geçirdiği sürelerin detaylı analizi
- Ortalama, minimum ve maksimum süreler
- CSV dışa aktarma
- Mevcut iş yükü hesaplama

### 📅 Üretim Programı
- Açık siparişler için tahmini tamamlanma tarihleri
- Cabinet tipine göre üretim süresi hesaplama
- Personel ve çalışma saati bazlı kapasite hesaplama
- Gerçek geçmiş ortalama süre karşılaştırması

### 🏭 Departman Yönetimi
- Özel departmanlar oluşturma (Boyahane, Üretim, Montaj, Kalite, Sevkiyat)
- Her departman için işçi sayısı ve temel süre tanımlama
- WooCommerce durumlarını departmanlara bağlama
- İş yükü simülasyonu (personel değişikliklerinin etkisi)

### 🪑 Ürün Rotaları (Cabinet Tipleri)
- **Shaker**: Sadece montaj gerektiren hazır çerçeveli dolaplar
- **SM (Semi-Custom)**: Boyama gerektiren yarı özel dolaplar
- **Frameless**: Sıfırdan üretilen çerçevesiz modern dolaplar
- **Özel Üretim**: Tamamen özel tasarımlı projeler
- Her tip için farklı departman akışı tanımlama
- Süre çarpanı (örn: Frameless %50 daha uzun)
- WooCommerce ürünlerine cabinet tipi atama

### 🗓️ Takvim
- FullCalendar entegrasyonu
- Aylık, haftalık ve liste görünümleri
- Durum bazlı renk kodlaması
- Tıklanabilir etkinlikler

### 📈 Gelişmiş Analiz
- Günlük ortalama süre trendi (saat cinsinden)
- Durum dağılımı (pasta grafik)
- Haftanın günlerine göre analiz (çubuk grafik)
- Tarih aralığı filtreleme

### ⚙️ Ayarlar
- Günlük çalışma saati
- Çalışma günleri seçimi
- E-posta bildirimleri
- Önbellek yönetimi

### 🎛️ Dashboard Widget
- Son 7 günlük durum özeti
- Açık sipariş sayısı
- Hızlı erişim linki

## Cabinet Üretim Akışı Örneği

| Cabinet Tipi | Departman Akışı | Süre Çarpanı |
|-------------|-----------------|--------------|
| Shaker | Montaj → Kalite → Sevkiyat | ×1.0 |
| SM | Boyahane → Montaj → Kalite → Sevkiyat | ×1.0 |
| Frameless | Üretim → Boyahane → Montaj → Kalite → Sevkiyat | ×1.5 |
| Özel | Operasyon → Üretim → Boyahane → Montaj → Kalite → Sevkiyat | ×2.0 |

## Kurulum

1. Eklenti dosyalarını `/wp-content/plugins/woo-uretim-planlama/` dizinine yükleyin
2. WordPress yönetim panelinden eklentiyi etkinleştirin
3. WooCommerce'in yüklü ve etkin olduğundan emin olun
4. **Üretim Planlama → Departmanlar** sayfasından departmanları yapılandırın
5. **Üretim Planlama → Ürün Rotaları** sayfasından cabinet tiplerini ayarlayın
6. Ürün düzenlerken cabinet tipini seçin

## Gereksinimler

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 6.0+

## Kullanım

1. **Üretim Planlama** menüsüne gidin
2. **Departmanlar** sayfasından departmanları ve işçi sayılarını tanımlayın
3. **Ürün Rotaları** sayfasından cabinet tiplerini ve departman akışlarını ayarlayın
4. WooCommerce ürünlerini düzenleyip cabinet tipini seçin
5. Siparişler durumlarını değiştirdikçe veriler otomatik olarak kaydedilir
6. Raporlar, program ve takvim sayfalarından analizleri görüntüleyin

## HPOS Desteği

Bu eklenti WooCommerce High-Performance Order Storage (HPOS) ile tam uyumludur.

## Lisans

GPL v2 veya sonrası

## Geliştirici

Yilmaz - [GitHub](https://github.com/yilmaz852)
