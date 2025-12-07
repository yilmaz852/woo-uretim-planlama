# WooCommerce Üretim Planlama

WooCommerce siparişleri için kapsamlı üretim planlama, takvim, analiz ve raporlama eklentisi. Özellikle **Kitchen Cabinet** üreticileri için tasarlandı.

---

## 📋 İçindekiler

1. [Özellikler](#özellikler)
2. [Kurulum](#kurulum)
3. [Hızlı Başlangıç](#hızlı-başlangıç)
4. [Kullanım Kılavuzu](#kullanım-kılavuzu)
   - [Departman Yönetimi](#1-departman-yönetimi)
   - [Ürün Rotaları](#2-ürün-rotaları-cabinet-tipleri)
   - [Durum Raporu](#3-durum-raporu)
   - [Üretim Programı](#4-üretim-programı)
   - [Takvim](#5-takvim)
   - [Analiz](#6-analiz)
   - [Ayarlar](#7-ayarlar)
5. [Formüller ve Hesaplamalar](#formüller-ve-hesaplamalar)
6. [İş Yükü Simülasyonu](#iş-yükü-simülasyonu)
7. [Sorun Giderme](#sorun-giderme)
8. [Gereksinimler](#gereksinimler)
9. [Teknik Özellikler](#teknik-özellikler)

---

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
- WooCommerce ürün kategorileri ile otomatik eşleştirme

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

---

## Kurulum

### Otomatik Kurulum (Önerilen)

1. WordPress Admin → **Eklentiler → Yeni Ekle**
2. **Eklenti Yükle** butonuna tıklayın
3. `woo-uretim-planlama.zip` dosyasını seçin
4. **Şimdi Yükle** ve ardından **Etkinleştir**

### Manuel Kurulum

1. ZIP dosyasını açın
2. `woo-uretim-planlama` klasörünü `/wp-content/plugins/` dizinine yükleyin
3. WordPress Admin → **Eklentiler** → Eklentiyi etkinleştirin

### Kurulum Sonrası

Eklenti etkinleştirildikten sonra:
- Veritabanı tablosu otomatik oluşturulur (`wp_wup_status_history`)
- Varsayılan departmanlar ve cabinet tipleri yüklenir
- Admin menüsünde **Üretim Planlama** menüsü görünür

---

## Hızlı Başlangıç

### İlk 5 Dakikada Başlayın

**Adım 1: Departmanları Tanımlayın**
```
Üretim Planlama → Departmanlar
```
- Mevcut departmanları inceleyin (Operasyon, Boyahane, Montaj, Kalite, Sevkiyat)
- İşçi sayılarını gerçek değerlerle güncelleyin
- Temel süreleri ayarlayın (dakika cinsinden)

**Adım 2: Cabinet Tiplerini Ayarlayın**
```
Üretim Planlama → Ürün Rotaları
```
- Shaker, SM, Frameless tiplerini inceleyin
- Departman akışlarını kontrol edin
- Ürün kategorilerinizi cabinet tiplerine eşleştirin

**Adım 3: Siparişleri İzlemeye Başlayın**
```
Üretim Planlama → Durum Raporu
```
- Siparişler durumlarını değiştirdikçe veriler otomatik kaydedilir
- Raporları, programı ve takvimi görüntüleyin

---

## Kullanım Kılavuzu

### 1. Departman Yönetimi

**Menü:** Üretim Planlama → Departmanlar

Departmanlar, üretim sürecinizin temel yapı taşlarıdır. Her departman için:

| Alan | Açıklama | Örnek |
|------|----------|-------|
| **Departman Adı** | Departmanın tanımlayıcı ismi | Boyahane |
| **İşçi Sayısı** | Bu departmanda çalışan kişi | 3 |
| **Temel Süre** | Tam kadro ile işlem süresi (dakika) | 180 |
| **Renk** | Görsel ayırt etme için renk kodu | #9b59b6 |
| **Bağlı Durumlar** | Hangi WooCommerce durumları bu departmana ait | Processing |

#### Departman Ekleme/Düzenleme

1. Departman listesinden **Düzenle** butonuna tıklayın veya yeni departman ekleyin
2. Tüm alanları doldurun
3. **Kaydet** butonuna tıklayın

#### İş Yükü Simülasyonu

Sayfanın alt kısmındaki simülasyon bölümü:
- İşçi sayısını değiştirin
- **Simüle Et** butonuna tıklayın
- Mevcut vs yeni iş yükünü karşılaştırın

**Örnek Senaryo:**
- Boyahanede 3 işçi var, 180 dakika süre
- 1 işçiye düşürünce: 180 × (3/1) = 540 dakika

---

### 2. Ürün Rotaları (Cabinet Tipleri)

**Menü:** Üretim Planlama → Ürün Rotaları

Her cabinet tipi farklı bir üretim akışını temsil eder.

#### Varsayılan Cabinet Tipleri

| Cabinet Tipi | Departman Akışı | Süre Çarpanı |
|-------------|-----------------|--------------|
| **Shaker** | Montaj → Kalite → Sevkiyat | ×1.0 |
| **SM (Semi-Custom)** | Boyahane → Montaj → Kalite → Sevkiyat | ×1.0 |
| **Frameless** | Üretim → Boyahane → Montaj → Kalite → Sevkiyat | ×1.5 |
| **Özel Üretim** | Operasyon → Üretim → Boyahane → Montaj → Kalite → Sevkiyat | ×2.0 |

#### Ürün Kategorisi Eşleştirme

WooCommerce ürün kategorilerinizi cabinet tiplerine bağlayın:

1. Sayfanın alt kısmındaki **Ürün Kategorisi Eşleştirme** bölümüne gidin
2. Her kategori için bir cabinet tipi seçin
3. **Eşleştirmeleri Kaydet** butonuna tıklayın

**Örnek:**
| Kategori | Cabinet Tipi |
|----------|--------------|
| Shaker Cabinets | Shaker |
| Modern Frameless | Frameless |
| Semi-Custom | SM |

Bu sayede:
- Ürünleri tek tek işaretlemenize gerek kalmaz
- Alt kategoriler parent'tan miras alır
- Manuel seçim her zaman önceliklidir (override)

---

### 3. Durum Raporu

**Menü:** Üretim Planlama → Durum Raporu

#### Özellikler

- **Filtreler:** Tarih aralığı ve durum bazlı filtreleme
- **İstatistikler:** Ortalama, minimum, maksimum süreler
- **Grafik:** Durum bazında ortalama süre grafiği
- **İş Yükü:** Mevcut açık siparişlerin toplam iş yükü
- **CSV Export:** Verileri dışa aktarma

#### Rapor Nasıl Okunur?

| Sütun | Açıklama |
|-------|----------|
| Durum | WooCommerce sipariş durumu |
| Ortalama | Bu durumda geçirilen ortalama süre |
| Minimum | En kısa kalma süresi |
| Maksimum | En uzun kalma süresi |
| Geçiş Sayısı | Kaç sipariş bu durumdan geçti |

---

### 4. Üretim Programı

**Menü:** Üretim Planlama → Program

Açık siparişler için üretim takvimi ve tahmini tamamlanma tarihleri.

#### Tablo Sütunları

| Sütun | Açıklama |
|-------|----------|
| Sipariş | Sipariş numarası (tıklanabilir) |
| Müşteri | Fatura adresi ismi |
| Cabinet Tipi | Siparişteki ürünlerin tipleri |
| Durum | Mevcut WooCommerce durumu |
| Departman | Şu an hangi departmanda |
| Geçmiş Ort. | Bu siparişin gerçek geçmiş ortalaması |
| Kalan Süre | Tahmini kalan iş süresi |
| Tahmini Bitiş | Tamamlanma tarihi |
| Akış | Kalan departman akışı |

#### Departman Kapasitesi Özeti

Sayfanın üst kısmında departman kapasitesi tablosu:
- Her departmanın işçi sayısı
- İşlem süreleri
- Tek işçi süreleri
- Bağlı durumlar

---

### 5. Takvim

**Menü:** Üretim Planlama → Takvim

FullCalendar entegrasyonu ile görsel planlama.

#### Görünümler

- **Aylık:** Tüm ayı göster
- **Haftalık:** Seçili haftayı göster
- **Liste:** Liste formatında göster

#### Etkinlikler

- Renkler sipariş durumuna göre ayarlanır
- Etkinliklere tıklayarak sipariş detayına gidin
- Tahmini tamamlanma tarihleri takvimde gösterilir

---

### 6. Analiz

**Menü:** Üretim Planlama → Analiz

Gelişmiş grafikler ve analizler.

#### Grafik Türleri

1. **Günlük Ortalama Süre Trendi** (Çizgi Grafik)
   - Son 30 günlük ortalama süre trendi
   - Saat cinsinden gösterim

2. **Durum Dağılımı** (Pasta Grafik)
   - Durumlar arası süre dağılımı
   - Yüzdelik oranlar

3. **Haftalık Analiz** (Çubuk Grafik)
   - Haftanın günlerine göre ortalama süreler
   - Hangi günler daha yoğun?

---

### 7. Ayarlar

**Menü:** Üretim Planlama → Ayarlar

#### Genel Üretim Ayarları

| Ayar | Açıklama | Varsayılan |
|------|----------|------------|
| Toplam Personel | Departmanlardan otomatik hesaplanır | - |
| Günlük Çalışma Saati | Personel başına günlük çalışma | 8 saat |
| Çalışma Günleri | Haftalık çalışma günleri | Pazartesi-Cuma |

#### Bildirim Ayarları

| Ayar | Açıklama | Varsayılan |
|------|----------|------------|
| Bildirimleri Etkinleştir | E-posta bildirimi gönder | Kapalı |
| Eşik Süresi | Bu süreden uzun kalanlar için bildirim | 24 saat |
| Bildirim E-postası | Bildirimlerin gönderileceği adres | Admin e-postası |

#### Performans

| Ayar | Açıklama | Varsayılan |
|------|----------|------------|
| Önbellek Süresi | Rapor verilerinin önbellekte tutulması | 60 dakika |
| Önbelleği Temizle | Manuel önbellek temizleme | - |
| Eski Veri Sil | 6 aydan eski verileri sil | - |

---

## Formüller ve Hesaplamalar

### Departman Süre Hesaplama

```
Temel Süre: Tam kadro ile işlem süresi (dakika)
İşçi Sayısı: Departmandaki çalışan sayısı
Tek İşçi Süresi: Temel Süre × İşçi Sayısı

Örnek:
- Boyahane: 3 işçi, 180 dakika temel süre
- Tek işçi süresi: 180 × 3 = 540 dakika (9 saat)
- 1 işçi ile: 180 × (3/1) = 540 dakika
- 2 işçi ile: 180 × (3/2) = 270 dakika
```

### Sipariş Süre Hesaplama

```
1. Cabinet tipini belirle (ürün kategorisi veya manuel seçim)
2. Rota departmanlarının sürelerini topla
3. Süre çarpanını uygula

Örnek (Frameless):
- Departmanlar: Üretim(180) + Boyahane(180) + Montaj(120) + Kalite(30) + Sevkiyat(30)
- Toplam: 540 dakika
- Çarpan: ×1.5
- Nihai: 540 × 1.5 = 810 dakika (13.5 saat)
```

### Tahmini Tamamlanma Tarihi

```
1. Kalan iş yükü (saniye)
2. Günlük kapasite = Toplam İşçi × Günlük Saat × 3600
3. Çalışma günlerini dikkate alarak tarih hesapla
```

---

## İş Yükü Simülasyonu

### Ne İşe Yarar?

Personel değişikliklerinin etkisini **önceden** görmek için:
- İşçi artırma/azaltma senaryoları
- Darboğaz tespiti
- Kapasite planlama

### Nasıl Kullanılır?

1. **Departmanlar** sayfasına gidin
2. **İş Yükü Simülasyonu** bölümüne inin
3. "Yeni İşçi" sütununda değerleri değiştirin
4. **Simüle Et** butonuna tıklayın
5. Sonuçları karşılaştırın

### Örnek Senaryo

| Departman | Mevcut İşçi | Yeni İşçi | Mevcut İş Yükü | Yeni İş Yükü | Fark |
|-----------|-------------|-----------|----------------|--------------|------|
| Boyahane | 3 | 2 | 5 saat | 7.5 saat | +2.5 saat |
| Montaj | 2 | 3 | 4 saat | 2.67 saat | -1.33 saat |

---

## Sorun Giderme

### Sık Karşılaşılan Sorunlar

**1. Veriler görünmüyor**
- Önbelleği temizleyin (Ayarlar → Önbelleği Temizle)
- WooCommerce siparişleriniz olduğundan emin olun
- Sipariş durumlarını değiştirerek veri oluşturun

**2. Tahmini tarihler çok uzak**
- Departman sürelerini kontrol edin
- İşçi sayılarının doğru olduğundan emin olun
- Çalışma saatlerini kontrol edin

**3. Cabinet tipi belirlenmedi**
- Ürün kategorilerini cabinet tiplerine eşleştirin
- Veya ürünleri düzenleyerek manuel seçim yapın

**4. Grafikler görünmüyor**
- Tarayıcı önbelleğini temizleyin
- JavaScript hatalarını konsol'dan kontrol edin
- Chart.js kütüphanesinin yüklendiğinden emin olun

**5. REST API hatası**
- WordPress permalinks'i yeniden kaydedin
- .htaccess dosyasını kontrol edin

---

## Gereksinimler

| Bileşen | Minimum Versiyon |
|---------|------------------|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| WooCommerce | 6.0+ |
| MySQL | 5.7+ |

### Önerilen Sunucu

- PHP 8.0+
- MySQL 8.0+ veya MariaDB 10.4+
- 256MB PHP memory limit

---

## Teknik Özellikler

### Mimari

- **Modüler sınıf yapısı**: Her özellik ayrı bir sınıf
- **Singleton pattern**: Tek örnek garantisi
- **WordPress Transient API**: Kalıcı önbellek
- **HPOS desteği**: WooCommerce High-Performance Order Storage uyumlu

### Güvenlik

- **CSRF koruması**: Tüm formlar ve AJAX'ta nonce doğrulama
- **SQL injection koruması**: Prepared statements
- **XSS koruması**: Escaping fonksiyonları (esc_html, esc_attr vb.)
- **Yetki kontrolü**: current_user_can() kontrolleri

### Veritabanı

```sql
CREATE TABLE wp_wup_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    changed_at DATETIME NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT 0,
    note TEXT,
    INDEX idx_order_id (order_id),
    INDEX idx_status (status),
    INDEX idx_changed_at (changed_at)
);
```

### REST API Endpoints

| Endpoint | Method | Açıklama |
|----------|--------|----------|
| `/wup/v1/report` | GET | Rapor verisi |
| `/wup/v1/orders/{id}/history` | GET | Sipariş geçmişi |
| `/wup/v1/calendar` | GET | Takvim etkinlikleri |

### Dosya Yapısı

```
woo-uretim-planlama/
├── woo-uretim-planlama.php    # Ana eklenti dosyası
├── README.md                   # Dokümantasyon
├── assets/
│   ├── css/
│   │   └── admin.css          # Admin stilleri
│   └── js/
│       └── admin.js           # Chart.js ve FullCalendar
└── includes/
    ├── class-wup-analytics.php     # Analiz grafikleri
    ├── class-wup-cache.php         # Önbellek yönetimi
    ├── class-wup-calendar.php      # Takvim entegrasyonu
    ├── class-wup-dashboard.php     # Dashboard widget
    ├── class-wup-departments.php   # Departman yönetimi
    ├── class-wup-main.php          # Ana sınıf
    ├── class-wup-product-routes.php# Ürün rotaları
    ├── class-wup-scheduler.php     # Üretim programı
    ├── class-wup-settings.php      # Ayarlar
    └── class-wup-ui.php            # UI yardımcıları
```

---

## Sürüm Geçmişi

### v1.0.0 (Aralık 2024)
- İlk sürüm
- Temel raporlama özellikleri
- Departman yönetimi
- Ürün rotaları (Cabinet tipleri)
- Kategori bazlı otomatik tip belirleme
- İş yükü simülasyonu
- FullCalendar entegrasyonu
- Chart.js grafikleri
- HPOS desteği

---

## Lisans

GPL v2 veya sonrası

---

## Geliştirici

Yilmaz - [GitHub](https://github.com/yilmaz852)

---

## Destek

Sorularınız için GitHub Issues kullanabilirsiniz:
https://github.com/yilmaz852/woo-uretim-planlama/issues
