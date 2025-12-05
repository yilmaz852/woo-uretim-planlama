<?php
/**
 * Önbellek yönetimi için sınıf
 */

if (!defined('ABSPATH')) exit;

class WC_Status_Duration_Cache {

    const CACHE_GROUP = 'wc_prod_duration'; // Eklentiye özel cache grubu

    /**
     * Önbellekten veri getirir veya callback ile oluşturup kaydeder.
     *
     * @param string $key Önbellek anahtarı (gruba özgü).
     * @param callable $callback Veri yoksa çağrılacak fonksiyon.
     * @param array $callback_args Callback fonksiyonuna gönderilecek argümanlar.
     * @param int $expiration Önbellek süresi (saniye). Varsayılan 1 saat.
     * @param bool $force_refresh True ise önbelleği atlayıp callback'i çalıştırır.
     * @param string $group Önbellek grubu (varsayılan olarak sınıf sabiti).
     * @return mixed Önbellekteki veri veya callback'in döndürdüğü veri.
     */
    public function get_cached_data($key, $callback, $callback_args = [], $expiration = 3600, $force_refresh = false, $group = self::CACHE_GROUP) {
        $cache_key = $this->generate_cache_key($key); // Anahtarı gruba özel hale getir
        $cached_data = $force_refresh ? false : wp_cache_get($cache_key, $group);

        if (false !== $cached_data) {
            // Önbellek bulundu, döndür
            return $cached_data;
        }

        // Önbellek yok veya yenileme zorunlu, callback'i çalıştır
        $data = call_user_func_array($callback, $callback_args);

        // Veriyi önbelleğe kaydet (sadece null olmayan veriyi kaydetmek mantıklı olabilir)
        if ($data !== null) {
             wp_cache_set($cache_key, $data, $group, (int)$expiration);
        }

        return $data;
    }

    /**
     * Belirli bir önbellek anahtarını siler.
     * @param string $key Silinecek anahtar.
     * @param string $group Önbellek grubu.
     * @return bool Başarılıysa true.
     */
    public function delete_cache($key, $group = self::CACHE_GROUP) {
        $cache_key = $this->generate_cache_key($key);
        return wp_cache_delete($cache_key, $group);
    }

    /**
     * Belirli bir önbellek grubundaki tüm veriyi temizler.
     * Dikkat: WordPress'in kendi `wp_cache_flush_group` fonksiyonu object cache backend'ine bağlıdır.
     * Bu metot, anahtarları bilinen bir grup için manuel temizleme yapar (eğer backend desteklemiyorsa).
     * Şimdilik sadece `wp_cache_flush` kullanıyoruz, bu tüm önbelleği temizler. Daha spesifik temizleme için
     * transient veya farklı bir cache mekanizması gerekebilir.
     *
     * @param string $group Temizlenecek grup.
     */
    public function clear_cache_group($group = self::CACHE_GROUP) {
        // WordPress'te grup bazında temizleme object cache backend'ine bağlıdır.
        // En güvenli yol tüm WP önbelleğini temizlemek veya transient kullanmaktır.
        // Şimdilik tümünü temizleyelim, çünkü bu eklenti verisi sık değişebilir.
        wp_cache_flush(); // Tüm WordPress object cache'i temizler.
        // Alternatif: Sadece bu gruba ait bilinen anahtarları silmek (daha karmaşık).
    }

    /**
     * Eklentiye ait tüm önbelleği temizler (tüm grupları).
     * Bu genellikle `wp_cache_flush` ile aynı işi görür.
     */
    public function clear_all_plugin_cache() {
        // Tüm WordPress object cache'i temizlemek genellikle yeterlidir.
        wp_cache_flush();
    }


    /**
     * Verilen anahtardan gruba özel bir cache anahtarı oluşturur.
     * @param mixed $key Orijinal anahtar (string veya serialize edilebilir).
     * @return string Oluşturulan cache anahtarı.
     */
    private function generate_cache_key($key) {
        // Anahtarı kısaltmak ve geçerli karakterler kullanmak için md5 kullanabiliriz.
        // Eğer anahtar basit bir string ise doğrudan kullanılabilir.
        if (is_string($key) && strlen($key) < 100 && preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
             return $key; // Basit string anahtarları doğrudan kullan
        }
        // Karmaşık anahtarlar için hash oluştur
        return md5(maybe_serialize($key));
    }

} // Class WC_Status_Duration_Cache sonu
