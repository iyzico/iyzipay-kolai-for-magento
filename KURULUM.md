# iyzico Kolai for Magento 2 — Kurulum ve Canlıya Alma Rehberi

Bu rehber Magento 2 mağazanızı iyzico Kolai platformuna bağlamak için baştan
sona yapılması gerekenleri anlatır. Sonundaki
[Canlıya Alma Checklist](#canliya-alma-checklist) tek tek işaretlenerek
kullanılmak üzere hazırlanmıştır.

---

## 0. Ön Koşullar

| Gereksinim | Minimum | Not |
|---|---|---|
| Magento Open Source / Adobe Commerce | 2.4.x (2.4.9 ile test edildi) | `magento/framework >= 103.0` |
| PHP | 7.4+ | Magento sürümünün desteklediği PHP; 8.4+ ise iyzipay-php notuna bakın |
| `iyzico/kolai-php` | 1.0+ | Protokol ve iş mantığı kütüphanesi |
| `iyzico/iyzipay-php` | **2.0.63+** | Aşağıdaki nota bakın |
| HTTPS | Zorunlu | Kolai yalnızca `https://` uçlara istek atar |
| Magento cron | Çalışıyor olmalı | Bekleyen sipariş iptali, indeksler, log temizliği |

> **iyzipay-php 2.0.63 veya üstü şart.** 2.0.62 ve öncesi PHP 8.4+ üzerinde
> deprecation uyarısı üretir; Magento web isteklerinde bu uyarıyı exception'a
> çevirdiği için yönetimden yapılan online iade ve iptal iyzico'ya **hiç
> ulaşmaz**. CLI'dan yapılan denemeler çalışıyor görünebilir — yanıltıcıdır.

### Cron neden şart?

| Cron işi | Ne yapar | Çalışmazsa |
|---|---|---|
| `sales_clean_orders` | Süresi dolan `pending_payment` siparişleri iptal eder, stoğu serbest bırakır | Ödenmeyen Kolai siparişleri stok tutmaya devam eder |
| İndeksleyiciler (*Zamanlamaya göre güncelle* modunda) | Fiyat/stok indekslerini günceller | Ürünler Kolai'de **stokta yok** veya **eski/0 fiyatla** görünür |
| `kolai_cleanup` | Eski logları ve imza salt'larını siler | `kolai_log` / `kolai_nonce` tabloları büyür |

```bash
bin/magento cron:install
```

### Google Merchant XML

iyzico Merchant Panel'de **Google Merchant XML'inizin girilmiş ve onaylanmış**
olması gerekir. XML üretimi ve gereksinimleri için:
<https://support.google.com/merchants/answer/14987622?hl=tr>

Bu adım tamamlanmadan Kolai ürün eşleştirmesi başlatılamaz.

---

## 1. Modülü Kurun

**Yöntem A — Composer** (paket, kullandığınız Composer deposunda yayınlıysa)

```bash
composer require iyzico/kolai-for-magento
```

**Yöntem B — `app/code`**

Modülü `app/code/Iyzico/Kolai/` altına kopyalayın, bağımlılıkları kurun:

```bash
composer require iyzico/kolai-php "iyzico/iyzipay-php:^2.0.63"
```

**Ardından (iki yöntemde de):**

```bash
bin/magento module:enable Iyzico_Kolai
```

```bash
bin/magento setup:upgrade
```

```bash
bin/magento setup:di:compile
```

```bash
bin/magento setup:static-content:deploy tr_TR en_US
```

```bash
bin/magento cache:flush
```

`setup:di:compile` ve `setup:static-content:deploy` yalnızca **production**
modunda gerekir (yönetimdeki sözleşme yer tutucu düğmelerinin JS'i statik
içeriktir). `setup:upgrade` `kolai_nonce`, `kolai_order`, `kolai_log` tablolarını
ve sipariş/fatura/credit memo tablolarındaki Kolai tutar kolonlarını oluşturur.

**Doğrulama:** Yönetimde *Sistem* menüsü altında **Kolai** bölümü
(*Kolai Logları*, *Kolai Ayarları*) ve Mağazalar › Yapılandırma › Satışlar
altında **Kolai** sekmesi görünmeli.

---

## 2. Kolai Platformunda Mağazayı Tanımlayın

iyzico Merchant Panel → Kolai ayarları:

| Alan | Değer |
|---|---|
| Altyapı sağlayıcısı | **Magento** |
| Kolai API Base URL | Mağaza adresi, ör. `https://alanadiniz.com` |

Kolai uçlara `{Base URL}/kolai/v1/...` biçiminde istek atar.

> **Mağaza kodu URL'de ise** (*Web › URL Seçenekleri › Mağaza kodunu URL'lere
> ekle* = Evet) Base URL mağaza kodunu içermelidir, ör. `https://alanadiniz.com/tr`
> → uçlar `https://alanadiniz.com/tr/kolai/v1/...`. İmza isteğin gerçek yolu
> üzerinden hesaplanır; Base URL ile mağazanın yolu farklıysa tüm istekler
> `Invalid signature` ile reddedilir (bkz. [AUTH.md](AUTH.md)).

