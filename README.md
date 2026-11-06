# Kolai for Magento 2

Kolai API entegrasyonu için Magento 2 modülü: ürün, kargo, sipariş, sözleşme ve
yorum uçları; iyzico iade/iptal; yönetici log ekranı.

Protokol, HMAC doğrulama, adres/ürün/yorum eşleme, sipariş matematiği ve iade
dağıtımı `iyzico/kolai-php` kütüphanesinden gelir — bu modül yalnızca Magento
veri katmanını yazar. Kolai'ye karşı davranış WooCommerce eklentisiyle
(`iyzipay-kolai-for-woocommerce`) aynıdır; Magento'ya özgü farklar ilgili
dökümanlarda ve [aşağıda](#woocommerce-eklentisinden-farklar) özetlenmiştir.

Kurulum ve canlıya alma için: **[KURULUM.md](KURULUM.md)**

## API Genel

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Mağaza kodu URL'de ise: `https://magaza-adresiniz.com/{store_code}/kolai/v1`.
İmza isteğin ham yolu üzerinden hesaplanır — Kolai bu adresle imzalamalıdır
(bkz. [AUTH.md](AUTH.md)).

Tümü JSON istek/yanıt kullanır. Yanıtlar `Content-Type: application/json;
charset=utf-8` ve `Cache-Control: no-store` ile döner.

### Base Response

Tüm uçlar aşağıdaki zarfla döner:

```json
{
  "status": "success",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {}
}
```

- `status`: HTTP durumu < 400 ise `success`, değilse `failure`.
- WooCommerce'teki `woocommerceVersion` / `wordpressVersion` yerine
  `magentoVersion` döner.
- Liste uçlarında (`/products`, `/products/{id}/reviews`) `data`'nın yanında
  `pagination` alanı bulunur.
- Beklenmeyen bir hata `500 / 1000 Unexpected error` olarak döner; asıl mesaj
  (dosya yolu, SQL içerebilir) yanıta konmaz, loga yazılır.

### Error Codes

| Kod | HTTP | Anlam |
|---|---|---|
| **1xxx – Platform** | | |
| `1000` | 500 | Internal error |
| `1001` | 400 | Bad request (geçersiz JSON gövde, geçersiz `modified_after`) |
| `1002` | 404 | Not found (bilinmeyen rota, sipariş yok, aydınlatma metni yok) |
| `1003` | 503 | Service unavailable — entegrasyon kapalı |
| `1004` | 503 | Store inactive — Magento modülünde kullanılmaz (WooCommerce'e özgü) |
| `1005` | 401 | Unauthorized |
| **2xxx – Ürün** | | |
| `2000` | 400 | Invalid product id |
| `2001` | 404 | Product not found |
| `2002` | 404 | Product not visible |
| `2003` | 404 | Variation parent not found |
| `2004` | 400 | Invalid product list |
| **3xxx – Kargo** | | |
| `3000` | 400 | Invalid address |
| `3001` | 404 | No shipping options |
| **4xxx – Sipariş** | | |
| `4000` | 400 | Invalid order request |
| `4001` | 400 | Invalid shipment option |
| `4002` | 409 | Insufficient stock |
| `4003` | 400 | Discount exceeds total |
| **5xxx – Sözleşme** | | |
| `5000` | 400 | Invalid contract type |
| `5001` | 400 | Invalid contract request |
| `5002` | 404 | Contract not found |
| **6xxx – Yorum** | | |
| `6000` | 400 | Invalid review request |
| `6001` | 404 | Review not found |
| `6003` | 400 | Invalid rating |
| `6004` | 400 | Reviews disabled (rezerv, kullanılmaz) |

## Uçlar

Detaylı istek/yanıt örnekleri ve açıklamalar için ilgili dökümana gidin:

| Alan | Döküman | Özet |
|---|---|---|
| **Kimlik Doğrulama** | [AUTH.md](AUTH.md) | HMAC-SHA256 imza doğrulaması, scope eşlemesi, replay koruması |
| **Ürün** | [PRODUCT.md](PRODUCT.md) | `GET /products` (sayfalama + `ids` / `modified_after`), `GET /products/{id}`, `GET /products-with-variants/{id}` |
| **Kargo** | [SHIPPING.md](SHIPPING.md) | `POST /shipment-options` (alias `POST /shipping-options`) |
| **Sipariş** | [ORDER.md](ORDER.md) | `GET /order-types`, `POST /orders`, `GET /orders/{orderId}`, `PATCH /orders/{orderId}` |
| **Sözleşme** | [CONTRACT.md](CONTRACT.md) | `POST /contracts`, `GET /contracts/clarification-text` |
| **Yorum** | [REVIEW.md](REVIEW.md) | `GET /products/{id}/reviews` (sayfalama + `status` / `rating` / `modified_after`), `GET /reviews/{id}` |
| **İade / İptal** | [REFUND.md](REFUND.md) | Credit memo (online) ve sipariş iptalinin iyzico'ya iletilmesi |
| **Loglama** | [LOGS.md](LOGS.md) | Yönetimden açılıp kapatılan, DB tabanlı yapısal log |

| Metod | Yol | Scope |
|---|---|---|
| GET | `/products` | `RETRIEVE_PRODUCTS` |
| GET | `/products/{id}` | `RETRIEVE_PRODUCT` |
| GET | `/products-with-variants/{id}` | `RETRIEVE_PRODUCT_WITH_VARIANTS` |
| GET | `/products/{id}/reviews` | `RETRIEVE_REVIEWS` |
| GET | `/reviews/{id}` | `RETRIEVE_REVIEW` |
| POST | `/shipment-options` (alias `/shipping-options`) | `RETRIEVE_SHIPMENT_OPTIONS` |
| GET | `/order-types` | `RETRIEVE_ORDER_TYPES` |
| POST | `/orders` | `CREATE_ORDER` |
| GET | `/orders/{id}` | `RETRIEVE_ORDER` |
| PATCH | `/orders/{id}` | `UPDATE_ORDER_STATUS` |
| POST | `/contracts` | `RETRIEVE_CONTRACT` |
| GET | `/contracts/clarification-text` | `RETRIEVE_CONTRACT` |

## Yönetim Sayfaları

| Sayfa | Yol | İçerik |
|---|---|---|
| Ayarlar | Mağazalar › Yapılandırma › Satışlar › **Kolai** (kısayol: Sistem › Kolai › Kolai Ayarları) | Genel, Kolai API kimlik bilgileri, iyzico (iade/iptal), satıcı bilgileri, sözleşmeler, kargo, loglar |
| Loglar | Sistem › Kolai › **Kolai Logları** | Filtrelenebilir log tablosu, JSON veri, temizle — bkz. [LOGS.md](LOGS.md) |
| Sipariş | Satışlar › Siparişler › sipariş › **Kolai / iyzico** kutusu | paymentId, fatura bilgileri, kalem işlemleri, iade defteri ve işlemleri, iptal sonucu — bkz. [REFUND.md](REFUND.md) |

| Ayar grubu | Alanlar |
|---|---|
| Genel | Entegrasyon aktif (kapalıyken uçlar `503 / 1003` döner) |
| Kolai API kimlik bilgileri | API anahtarı, gizli anahtar (boşsa tüm istekler reddedilir) |
| iyzico (iade / iptal) | Ortam (sandbox/canlı), API anahtarı, gizli anahtar |
| Satıcı bilgileri | Sözleşmelerdeki `{{seller_*}}` alanları |
| Sözleşmeler | Teslim süresi, cayma süresi, Aydınlatma Metni CMS sayfası, iki sözleşme şablonu |
| Kargo | Kolai'ye sunulacak kargo yöntemleri (boş = hepsi) |
| Loglar | Aç/kapa, minimum seviye, saklama süresi (yalnızca varsayılan kapsam) |

Ayarlar **website** kapsamındadır (Loglar hariç); istek hangi mağaza adresine
gelirse o website'ın ayarları kullanılır.

## WooCommerce Eklentisinden Farklar

Kolai'ye bakan sözleşme aynıdır; aşağıdakiler Magento'nun veri modelinden gelir.

| Konu | Magento davranışı | Detay |
|---|---|---|
| Uç yolu | `/kolai/v1` (WooCommerce: `/wp-json/kolai/v1`) | [AUTH.md](AUTH.md) |
| Zarf | `magentoVersion` alanı | — |
| Ürün tipi | Configurable `productType: "variable"`; varyasyonlar alt ürünler | [PRODUCT.md](PRODUCT.md) |
| Fiyat | Magento vergi ayarlarıyla, misafir müşteri grubu için KDV dahil | [PRODUCT.md](PRODUCT.md#vergi-alanları-tax) |
| Ölçüler | `length`/`width`/`height` yalnızca bu kodlarla özellik varsa | [PRODUCT.md](PRODUCT.md#ölçü-ve-ağırlık) |
| Kargo `id` | Magento rate kodu (`flatrate_flatrate`) | [SHIPPING.md](SHIPPING.md) |
| Kargo filtresi | Sipariş oluşturmada da uygulanır | [SHIPPING.md](SHIPPING.md#kargo-yöntemi-filtresi) |
| Sipariş durumları | `pending_payment`, `processing`, `complete`, `canceled`, `holded`, …; aşama durumdan türetilir | [ORDER.md](ORDER.md) |
| Sipariş oluşturma | Misafir quote; e-posta eşleşirse müşteriye bağlanır, adres defterine yazılmaz | [ORDER.md](ORDER.md#post-orders) |
| `orderNumber` | `increment_id` (`000001234`); `orderId` = `entity_id` | [ORDER.md](ORDER.md) |
| `orderExpireAt` | *Bekleyen ödeme siparişlerinin ömrü* (varsayılan 480 dk), `sales_clean_orders` cron'u iptal eder | [ORDER.md](ORDER.md#orderexpireat) |
| Sipariş bulunamadı | `404 / 1002` (WooCommerce: `1001`) | [ORDER.md](ORDER.md) |
| Ödeme | `PATCH` + `paymentId` → fatura + capture işlemi + sipariş e-postası | [ORDER.md](ORDER.md#patch-ordersorderid) |
| Son hal | `canceled` siparişe yalnızca tekrar `canceled`; `closed` siparişe hiçbir durum kabul edilmez | [ORDER.md](ORDER.md#son-haldeki-siparişler) |
| İndirim / vade farkı | Sipariş seviyesinde ayrı toplam satırları (fee değil) | [ORDER.md](ORDER.md) |
| İade | Faturadan **Credit Memo › Refund** (online); "Refund Offline" iyzico'ya gitmez | [REFUND.md](REFUND.md) |
| Yorum durumu | `approved` / `hold` / `spam`; `trash` her zaman boş; `parentId` yok | [REVIEW.md](REVIEW.md) |
| `verifiedBuyer` | Türetilir: müşteri hesabının bu ürünü içeren iptal edilmemiş siparişi | [REVIEW.md](REVIEW.md#alanlar) |
| Aydınlatma metni | WordPress sayfası yerine CMS sayfası | [CONTRACT.md](CONTRACT.md) |
| Meta alan eşleme | Yok — Kolai verisi `kolai_order` tablosunda | [Veritabanı](#veritabanı) |

## Veritabanı

| Tablo / kolon | Amaç |
|---|---|
| `kolai_nonce` | Tek kullanımlık imza salt'ları (replay koruması) |
| `kolai_order` | paymentId, itemTransactions, iade defteri ve işlemleri, iptal sonucu, taksit sayısı, fatura tipi / VKN-TCKN / vergi dairesi |
| `kolai_log` | Yapısal log |
| `*_kolai_discount_amount`, `*_kolai_installment_fee` | `quote`, `quote_address`, `sales_order`, `sales_invoice`, `sales_creditmemo` üzerinde tutar kolonları (indirim kolonları quote'ta da, taksit farkı yalnızca sipariş/fatura/credit memo'da) |

Ödeme yöntemi kodu `kolai` (*Kolai App (iyzico)*); vitrin ve yönetim çıkışında
**sunulmaz**, siparişler yalnızca `POST /kolai/v1/orders` ile bu yönteme düşer.

## Yapı

```
Iyzico/Kolai/
├── Block/
│   ├── Adminhtml/Logs.php                 # Kolai Logları sayfası
│   ├── Adminhtml/Order/KolaiInfo.php      # Sipariş ekranındaki Kolai / iyzico kutusu
│   ├── Adminhtml/System/Config/ContractField.php  # Sözleşme alanı + yer tutucu düğmeleri
│   └── Sales/Totals.php                   # Kolai İndirimi / Vade Farkı toplam satırları
├── Controller/
│   ├── Router.php                         # /kolai/v1/* → API denetleyicisi
│   ├── Api/Index.php                      # JSON yanıtı yazar
│   └── Adminhtml/Logs/{Index,Clear}.php
├── Cron/Cleanup.php                       # kolai_cleanup: log retention + nonce temizliği
├── Gateway/Command/RefundCommand.php      # Credit memo › Refund (online)
├── Model/
│   ├── Api/                               # Dispatcher, Routes, ApiRequest, Result
│   ├── Auth/NonceStore.php                # kolai_nonce üzerinde atomik salt deposu
│   ├── Config.php, Config/Source/
│   ├── Db/IsolatedConnection.php          # Geri almadan etkilenmeyen yazımlar (defter, log)
│   ├── Endpoint/                          # Products, Reviews, Shipping, Orders, Contracts
│   ├── Log/                               # Logger (PSR-3), LogRepository
│   ├── Order/                             # OrderCreator, OrderUpdater, OrderFormatter, StatusMap, KolaiOrderRepository
│   ├── Payment/                           # RefundService, RefundRecord, CancelService, OrderLock, IyzicoClientFactory, PaymentMethod
│   ├── Product/                           # ProductReader, VariationReader, BundleReader, PriceResolver, CatalogSupport
│   ├── Quote/                             # LineResolver, QuoteBuilder, RegionResolver
│   ├── Review/ReviewReader.php
│   └── Total/                             # Quote/Invoice/Creditmemo Kolai tutarları
├── Observer/
│   ├── CopyDiscountToOrder.php            # sales_model_service_quote_submit_before
│   └── CancelIyzicoPayment.php            # order_cancel_after
├── etc/                                   # system.xml, config.xml, db_schema.xml, di.xml, …
├── view/
│   ├── adminhtml/                         # Log sayfası, Kolai kutusu, sözleşme alanı, toplam satırları
│   └── frontend/                          # Müşteri hesabı, yazdırma ve e-postalarda toplam satırları
├── README.md
├── KURULUM.md
├── AUTH.md
├── PRODUCT.md
├── SHIPPING.md
├── ORDER.md
├── CONTRACT.md
├── REVIEW.md
├── REFUND.md
└── LOGS.md
```

## Sürüm Notları

### 1.0.1
- Dökümantasyon WooCommerce eklentisindeki yapıya ayrıldı: README indeks;
  AUTH, PRODUCT, SHIPPING, ORDER, CONTRACT, REVIEW, REFUND, LOGS ve KURULUM
  Magento'ya özgü istek/yanıt örnekleriyle.
- İade: iyzico iadesi tamamlandıktan sonra Magento credit memo'yu geri alırsa,
  aynı tutarla tekrar deneme artık iyzico'ya **mükerrer iade göndermez**;
  yalnızca credit memo'yu kaydeder. Yeni işlem durumu `awaiting_credit_memo`,
  credit memo'nun kalıcı kaydına göre `success` olarak sonuçlandırılır
  (bkz. [REFUND.md](REFUND.md#credit-memo-geri-alınırsa)).

### 1.0.0
- İlk sürüm. WooCommerce eklentisiyle aynı sözleşmede 12 uç:
  ürün (liste/detay/varyasyonlu), yorum, kargo teklifi, sipariş türleri,
  sipariş oluşturma/okuma/güncelleme, sözleşmeler ve aydınlatma metni.
- HMAC-SHA256 kimlik doğrulama, imzalı `timestamp` ve DB tabanlı tek kullanımlık
  salt ile replay koruması (`kolai-php`).
- Configurable ve bundle (varsayılan seçimler) desteği; KDV dahil fiyat ve vergi
  dökümü.
- Ödeme bildiriminde otomatik fatura + capture; taksit vade farkı / indirimi
  toplam satırı; Kolai indirimi.
- Credit memo (online) ile kısmi/tam iyzico iadesi: kilit, kalıcı iade defteri,
  kaldığı yerden devam, mutabakat işareti. Sipariş iptalinde iyzico iptali.
- Yönetici log ekranı, kişisel veri maskeleme, saatlik temizlik cron'u.
