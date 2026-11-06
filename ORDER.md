# Kolai API – Sipariş (Order) Uçları

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Genel yanıt formatı ve hata kodları için [README.md](README.md) dosyasına bakın.

## Hata Kodları (Order)

| Kod | HTTP | Ne zaman |
|---|---|---|
| `4000` | 400 | Geçersiz istek: eksik/geçersiz alan, geçersiz `orderStatus`, son haldeki siparişe durum değişikliği, Magento'nun siparişi reddetmesi |
| `4001` | 400 | `shipmentOptionId` eksik, adrese uygun değil ya da izin verilen kargo yöntemleri arasında değil |
| `4002` | 409 | Ürün stokta yok ya da istenen adet stoktan fazla |
| `4003` | 400 | `discountAmount` sipariş toplamından büyük |
| `3000` | 400 | Fatura veya teslimat adresinde `countryId`/`cityId` eksik |
| `2001` / `2004` | 404 / 400 | Ürün yok / ürün satırı geçersiz (bkz. [SHIPPING.md](SHIPPING.md#response-invalid-product-list)) |
| `1002` | 404 | Sipariş yok ya da Kolai ile oluşturulmamış |

---

## Magento'da durum (status) ve aşama (state)

WooCommerce'te sipariş yalnızca bir duruma (status) sahiptir. Magento'da her
sipariş bir **aşamada** (state: `new`, `pending_payment`, `processing`,
`complete`, `closed`, `canceled`, `holded`, `payment_review`) bulunur ve her
aşamaya bir veya daha fazla **durum** atanır. Kolai yalnızca durum gönderir;
modül aşamayı durumdan türetir:

- Durumun atandığı aşama kullanılır.
- Durum birden fazla aşamaya atanmışsa, siparişin mevcut aşaması bunlardan
  biriyse o korunur; değilse durumun **varsayılan** olduğu aşama seçilir.
- Hiçbir aşamaya atanmamış bir durum yalnızca etiket olarak değişir.

Özel durumlar (Mağazalar › Ayarlar › Sipariş Durumu) `GET /order-types`
listesinde görünür ve atandıkları aşamaya göre davranır.

---

## GET /order-types

Magento'da tanımlı tüm sipariş durumlarını kod → etiket olarak döner. `PATCH
/orders/{orderId}` → `orderStatus` alanında bu kodlar kullanılır.

### Request

```
GET /kolai/v1/order-types
```

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "canceled": "Canceled",
    "closed": "Closed",
    "complete": "Complete",
    "fraud": "Suspected Fraud",
    "holded": "On Hold",
    "payment_review": "Payment Review",
    "pending": "Pending",
    "pending_payment": "Pending Payment",
    "processing": "Processing"
  }
}
```

> Etiketler mağazada tanımlı haliyle döner (yönetici dilinde veya özelleştirilmiş
> olabilir). Kurulu modüllere göre başka durumlar da listelenebilir (ör. PayPal).
> WooCommerce adlarıyla farklar: `cancelled` → **`canceled`**, `completed` →
> **`complete`**, `pending` (ödeme bekliyor) → **`pending_payment`**,
> `on-hold` → **`holded`**.

---

## POST /orders

Dış sistemden sipariş oluşturur. Akış:

1. İstek `kolai-php` doğrulayıcısından geçer (alıcı, fatura tipi/VKN-TCKN, iki
   adres, ürün satırları, `shipmentOptionId`). Bu aşamada hiçbir şey yazılmaz.
2. Satırlar katalogdan çözülür ve geçici bir **misafir quote** kurulur
   (stok burada kontrol edilir).
3. Adresler, kargo yöntemi ve `kolai` ödeme yöntemi atanır, toplamlar toplanır,
   varsa `discountAmount` uygulanır.
4. Magento'nun normal sipariş gönderimi (`QuoteManagement::submit`) çalışır:
   sipariş oluşur, **stok rezerve edilir**.
5. Sipariş `pending_payment` aşama/durumuna alınır; alıcı e-postası mevcut bir
   müşteri hesabıyla eşleşiyorsa sipariş o müşteriye bağlanır.

Sipariş e-postası bu aşamada **gönderilmez**; ödeme `PATCH` ile bildirildiğinde
gider.

> **Yetim sipariş bırakılmaz.** Sipariş satırı oluştuktan sonra bir hata olursa
> sipariş silinmez, **iptal edilir** (stok rezervasyonu Magento'nun normal iptal
> yoluyla serbest kalır) ve hata döner. Sipariş oluşmadan önceki hatalarda geçici
> quote silinir.

### Request

```
POST /kolai/v1/orders
```

```json
{
  "buyer": {
    "email": "john@doe.com",
    "firstName": "John",
    "lastName": "Doe",
    "phone": "+90 555 000 00 00"
  },
  "billingAddress": {
    "countryId": "TR",
    "cityId": "34",
    "town": "Kadıköy",
    "postcode": "34710",
    "addressLine": "Örnek Mah. 1. Sok. No: 2",
    "invoiceType": "company",
    "companyName": "Örnek Ltd. Şti.",
    "taxId": "1234567890",
    "taxOffice": "Kadıköy"
  },
  "shippingAddress": {
    "countryId": "TR",
    "cityId": "34",
    "town": "Kadıköy",
    "postcode": "34710",
    "addressLine": "Örnek Mah. 1. Sok. No: 2"
  },
  "products": [
    { "productId": 66, "quantity": 2 },
    { "productId": 12, "variationId": 101, "quantity": 1 }
  ],
  "shipmentOptionId": "flatrate_flatrate",
  "discountAmount": 25.0
}
```

#### Ürün satırları

Biçim `POST /shipment-options` ile aynıdır (bkz. [SHIPPING.md](SHIPPING.md#post-shipment-options)):

- **Configurable**: `productId` üst ürün, `variationId` alt ürün
  (`GET /products/{id}` → `variations[].id`). Alt ürünün configurable özellik
  değerleri Magento'nun beklediği `super_attribute` seçimine çevrilir. Yalnızca
  alt ürün ID'si `productId` olarak gönderilirse üst ürün bulunur.
- **Bundle**: Paket **varsayılan seçimleriyle** eklenir — mağazadaki sepette
  varsayılanlarla eklenmiş gibi. Paket içeriğini ayrıca göndermeyin. Varsayılan
  seçimi olmayan zorunlu bir seçenek varsa `2004` döner.
- **Sanal/indirilebilir** ürünlerden oluşan bir siparişte kargo adımı atlanır;
  `shipmentOptionId` yine de doğrulayıcı tarafından zorunludur.

#### Adres alanları

`billingAddress` ve `shippingAddress` için aynı:

| Kolai | Magento adres alanı | Not |
|---|---|---|
| `countryId` | `country_id` | Zorunlu |
| `cityId` | `region_id` / `region` | **İl** plaka kodu, zorunlu. `34` → `TR34`; mağazada TR bölgeleri varsa gerçek `region_id`, yoksa il adı |
| `town` | `city` | **İlçe** adı. Geriye dönük uyum için `district` / `districtId` da kabul edilir (ilk dolu olan). Hiçbiri yoksa il adına düşer (`TR01` → `Adana`), boş kalmaz |
| `addressLine` | `street` | **Yalnızca açık adres** (mahalle/sokak/no/daire), olduğu gibi yazılır. İl/ilçe **konmamalıdır**, yoksa adreste tekrar eder |
| `postcode` | `postcode` | Opsiyonel. Magento'nun TR için posta kodu zorunluluğu Kolai siparişlerinde uygulanmaz |
| `companyName` | `company` | Opsiyonel |
| `buyer.firstName` / `lastName` | `firstname` / `lastname` | İki adreste de |
| `buyer.phone` | `telephone` | İki adreste de. `+90`/`0` öneki atılarak 10 haneye normalize edilir; 10 haneye inmeyen numara olduğu gibi kalır |
| `buyer.email` | `email` | İki adreste de |
| `billingAddress.taxId` | `vat_id` | Yalnızca fatura adresinde |

Adresler müşterinin adres defterine **yazılmaz** (`save_in_address_book = 0`).

> **Önemli:** iyzico "Pay with iyzico" (PWI) adresinde ilçe `town` alanında gelir;
> çağıran bunu olduğu gibi iletmeli ve `addressLine`'ı il/ilçe içermeyecek
> şekilde temiz göndermelidir.

Yanlış (`city` il adına düşer, `street` il/ilçe ile kirlenir):

```json
{
  "cityId": "06",
  "addressLine": "Akpınar Mah. Çankaya sokak 1 No: 2 Daire: 3 Kat: Çankaya Ankara /"
}
```

Doğru:

```json
{
  "countryId": "TR",
  "cityId": "06",
  "town": "Çankaya",
  "postcode": "06550",
  "addressLine": "Akpınar Mah. Çankaya sokak 1 No: 2 Daire: 3 Kat"
}
```

Sonuç: `region` = `Ankara` (veya TR bölgelerinden `region_id`), `city` = `Çankaya`,
`street` = `Akpınar Mah. Çankaya sokak 1 No: 2 Daire: 3 Kat`.

#### Fatura alanları (`billingAddress`)

| Alan | Kural |
|---|---|
| `invoiceType` | `personal` veya `company`; opsiyonel, **varsayılanı yok** |
| `taxId` | VKN (10 hane) veya TCKN (11 hane); `""` veya `null` olabilir |
| `invoiceType = company` | `taxId` girildiyse **10** hane olmalı |
| `invoiceType = personal` | `taxId` girildiyse **11** hane olmalı |
| `invoiceType` yok | `taxId` uzunluğu **doğrulanmaz**, fatura tipi kaydedilmez; `taxId` yine `vat_id`'ye yazılır. Fatura tipini her zaman gönderin |
| `companyName`, `taxOffice` | Opsiyonel |

Fatura tipi, VKN/TCKN ve vergi dairesi `kolai_order` tablosuna yazılır ve
sipariş ekranındaki **Kolai** kutusunda görünür; VKN/TCKN ayrıca fatura adresinin
`vat_id` alanındadır.

#### İndirim (`discountAmount`)

Opsiyoneldir; gönderildiğinde sayısal ve `0`'dan büyük olmalıdır, sipariş
toplamını (kargo dahil) aşamaz (`4003`).

Satırlara dağıtılmaz; siparişe **tek bir, vergisiz** "Kolai İndirimi" toplamı
olarak uygulanır. Böylece toplam tam olarak istenen tutar kadar düşer — kalem
bazlı dağıtımın kuruş yuvarlaması oluşmaz. Bu satır yönetimdeki sipariş, fatura,
credit memo ekranlarında, müşteri hesabında, yazdırma sayfalarında ve
e-postalarda görünür.

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "orderId": 1234,
    "orderNumber": "000001234",
    "status": "pending_payment",
    "total": 525.0,
    "currency": "TRY",
    "paymentMethod": "kolai",
    "orderExpireAt": "2026-11-06T18:15:30+00:00"
  }
}
```

| Alan | Açıklama |
|---|---|
| `orderId` | Magento `entity_id` — `GET`/`PATCH /orders/{orderId}` bu değeri kullanır |
| `orderNumber` | Magento sipariş numarası (`increment_id`), mağaza yönetiminde ve e-postalarda görünen |
| `status` | `pending_payment` |
| `total` | Genel toplam (`grand_total`), 2 hane |
| `paymentMethod` | Her zaman `kolai` (yönetimde *Kolai App (iyzico)*) |
| `orderExpireAt` | Aşağıya bakın |

#### `orderExpireAt`

`oluşturma zamanı + Satışlar › Satış › Sipariş Cron Ayarları › Bekleyen ödeme
siparişlerinin ömrü (dakika)` (`sales/orders/delete_pending_after`, varsayılan
**480 dk**). Değer UTC, ISO 8601 (`YYYY-MM-DDTHH:mm:ss+00:00`).

Magento'nun `sales_clean_orders` cron'u bu süreyi aşan `pending_payment`
siparişleri **iptal eder** ve stoğu serbest bırakır. Cron çalışmıyorsa sipariş
süre dolsa da beklemede kalır — bkz. [PATCH: son hal](#son-haldeki-siparişler).

### Response (insufficient stock)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "4002",
  "errorMessage": "Insufficient stock quantity",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

### Response (invalid shipment option)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "4001",
  "errorMessage": "Invalid shipment option",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

### Response (discount exceeds total)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "4003",
  "errorMessage": "Discount exceeds order total",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

### Response (Magento siparişi reddetti)

Magento'nun kendi kuralları (minimum sipariş tutarı, taşıyıcının adresi reddetmesi
vb.) siparişi engellerse `400 / 4000` döner; `errorMessage` Magento'nun
müşteriye gösterdiği metindir:

```json
{
  "status": "failure",
  "errorCode": "4000",
  "errorMessage": "Minimum order amount is ₺100.00",
  "data": null
}
```

Diğer `4000` mesajları: `buyer.email is required`, `buyer.email is invalid`,
`billingAddress.invoiceType must be personal or company`,
`billingAddress.taxId must be 10 digits for company invoice`,
`billingAddress.taxId must be 11 digits for personal invoice`,
`discountAmount must be numeric`, `discountAmount must be greater than 0`.

---

## GET /orders/{orderId}

Siparişi ID (`entity_id`) ile döner. Yalnızca **Kolai ile oluşturulmuş**
(ödeme yöntemi `kolai`) siparişler okunabilir; Kolai imzası mağazadaki başka bir
siparişi okuyamaz.

### Request

```
GET /kolai/v1/orders/1234
```

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "orderId": 1234,
    "orderNumber": "000001234",
    "status": "pending_payment",
    "total": 525.0,
    "currency": "TRY",
    "paymentMethod": "kolai",
    "orderExpireAt": "2026-11-06T18:15:30+00:00",
    "dateCreated": "2026-11-06T10:15:30+00:00",
    "dateModified": "2026-11-06T10:15:31+00:00"
  }
}
```

### Response (order not found)

Sipariş yoksa, ID sayı değilse veya sipariş Kolai siparişi değilse:

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "1002",
  "errorMessage": "Order not found",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

> WooCommerce eklentisi bu durumda `1001` döner; Magento modülü HTTP 404 ile
> `1002` döner.

---

## PATCH /orders/{orderId}

Siparişin durumunu günceller ve isteğe bağlı olarak iyzico ödeme bilgilerini
kaydeder. Yalnızca Kolai siparişlerinde çalışır.

### Request

```
PATCH /kolai/v1/orders/1234
```

```json
{
  "orderStatus": "processing"
}
```

`orderStatus` zorunludur ve `GET /order-types` anahtarlarından biri olmalıdır.

Ödeme bilgileriyle birlikte:

```json
{
  "orderStatus": "processing",
  "paymentId": "123456789",
  "price": 100.00,
  "paidPrice": 110.00,
  "installment": 3,
  "itemTransactions": [
    {
      "itemId": "7ab0ee59-18b4-4e30-9ca8-04197bb34127",
      "paymentTransactionId": "34426266",
      "transactionStatus": 2,
      "price": 100.00,
      "paidPrice": 110.00
    }
  ]
}
```

| Alan | Nereye yazılır | Ne için |
|---|---|---|
| `paymentId` | `kolai_order.payment_id`, faturanın işlem numarası | iyzico iptali, faturalama |
| `itemTransactions` | `kolai_order.item_transactions` (normalize edilmiş JSON) | iyzico iadesi — kalem bazlı `paymentTransactionId` |
| `installment` (> 1) | `kolai_order.installment_count` | Kayıt / görüntüleme |
| `paidPrice` | Sipariş toplamı (aşağıya bakın) | Toplamı tahsil edilen tutarla eşlemek |

### Ne olur? (durum → davranış)

İşlem sırası: son hal kontrolü → ödeme verisinin kaydı → `paidPrice` farkı →
durum değişikliği. Fark toplamı değiştirdiği için faturadan **önce** uygulanır;
fatura düzeltilmiş toplamı tahsil eder.

| Hedef aşama | `paymentId` | Davranış |
|---|---|---|
| `processing` / `complete` | var, sipariş faturalanabilir | **Ödeme kaydı**: Magento'nun capture bildirimi (`registerCaptureNotification`) çalışır — fatura ve işlem numarası `paymentId` olan capture işlemi oluşur, sipariş istenen duruma geçer, **sipariş e-postası gönderilir** |
| `processing` / `complete` | yok | Yalnızca durum/aşama değişir; fatura oluşmaz |
| `canceled` | — | Sipariş Magento'nun iptaliyle iptal edilir (stok serbest kalır). Faturalanmış sipariş iptal edilemez → `4000`, credit memo ile iade edilmelidir |
| diğer | — | Durum ve türetilen aşama atanır, sipariş geçmişine not düşülür |

> **Online iade için fatura şarttır.** Yönetimden *Credit Memo › Refund*
> (online) yalnızca faturası olan siparişte görünür; fatura da yalnızca
> `paymentId` ile ödenmiş durumda bir `PATCH` gelince oluşur. Bkz. [REFUND.md](REFUND.md).

### `paidPrice` farkı (vade farkı / taksit indirimi)

`paidPrice` gönderildiğinde sipariş toplamı iyzico'nun gerçekten tahsil ettiği
tutarla eşlenir. Fark tek bir işaretli tutar olarak (`kolai_installment_fee`)
siparişe eklenir:

- **Pozitif** fark → **Vade Farkı (taksit)** satırı
- **Negatif** fark → **İndirim (taksit)** satırı

Satır sipariş, fatura, credit memo ekranlarında, müşteri hesabında ve
e-postalarda görünür; sipariş geçmişine `Kolai: 3 taksit için vade farkı: 10.00` gibi bir not düşülür.

- **Idempotent**: Önceki fark çıkarılıp yeniden hesaplanır; aynı `paidPrice` iki
  kez gönderilirse fark üst üste binmez.
- **Yalnızca faturadan önce**: Faturalanmış siparişin toplamı değiştirilmez
  (fatura bir belgedir). Daha önce uygulanmış aynı `paidPrice`'ın tekrarı
  sessizce kabul edilir; farklı bir `paidPrice` atlanır ve `warning` loglanır.

### Son haldeki siparişler

`canceled` veya `closed` aşamasındaki sipariş **son haldedir**. `canceled`
siparişe yalnızca tekrar `canceled` kabul edilir; `closed` sipariş **her** durum
değişikliğini (`canceled` dahil) reddeder.

```json
{
  "status": "failure",
  "errorCode": "4000",
  "errorMessage": "Order is canceled and can no longer be updated to processing",
  "data": null
}
```

Asıl önemli senaryo: süresi dolup `sales_clean_orders` cron'unun iptal ettiği
siparişe sonradan ödeme gelmesi. Sipariş ödendiye **çekilmez** (iptal edilmiş
kalemler, faturasız ve rezervasyonsuz bir "ödenmiş" sipariş oluşurdu). Bunun
yerine:

1. İstek `4000` ile reddedilir.
2. `paymentId` gönderildiyse sipariş geçmişine not düşülür:
   *"Kolai: sipariş "canceled" durumundayken ödeme bildirimi geldi (paymentId: …)
   ve reddedildi. Ödemenin Kolai tarafından iade edilmesi beklenir."*
3. Kolai ödemeyi iade eder ve `canceled` gönderir — bu, iyzico'ya **ikinci kez
   gidilmez** (sipariş zaten iptal).

Karar `orderExpireAt` saatine değil siparişin **aşamasına** göre verilir: cron
iptal edene kadar stok rezervedir ve sipariş ödenebilir.

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T10:16:00+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "orderId": 1234,
    "orderNumber": "000001234",
    "status": "processing",
    "total": 535.0,
    "currency": "TRY",
    "paymentMethod": "kolai",
    "orderExpireAt": "2026-11-06T18:15:30+00:00",
    "dateCreated": "2026-11-06T10:15:30+00:00",
    "dateModified": "2026-11-06T10:16:00+00:00"
  }
}
```

### Response (invalid orderStatus)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:16:00+00:00",
  "errorCode": "4000",
  "errorMessage": "Invalid orderStatus: foo",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

Diğer: `orderStatus is required`,
`Order cannot be canceled in its current state; refund it with a credit memo`.

---

## Faturalar ve credit memo'larda Kolai tutarları

- **Kolai İndirimi** ve **Vade Farkı / İndirim (taksit)** ilk faturaya tam olarak
  taşınır.
- Credit memo'da bu tutarlar **son** (siparişin tüm kalemlerini kapatan) credit
  memo'ya taşınır; kısmi credit memo'lar yalnızca kalem tutarlarını içerir.

## Veritabanı

Siparişe ait Kolai verisi `kolai_order` tablosunda (`order_id` birincil anahtar),
tutarlar `sales_order` / `sales_invoice` / `sales_creditmemo` üzerindeki
`kolai_discount_amount` ve `kolai_installment_fee` kolonlarındadır — bkz.
[README.md](README.md#veritabanı).