Base URL'i girdikten sonra panelden **API Key** ve **Secret Key** üretin. Bu iki
değeri bir sonraki adımda Magento'ya **birebir** gireceksiniz (başında/sonunda
boşluk kalmasın).

---

## 3. Mağazalar › Yapılandırma › Satışlar › Kolai

Tüm gruplar (Loglar hariç) **website** kapsamındadır. Tek website'lı mağazada
*Varsayılan Yapılandırma*'da girmeniz yeterlidir; çok website'lı mağazada her
website'a ayrı anahtar/satıcı bilgisi girilebilir — istek hangi website'ın
adresine gelirse o website'ın ayarları kullanılır.

### Genel

| Alan | Açıklama |
|---|---|
| **Entegrasyon aktif** | Varsayılan *Hayır*. Kapalıyken tüm uçlar `503 / 1003 Kolai integration is disabled` döner |

### Kolai API kimlik bilgileri

| Alan | Açıklama |
|---|---|
| **API anahtarı** | iyzico Merchant Panel'deki Kolai API Key |
| **Gizli anahtar** | iyzico Merchant Panel'deki Kolai Secret Key |

İkisi de şifreli saklanır ve maskeli görünür. Biri boşsa modül **fail-closed**
çalışır: tüm istekler `401 Server credentials not configured` döner.

### iyzico (iade / iptal)

Yönetimden iade/iptal yapılacaksa doldurulmalıdır.

| Alan | Açıklama |
|---|---|
| **Ortam** | Test için `Sandbox`, canlı için **`Canlı`** |
| **API anahtarı** / **Gizli anahtar** | iyzico Merchant Panel API bilgileri |

Bu bilgiler girilmezse sipariş akışı çalışır, ancak yönetimden yapılan online
iade **reddedilir** (credit memo oluşmaz) ve iptal iyzico'ya iletilmez
(bkz. [REFUND.md](REFUND.md)).

### Satıcı bilgileri

Sözleşmelerdeki `{{seller_*}}` alanları — **hepsi doldurulmalı**:
Unvan, Adres, Telefon, E-posta, **VKN**, **MERSİS numarası**. Boş bırakılan alan
sözleşmede yer tutucu olarak görünür.

### Sözleşmeler

- **Tahmini teslim süresi** (varsayılan `30 gün`) ve **Cayma hakkı süresi**
  (varsayılan `14 gün`).
- **Aydınlatma Metni sayfası**: önce İçerik › Sayfalar altında aktif bir CMS
  sayfası oluşturun ve ilgili mağaza görünümüne atayın, sonra burada seçin.
- **Mesafeli Satış Sözleşmesi** ve **Ön Bilgilendirme Formu**: boş bırakılırsa
  paketteki varsayılan metinler kullanılır. Hukuk biriminizle kontrol edip
  gerekirse düzenleyin. Alıcı/sipariş yer tutucularını (`{{buyer_name}}`,
  `{{product_list}}` …) şablonda bırakın — Kolai doldurur.

Detay: [CONTRACT.md](CONTRACT.md).

### Kargo

**İzin verilen kargo yöntemleri**: Kolai'ye sunulacak yöntemler. Hiçbiri
seçilmezse mağazada aktif tüm yöntemler sunulur. Seçim hem
`/shipment-options` hem `POST /orders` için geçerlidir; normal Magento çıkış
akışını etkilemez.

Kargo yöntemlerinin Türkiye için yapılandırıldığından emin olun (Satışlar ›
Kargo Yöntemleri — ör. *Flat Rate* / *Table Rates*, *Belirli ülkelere gönder*
listesinde Türkiye). Kargo teklifi il düzeyinde yapılır (bkz.
[SHIPPING.md](SHIPPING.md)).

