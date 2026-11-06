# Kolai – Log Sistemi

Modül, gelen istekleri ve iç adımları ayrı bir tabloda (`kolai_log`) saklayan
yapısal bir log altyapısı içerir. Sorun teşhisinde hangi adımda ne olduğunu
görmek için tasarlandı; **varsayılan olarak kapalıdır** ve açılmadıkça **hiçbir
log yazılmaz** — kapalı logger'ın maliyeti tek bir config okumasıdır.

Magento'nun kendi `var/log/system.log` / `exception.log` dosyaları ayrıca
çalışmaya devam eder; Kolai logu onların yerine geçmez.

## Ayarlar

Mağazalar › Yapılandırma › Satışlar › Kolai › **Loglar** (yalnızca *Varsayılan
Yapılandırma* kapsamında):

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| **Log tutmayı etkinleştir** | Hayır | Ana anahtar |
| **Minimum log seviyesi** | `info` | `debug` / `info` / `warning` / `error`. Altındaki kayıtlar yazılmaz |
| **Saklama süresi (gün)** | `14` | Daha eski kayıtlar saatlik cron ile silinir. `0` = sınırsız (önerilmez) |

Kaydettikten sonra önbellek temizlemek gerekmez; yapılandırma kaydı config
önbelleğini zaten yeniler.

## Yönetim Sayfası

Sistem › Kolai › **Kolai Logları**

- Üstte durum satırı: log kapalıysa *"Log tutma kapalı; yeni kayıt yazılmıyor"*
  ve ayarlara bağlantı, açıksa minimum seviye.
- **Filtreler**: seviye, bağlam, serbest metin (mesaj, veri veya yol içinde arar),
  istek kimliği. Filtreler URL parametresidir — bağlantı paylaşılabilir.
- **Sıfırla**, **Yenile**.
- Sayfalama: sayfa başı 100 kayıt, en yeni önce.
- Her satırda zaman (UTC), seviye, bağlam, istek (metod + yol + istek kimliği),
  mesaj ve süre (`duration_ms`); **veri** açılır kutusunda JSON yük.
- İstek kimliğine tıklamak o isteğin **tüm** kayıtlarını filtreler.
- **Tüm logları temizle** — onay ister, tabloyu boşaltır.

Erişim ACL kaynağı `Iyzico_Kolai::logs` (*Sistem › Kolai Logları*) ile, ayarlar
`Iyzico_Kolai::config` ile korunur.

## Seviyeler

| Seviye | Ne zaman |
|---|---|
| `debug` | Adım adım akış (ör. doğrulanmış sipariş yükü özeti). Üretimde kapatın — bir istek çok sayıda satır üretebilir |
| `info` | Standart akış: istek başladı/bitti, ana servis adımları, ürün/yorum sorgu süreleri |
| `warning` | Beklenen ama dikkat gerektiren durumlar: imza/scope reddi, ürün bulunamadı, doğrulama reddi, taksit farkı atlandı |
| `error` | Beklenmeyen hata. Exception sınıfı, mesajı, dosya:satır ve ilk 8 çağrı çerçevesi (argümanlar olmadan) |

PSR-3 seviyeleri dörde katlanır: `notice` → `info`; `critical`/`alert`/`emergency` → `error`.

## Bağlamlar (context)

