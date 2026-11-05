# Kolai for Magento 2

Kolai API entegrasyonu: ürün, kargo, sipariş, sözleşme ve yorum uçları; iyzico
iade/iptal; yönetici log ekranı. Protokol, HMAC doğrulama, adres/ürün/yorum
eşleme, sipariş matematiği ve iade dağıtımı `iyzico/kolai-php` kütüphanesinden
gelir — bu modül yalnızca Magento veri katmanını yazar. Davranış
WooCommerce eklentisiyle (`iyzipay-kolai-for-woocommerce`) aynıdır; farklar
aşağıda.

## Kurulum

```bash
composer require iyzico/kolai-php "iyzico/iyzipay-php:^2.0.63"
bin/magento module:enable Iyzico_Kolai
bin/magento setup:upgrade
bin/magento setup:di:compile   # production modunda
bin/magento cache:flush
```

> iyzipay-php **2.0.63 veya üstü** gerekir. 2.0.62 ve öncesi PHP 8.4+ üzerinde
> deprecation uyarısı üretir; Magento bu uyarıyı exception'a çevirdiği için
> admin panelden yapılan online iade ve iptal iyzico'ya hiç ulaşmaz.

Mağazalar › Yapılandırma › Satışlar › **Kolai**:

| Grup | Alanlar |
|---|---|
| Genel | Entegrasyon aktif (kapalıyken uçlar `503 / 1003` döner) |
| Kolai API kimlik bilgileri | API anahtarı, gizli anahtar (boşsa tüm istekler reddedilir) |
| iyzico (iade / iptal) | Ortam (sandbox/canlı), API anahtarı, gizli anahtar |
| Satıcı bilgileri | Sözleşmelerdeki `{{seller_*}}` alanları |
| Sözleşmeler | Teslim süresi, cayma süresi, Aydınlatma Metni CMS sayfası, iki sözleşme şablonu |
| Kargo | Kolai'ye sunulacak kargo yöntemleri (boş = hepsi) |
| Loglar | Aç/kapa, minimum seviye, saklama süresi |

Ayarlar website kapsamındadır; istek hangi mağaza adresine gelirse o website'ın
ayarları kullanılır.

## API

Base URL: `https://magaza-adresiniz/kolai/v1` (mağaza kodu URL'de ise
`/{store_code}/kolai/v1`). İmza `uriPath` olarak isteğin ham yolunu kullanır —
Kolai tarafında bu base URL ile imzalanmalıdır.

| Metod | Yol | Scope |
|---|---|---|
| GET | `/products` (`page`, `per_page` ≤ 200, `ids`, `modified_after`) | `RETRIEVE_PRODUCTS` |
| GET | `/products/{id}` | `RETRIEVE_PRODUCT` |
| GET | `/products-with-variants/{id}` | `RETRIEVE_PRODUCT_WITH_VARIANTS` |
| GET | `/products/{id}/reviews` (`page`, `per_page`, `status`, `rating`, `modified_after`) | `RETRIEVE_REVIEWS` |
| GET | `/reviews/{id}` | `RETRIEVE_REVIEW` |
| POST | `/shipment-options` (alias `/shipping-options`) | `RETRIEVE_SHIPMENT_OPTIONS` |
| GET | `/order-types` | `RETRIEVE_ORDER_TYPES` |
| POST | `/orders` | `CREATE_ORDER` |
| GET | `/orders/{id}` | `RETRIEVE_ORDER` |
| PATCH | `/orders/{id}` | `UPDATE_ORDER_STATUS` |
| POST | `/contracts` | `RETRIEVE_CONTRACT` |
| GET | `/contracts/clarification-text` | `RETRIEVE_CONTRACT` |

İstek/yanıt şekilleri ve hata kodları WooCommerce eklentisinin
`PRODUCT.md` / `SHIPPING.md` / `ORDER.md` / `CONTRACT.md` / `REVIEW.md`
dokümanlarıyla aynıdır. Zarfta `woocommerceVersion` / `wordpressVersion`
yerine `magentoVersion` döner.

## Magento'ya özgü davranış

**Ürünler**
- Configurable ürün `productType: "variable"` olarak döner; varyasyonlar alt
  ürünlerdir (en fazla 100, fazlası `variationsTruncated`).
- Fiyatlar KDV dahil, Magento'nun vergi ayarlarıyla (`Catalog\Helper\Data::getTaxPrice`)
  ve misafir müşteri grubu için hesaplanır. `salePrice` yalnızca nihai fiyat
  normal fiyattan düşükse döner.
- Listede configurable/bundle fiyatı Magento fiyat bilgisinden (en düşük)
  gelir; detayda en ucuz satılabilir alt üründen / varsayılan paket içeriğinden
  hesaplanır. İkisi nadiren farklı olabilir.