### Loglar

Varsayılan kapalı. Devreye alma sırasında açın, bitince kapatın (bkz.
[LOGS.md](LOGS.md)).

**Config'i kaydedin.**

---

## 4. Magento Ayarlarını Gözden Geçirin

| Ayar | Yol | Kolai'ye etkisi |
|---|---|---|
| Bekleyen ödeme siparişlerinin ömrü | Satışlar › Satış › Sipariş Cron Ayarları | `orderExpireAt`; varsayılan 480 dk. Kolai ödeme akışından uzun olmalı |
| Katalog fiyatları KDV dahil / vergi kuralları | Satışlar › Vergi | Ürün uçlarındaki `price` / `taxPrice` (bkz. [PRODUCT.md](PRODUCT.md#vergi-alanları-tax)) |
| Kargo vergi sınıfı | Satışlar › Vergi › Vergi Sınıfları | `/shipment-options` → `tax` |
| Para birimi | Genel › Para Birimi Kurulumu | Yanıtlardaki `currency`; TRY önerilir |
| Minimum sipariş tutarı | Satışlar › Satış › Minimum Sipariş Tutarı | Altındaki Kolai siparişleri `4000` ile reddedilir |
| İndeksleyici modu | Sistem › İndeks Yönetimi | *Zamanlamaya göre* ise cron şart |

---

## 5. Web Sunucusu / CDN

- **`Authorization` başlığı PHP'ye ulaşmalı.** Nginx + PHP-FPM varsayılan olarak
  iletir. Apache + PHP-FPM/FastCGI kurulumlarında başlık düşebilir; bu durumda
  her istek `Missing or invalid authorization header` alır. Apache için:
  ```apache
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```
  (veya Apache 2.4.13+ için `CGIPassAuth On`).
- **Yol yeniden yazılmamalı.** Reverse proxy/CDN `/kolai/v1/...` yolunu
  değiştirmemeli (önek ekleme/çıkarma, sondaki `/` normalizasyonu vb.).
- **Önbelleğe alınmamalı.** Modül `Cache-Control: no-store` gönderir; CDN'in bu
  başlığa uyduğundan, `/kolai/v1/*` için önbelleği atlattığından emin olun.
- **WAF**: `PATCH` metoduna ve JSON gövdeli `POST`'a izin verilmeli.

---

## 6. Kurulumu Doğrulayın

### 6.1 Uç ayakta mı?

Terminalden imzasız bir istek atın:

```bash
curl -i https://alanadiniz.com/kolai/v1/products
```

**Beklenen:** HTTP `401` ve şu gövde:

```json
{
  "status": "failure",
  "errorCode": "1005",
  "errorMessage": "Missing or invalid authorization header",
  "magentoVersion": "2.4.9",
  ...
}
```

Bu yanıt rotanın kayıtlı, entegrasyonun açık ve kimlik doğrulamanın devrede
olduğunu gösterir. Başlık, anahtar kontrolünden **önce** çözümlendiği için bu
yanıt API anahtarlarının girildiğini kanıtlamaz — anahtarları 6.2'deki imzalı
istekle (veya Kolai'den bir test isteğiyle) doğrulayın; boşlarsa imzalı istek
`401 Server credentials not configured` döner.

| Aldığınız yanıt | Anlamı |
|---|---|
| `401` + `Missing or invalid authorization header` | ✅ Doğru |
| `503` + `1003` | *Entegrasyon aktif* = Hayır |
| `404` + Magento'nun HTML 404 sayfası | İstek modüle ulaşmıyor: modül etkin değil, önbellek temizlenmedi ya da mağaza kodu URL'de olmasına rağmen yolda yok |
| `200` + veri | ⛔ Kimlik doğrulama devre dışı — derhal iyzico'ya bildirin |

### 6.2 Uçtan uca test

1. Loglarda *Log tutmayı etkinleştir* = Evet, seviye `debug`, saklama 7 gün →
   kaydedin.
2. Kolai tarafından ürün listeleme / kargo seçenekleri / test siparişi tetikleyin.
3. Sistem › Kolai › **Kolai Logları**'nda `auth` ve `request` kayıtlarını
   kontrol edin (kişisel veriler maskelenir).
4. Devreye alma bittikten sonra **logları kapatın**.

Kontrol edilecekler:
- Ürünler doğru fiyat ve KDV dökümüyle, doğru stok durumuyla geliyor mu?
- Configurable ürünlerin varyasyonları ve bundle içerikleri doğru mu?
- Kargo seçenekleri doğru yöntem/ücretle dönüyor mu?
- Test siparişi Satışlar › Siparişler'de **Pending Payment** olarak oluşuyor mu;
  adres (il → bölge, ilçe → şehir) doğru doluyor mu?
- Ödeme sonrası sipariş **Processing**'e geçiyor, **fatura** oluşuyor ve sipariş
  e-postası gidiyor mu?
- Sipariş toplamı iyzico `paidPrice` ile birebir eşleşiyor mu (taksitte
  "Vade Farkı (taksit)" satırı)?
- Sipariş ekranındaki **Kolai / iyzico** kutusunda `paymentId` ve kalem
  işlemleri görünüyor mu?
- Faturadan açılan credit memo ile **Refund** (online) kısmi iade iyzico'ya
  iletiliyor mu?

---

<a id="canliya-alma-checklist"></a>
## 7. Canlıya Alma Checklist

### A. iyzico Merchant Panel

- [ ] XML alanında **production** XML aktif
- [ ] Google Merchant XML girilmiş ve **onaylanmış**
      ([kılavuz](https://support.google.com/merchants/answer/14987622?hl=tr))
- [ ] **Kolai ile Ürün Satışı** özelliği aktif
- [ ] **Kolai API Base URL** tanımlı (mağaza kodu URL'deyse dahil)
- [ ] **Altyapı sağlayıcısı** = Magento
- [ ] **Kolai API Key** tanımlı
- [ ] **Kolai Secret Key** tanımlı

### B. Magento sunucusu

- [ ] Modül etkin, `setup:upgrade` çalıştı (production'da `di:compile` +
      `static-content:deploy`)
- [ ] `iyzico/iyzipay-php` **2.0.63+** (`composer show iyzico/iyzipay-php`)
- [ ] Site HTTPS üzerinden yayında
- [ ] Magento cron çalışıyor (`bin/magento cron:install`)
- [ ] `Authorization` başlığı PHP'ye ulaşıyor; CDN/proxy `/kolai/v1` yolunu
      değiştirmiyor ve önbelleğe almıyor

### C. Kolai ayarları

- [ ] **Entegrasyon aktif** = Evet
- [ ] **API anahtarı** ve **Gizli anahtar** girildi (platformdakiyle birebir,
      doğru website kapsamında)
- [ ] **iyzico Ortam** = `Canlı`
- [ ] **iyzico API anahtarı** ve **Gizli anahtar** girildi
- [ ] Kargo yöntemleri gözden geçirildi

### D. Sözleşmeler ve satıcı

- [ ] Satıcı unvanı, adresi, telefonu, e-postası girildi
- [ ] **VKN** ve **MERSİS numarası** girildi
- [ ] **Tahmini teslim süresi** ve **cayma hakkı süresi** girildi
- [ ] **Aydınlatma Metni** CMS sayfası aktif, mağaza görünümüne atanmış ve seçildi
- [ ] Mesafeli satış sözleşmesi ve ön bilgilendirme formu kontrol edildi
      (veya varsayılan kabul edildi)

### E. Doğrulama

- [ ] `curl https://alanadiniz.com/kolai/v1/products` → `401` +
      `Missing or invalid authorization header`
- [ ] Kolai'den ürün listeleme başarılı, fiyat/stok doğru
- [ ] Kargo seçenekleri doğru dönüyor
- [ ] Test siparişi oluştu, adres ve toplam doğru; ödeme sonrası faturalandı
- [ ] Test iadesi iyzico'ya iletildi
- [ ] Devreye alma sonrası loglar kapatıldı

---

## 8. Canlıya Alma Talebi

Yukarıdaki tüm maddeler tamamlandığında **entegrasyon@iyzico.com** adresine
canlıya alınabilir bilgisini gönderin.

**Örnek e-posta:**

> **Konu:** Kolai canlıya alma talebi — [Mağaza Adı] / [Merchant ID]
>
> Merhaba,
>
> Kolai Magento 2 entegrasyonu için gerekli tüm adımları tamamladık:
>
> - Merchant Panel: production XML aktif, Google Merchant XML onaylı, Kolai ile
>   Ürün Satışı aktif, Base URL + altyapı sağlayıcısı + API/Secret Key tanımlı.
> - Magento: iyzico Kolai for Magento v1.0.1 kurulu ve aktif; Kolai API
>   anahtarları, iyzico iade/iptal anahtarları (ortam: canlı), satıcı ve sözleşme
>   bilgileri (VKN/MERSİS, teslim ve cayma süreleri, aydınlatma metni) girildi.
> - Doğrulama: `/kolai/v1` uçları imza doğrulamasıyla yanıt veriyor; test ürün
>   listeleme, kargo seçenekleri, sipariş ve iade akışları başarılı.
>
> Mağaza URL: https://alanadiniz.com
> Merchant ID: ...
>
> Canlıya alınabilir. Teşekkürler.

---

## 9. Sorun Giderme

| Belirti | Olası neden | Çözüm |
|---|---|---|
| Tüm istekler `Invalid signature` | Kolai'nin imzaladığı yol ile isteğin vardığı yol farklı (mağaza kodu, proxy yeniden yazımı, `/index.php/`) ya da gizli anahtar yanlış | Base URL'i mağazanın gerçek adresiyle eşleyin; gizli anahtarı birebir kopyalayın |
| Tüm istekler `Missing or invalid authorization header` (imzalı olsalar da) | Web sunucusu `Authorization` başlığını düşürüyor | [5. bölüm](#5-web-sunucusu--cdn) |
| `Server credentials not configured` | API anahtarı veya gizli anahtar boş | Doğru website kapsamında girin |
| `Invalid client credentials` | API anahtarı platformdakiyle uyuşmuyor | Değeri birebir kopyalayın |
| `Replayed request rejected` | Aynı istek aynı `salt` ile tekrar gönderildi | Normal koruma; istemci her istekte yeni salt üretmeli |
| `Request timestamp outside allowed window` | Sunucu saati kaymış | NTP ile senkronlayın (±300 sn) |
| `503 / 1003` | Entegrasyon kapalı | *Genel › Entegrasyon aktif* = Evet |
| Ürünler stokta yok / fiyat 0 ya da eski | İndeksler güncel değil | Cron'u çalıştırın veya `bin/magento indexer:reindex` |
| Ürün listede yok | Ürün devre dışı, bu website'a atanmamış ya da "Tek başına görünmez" | Ürün ayarlarını kontrol edin; alt ürünler üst ürünün `variations` alanında döner |
| `3001 No shipping options` | Kargo yöntemi Türkiye'ye kapalı ya da *İzin verilen kargo yöntemleri* dışında | Kargo yöntemi ayarlarını kontrol edin |
| Sipariş birkaç saat sonra kendiliğinden iptal oluyor | *Bekleyen ödeme siparişlerinin ömrü* doldu | Beklenen davranış; süreyi gerekirse uzatın |
| Ödenmeyen siparişler hiç iptal olmuyor | Magento cron çalışmıyor | `bin/magento cron:install` |
| Sipariş oluşunca e-posta gitmiyor | Beklenen: e-posta ödeme `PATCH` ile bildirilince gider | — |
| Faturada **Refund** (online) düğmesi yok | Sipariş faturalanmamış (`paymentId` ile ödendi `PATCH`'i gelmemiş) ya da credit memo sipariş ekranından açıldı | Credit memo'yu faturanın ekranından açın; Kolai kutusunda `paymentId`'yi kontrol edin |
| Online iade/iptal iyzico'ya gitmiyor, PHP 8.4+ | iyzipay-php < 2.0.63 | `composer require "iyzico/iyzipay-php:^2.0.63"` |
| İade *"itemTransactions bulunamadı"* | `PATCH` ile `itemTransactions` gönderilmemiş | Kolai tarafını kontrol edin; gerekirse çevrimdışı iade |
| Adreste il serbest metin, bölge seçili değil | Mağazada TR bölgeleri tanımlı değil | Normal; il adı `region` alanına yazılır. TR bölgeleri eklenirse `region_id` eşlenir |
| Loglar boş | Log tutma kapalı | Loglar › *Log tutmayı etkinleştir* (Varsayılan Yapılandırma kapsamında) |

Detaylı teknik dokümantasyon: [README.md](README.md) ve
[AUTH.md](AUTH.md) / [PRODUCT.md](PRODUCT.md) / [SHIPPING.md](SHIPPING.md) /
[ORDER.md](ORDER.md) / [CONTRACT.md](CONTRACT.md) / [REVIEW.md](REVIEW.md) /
[REFUND.md](REFUND.md) / [LOGS.md](LOGS.md).