| Bağlam | Kaynak | Tipik kayıtlar |
|---|---|---|
| `request` | `Model/Api/Dispatcher` | İstek başladı (uç, parametreler, maskeli sorgu ve gövde), bitti (HTTP durumu, süre), Kolai hatası, beklenmeyen hata, geçersiz JSON gövde |
| `auth` | `kolai-php` `RequestVerifier` | Kimlik bilgisi ayarlı değil, client/scope/imza uyuşmazlığı, tekrar reddi, zaman damgası penceresi, `timestamp` göndermeyen istemci uyarısı |
| `product` | `Model/Product/ProductReader` | Liste sorgusu (sayfa, sayı, sorgu süresi), tek ürün, varyasyonun üst ürüne çözülmesi, varyasyon kırpma, görünmez ürün |
| `review` | `Model/Review/ReviewReader` | Liste sorgusu süresi ve sayıları, yorum bulunamadı |
| `shipping` | `Model/Endpoint/Shipping` | Kargo teklifi istendi (satır sayısı, ülke, il), uygun yöntem yok |
| `order` | `Model/Order/*` | Yük doğrulandı, sipariş oluştu, Magento'nun reddi, yetim sipariş iptali, indirim sapması, durum güncellendi, taksit farkı, son hal reddi, sipariş e-postası hatası |
| `payment` | `Model/Payment/*` | iyzico iade adımları, kısmi/açık işlem, mutabakat gereken adım, iptal sonucu |
| `general` | — | Bağlam öneki taşımayan kayıtlar |

Bağlam, mesajın `kolai.<bağlam>:` önekinden alınır (kütüphanenin kullandığı biçim).

## Veritabanı

`kolai_log` tablosu (`setup:upgrade` ile oluşur):

| Kolon | Görev |
|---|---|
| `log_id` | Otomatik artan birincil anahtar |
| `created_at` | UTC zaman |
| `level` | `debug` / `info` / `warning` / `error` |
| `context` | `auth` / `request` / `product` / … |
| `request_id` | Aynı isteğin tüm satırları için ortak kimlik |
| `method` | HTTP metodu |
| `route` | `/kolai/v1/...` yolu |
| `message` | İnsan okunur özet |
| `data` | JSON yük (en fazla ~16 KB, fazlası kırpılır) |
| `duration_ms` | İlgili adımın süresi |

İndeksler: `created_at`, `level`, `context`, `request_id`.

Log yazımı **ayrı bir veritabanı bağlantısıyla** yapılır: Magento bir işlemi
geri alsa da (ör. başarısız credit memo) o işleme ait log kayıtları kalır.

## Cron

`kolai_cleanup` işi her saat **:17**'de (`default` cron grubu) çalışır:

- Saklama süresini aşan log kayıtlarını siler (`0` ise silmez).
- Süresi dolan imza salt'larını (`kolai_nonce`) siler.

Magento cron'u çalışmıyorsa (`bin/magento cron:run` zamanlanmamışsa) temizlik
yapılmaz ve tablo büyür.

## Güvenlik Notları

- `auth` bağlamında **imza** ve **gizli anahtar** asla loglanmaz.
- İstek gövdeleri ve sorgu parametreleri yazılmadan önce `Kolai\Log\Redactor`
  ile **maskelenir**: ad, e-posta, telefon, TCKN/VKN, adres alanları çıkarılır;
  geriye isteğin **şekli** (hangi anahtarlar, kaç kalem, ne boyutta) kalır.
- Exception kayıtları çağrı argümanlarını içermez (müşteri verisi taşıyabilir).
- Yine de retention süresini kısa tutmanız (KVKK/GDPR) önerilir.

## Sorun Giderme

**Loglar yazılmıyor**
- *Log tutmayı etkinleştir* = Evet mi, ayar **Varsayılan Yapılandırma**
  kapsamında mı kaydedildi?
- İstek hangi seviyede log atıyor? Minimum seviyeyi `debug`'a çekip tekrar deneyin.
- İstek modüle hiç ulaşıyor mu? Log sayfasında hiç `request` kaydı yoksa sorun
  web sunucusu/rota tarafındadır — bkz. [KURULUM.md](KURULUM.md#61-uç-ayakta-mı).

**Tablo yok hatası**
`bin/magento setup:upgrade` çalıştırılmamış.

**Eski kayıtlar silinmiyor**
- Magento cron çalışıyor mu? `cron_schedule` tablosunda `kolai_cleanup` işleri
  `success` durumunda mı?
- Saklama süresi `0` ise temizlik yapılmaz.
