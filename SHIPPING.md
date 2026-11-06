# Kolai API – Kargo (Shipping) Uçları

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Genel yanıt formatı ve hata kodları için [README.md](README.md) dosyasına bakın.

## Hata Kodları (Shipping)

| Kod | HTTP | Ne zaman |
|---|---|---|
| `3000` | 400 | `address.countryId` veya `address.cityId` eksik |
| `3001` | 404 | Adrese uygun kargo yöntemi yok, ya da sepette kargolanabilir ürün yok |
| `2004` | 400 | `products` boş/geçersiz, ya da varyasyon bilgisi hatalı |
| `2001` | 404 | Ürün yok, devre dışı ya da bu website'da değil |
| `4002` | 409 | Ürün stokta yok ya da istenen adet stoktan fazla |

---

## POST /shipment-options

Alias: `POST /shipping-options`

Ürün listesine ve adrese göre uygun kargo seçeneklerini ve fiyatlarını döner.

Teklif, `POST /orders`'ın kullanacağı satırların **aynısıyla** kurulan geçici bir
misafir quote üzerinde Magento'nun kendi kargo hesaplamasıyla (`collectShippingRates`)
yapılır ve quote ardından silinir. Böylece burada sunulan fiyat siparişin alacağı
fiyattır.

### Request

```
POST /kolai/v1/shipment-options
```

```json
{
  "products": [12, 34, 56],
  "address": {
    "countryId": "TR",
    "cityId": "34"
  }
}
```

**Adet ve varyasyon desteği (tavsiye edilir).** `products` içinde sade ID yerine
nesne gönderilebilir. Sade ID listesi geriye dönük uyumludur (adet = 1):

```json
{
  "products": [
    { "productId": 12, "quantity": 2 },
    { "productId": 34, "variationId": 41, "quantity": 1 }
  ],
  "address": { "countryId": "TR", "cityId": "34" }
}
```

| Alan | Açıklama |
|---|---|
| `productId` | Ürün ID'si. Configurable için **üst** ürünün ID'si |
| `variationId` | Configurable alt ürününün ID'si (`GET /products/{id}` → `variations[].id`). Configurable dışındaki ürünlerde gönderilmez |
| `quantity` | Adet, en az 1 (varsayılan 1) |

> - `productId` olarak doğrudan bir configurable **alt ürünü** gönderilirse üst
>   ürünü bulunur ve satır ona çevrilir; `variationId` ayrıca gerekmez.
> - Configurable üst ürün `variationId` olmadan gönderilirse
>   `2004 variationId is required for a product with variants` döner.
> - Bundle ürün varsayılan paket içeriğiyle eklenir.
> - Kargo fiyatının siparişle birebir eşleşmesi için `POST /orders` ile **aynı
>   ürün + adet** listesini gönderin.

Adres alanları Magento'ya şöyle eşlenir:

| Kolai | Magento | Not |
|---|---|---|
| `countryId` | `country_id` | `TR` |
| `cityId` | `region_id` / `region` | Plaka kodu normalize edilir (`34` → `TR34`, `6` → `TR06`). Mağazada TR bölgeleri tanımlıysa (`directory_country_region`) koddan veya addan gerçek `region_id` bulunur; yoksa il adı (`İstanbul`) serbest metin `region` olarak yazılır |

> Bu uç kargo teklifi için yalnızca `countryId` + `cityId` (il) kullanır; ilçe
> (`town`) ve açık adres (`addressLine`) gerekmez. Tablo tabanlı kargo gibi
> posta koduna/şehre bakan yöntemler bu yüzden il düzeyinde fiyatlanır. Tam
> adres alanları için [ORDER.md](ORDER.md#post-orders).

### Kargo yöntemi filtresi

Mağazalar › Yapılandırma › Satışlar › Kolai › **Kargo** › *İzin verilen kargo
yöntemleri* alanında seçim yapılmışsa yalnızca o yöntemler sunulur. Hiçbiri
seçilmemişse mağazada aktif olan tüm yöntemler döner.

> WooCommerce'ten farkı: filtre **sipariş oluşturmada da** uygulanır. İzin
> verilmeyen bir `shipmentOptionId` ile `POST /orders` → `4001` döner. Normal
> Magento çıkış (checkout) akışı etkilenmez.

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
    "options": [
      {
        "id": "flatrate_flatrate",
        "label": "Flat Rate - Fixed",
        "methodId": "flatrate",
        "cost": 10,
        "tax": 2,
        "price": 12
      },
      {
        "id": "freeshipping_freeshipping",
        "label": "Free Shipping - Free",
        "methodId": "freeshipping",
        "cost": 0,
        "tax": 0,
        "price": 0
      }
    ]
  }
}
```

| Alan | Açıklama |
|---|---|
| `id` | Magento rate kodu (`{carrier}_{method}`). `POST /orders` → `shipmentOptionId` |
| `label` | `Taşıyıcı başlığı - Yöntem başlığı` |
| `methodId` | Taşıyıcı kodu (`flatrate`, `tablerate`, `freeshipping`, …) |
| `cost` | KDV **hariç** kargo ücreti |
| `tax` | Kargo KDV'si |
| `price` | KDV **dahil** kargo ücreti |

> `cost` / `price`, mağazanın *Kargo fiyatları KDV dahil* ayarından bağımsız
> olarak her zaman sırasıyla net ve brüt değerdir — ikisi de Magento'nun vergi
> yardımcısından (`Tax\Helper\Data::getShippingPrice`) geçer. Kargo vergi sınıfı
> tanımlı değilse `tax = 0` ve `cost = price` olur.

### Response (no shipping options)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "3001",
  "errorMessage": "No shipping options available",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

Sepetteki tüm ürünler sanal/indirilebilir ise mesaj `No shippable products found` olur.

### Response (invalid address)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "3000",
  "errorMessage": "countryId and cityId are required",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

### Response (invalid product list)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "2004",
  "errorMessage": "Products list is required",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

Diğer `2004` mesajları: `productId is required`, `productId must be a positive integer`,
`quantity must be at least 1`, `Each product entry must be an id or an object`,
`variationId is required for a product with variants`,
`variationId given for a product without variants`,
`variationId does not belong to productId`.

> Doğrulama sırası: önce `products` **yapısı** (`2004`: boş liste, geçersiz ID,
> adet), sonra adres (`3000`), en son katalog (`2001`, varyasyon `2004`'leri,
> stok `4002`).