- Uzunluk/genişlik/yükseklik yalnızca bu kodlarla özellik tanımlıysa döner.
- Yorumlarda `status`: `approved`=Onaylı, `hold`=Beklemede, `spam`=Onaylanmadı;
  `trash` her zaman boş döner. `verifiedBuyer`: yorumcunun bu ürünü içeren
  iptal edilmemiş bir siparişi var mı. Magento'da yanıt-yorum ilişkisi olmadığı
  için `parentId` dönmez.

**Siparişler**
- `POST /orders` misafir quote ile oluşturulur; e-posta mevcut bir müşteriyle
  eşleşirse sipariş sonradan o müşteriye bağlanır (müşterinin adres defterine
  yazılmaz). Sipariş `pending_payment` durumunda oluşur, stok rezerve edilir.
- `orderExpireAt` = oluşturma + `Satışlar › Siparişler › Bekleyen ödeme
  siparişlerinin ömrü` (varsayılan 480 dk). Magento'nun `sales_clean_orders`
  cron'u süresi dolan siparişi iptal eder ve stoğu serbest bırakır.
- `discountAmount` vergisiz tek bir "Kolai İndirimi" toplamı olarak uygulanır.
- `PATCH` ile `paymentId` + ödenmiş durum (`processing`/`complete`) gelirse
  sipariş faturalanır (`registerCaptureNotification`, işlem no = `paymentId`)
  ve sipariş e-postası o anda gönderilir. Online iade için fatura şarttır.
- `paidPrice` farkı "Vade Farkı (taksit)" / "İndirim (taksit)" toplamı olarak
  siparişe eklenir; aynı `paidPrice` tekrar gönderilirse üst üste binmez.
  Faturalanmış siparişte değiştirilemez.
- `canceled`: yalnızca Magento'nun iptale izin verdiği (faturalanmamış)
  siparişlerde; faturalanmışsa credit memo ile iade edilmelidir.
- İptal edilmiş (`canceled`) ya da kapanmış (`closed`) sipariş son haldedir:
  yalnızca tekrar `canceled` kabul edilir, başka her durum `4000` ile
  reddedilir. Süresi dolup cron'un iptal ettiği siparişe sonradan ödeme
  gelirse sipariş ödendiye çekilmez; ret sipariş notuna `paymentId` ile
  yazılır, Kolai ödemeyi iade edip `canceled` gönderir (iyzico'ya ikinci kez
  gidilmez). Karar `orderExpireAt` saatine değil sipariş durumuna göre
  verilir: cron iptal edene kadar stok rezervedir ve sipariş ödenebilir.
- `GET`/`PATCH` yalnızca Kolai ile oluşmuş siparişlerde çalışır.
- Kolai indirimi ve vade farkı faturaya ilk faturada, credit memo'ya son
  (tüm kalemleri kapatan) credit memo'da taşınır.

**İade / İptal**
- Sipariş › Fatura › Credit Memo › **Refund** (online) iyzico'ya gider.
  "Refund Offline" yalnızca Magento kaydı oluşturur.
- Tutar kayıtlı `itemTransactions` kalemlerine dağıtılır (kolai-php
  `RefundAllocator`); her başarılı adımdan sonra iade defteri ayrı bir DB
  bağlantısıyla yazılır, böylece credit memo geri alınsa da defter kalır ve
  tekrar deneme mükerrer iade yapmaz.
- Kısmi başarısızlıkta credit memo oluşmaz; **aynı tutarla** tekrar denemek
  yalnızca kalan kısmı gönderir. iyzico'dan yanıt alınamayan adım
  `needs_reconciliation` olarak işaretlenir — iyzico panelinden kontrol
  edilmeden tekrar denenmemelidir.
- Siparişi iptal etmek (faturalanmamış, `paymentId` kayıtlı) iyzico iptalini
  tetikler; iyzico yalnızca ödeme günü iptal kabul eder. iyzico'nun reddi
  Magento iptalini engellemez, sipariş notuna yazılır.
- Sipariş ekranındaki **Kolai** kutusu paymentId, kalem işlemleri, iade defteri,
  iade işlemleri ve iptal sonucunu gösterir.

**Loglar**
- Sistem › Kolai › **Kolai Logları**: seviye/bağlam/arama/istek kimliği
  filtreleri, JSON veri, temizle. Kişisel veriler (`Kolai\Log\Redactor`)
  maskelenir, imza ve anahtarlar hiç yazılmaz.
- Saklama süresini aşan kayıtlar ve süresi dolan imza salt'ları saatlik
  `kolai_cleanup` cron'u ile silinir.

## Veritabanı

| Tablo / kolon | Amaç |
|---|---|
| `kolai_nonce` | Tek kullanımlık imza salt'ları (replay koruması) |
| `kolai_order` | paymentId, itemTransactions, iade defteri ve işlemleri, iptal sonucu, fatura tipi / VKN-TCKN / vergi dairesi |
| `kolai_log` | Yapısal log |
| `*_kolai_discount_amount`, `*_kolai_installment_fee` | quote, quote_address, sales_order, sales_invoice, sales_creditmemo üzerinde tutar kolonları |
