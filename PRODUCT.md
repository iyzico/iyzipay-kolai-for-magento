# Kolai API – Ürün (Product) Uçları

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Genel yanıt formatı ve hata kodları için [README.md](README.md) dosyasına bakın.

## Hata Kodları (Product)

| Kod | HTTP | Ne zaman |
|---|---|---|
| `2000` | 400 | `{id}` pozitif bir tam sayı değil |
| `2001` | 404 | Ürün yok ya da bu website'a atanmamış |
| `2002` | 404 | Ürün devre dışı veya katalogda/aramada görünür değil |
| `2003` | 404 | Varyasyonun configurable üst ürünü bulunamadı |
| `2004` | 400 | `ids` parametresi geçerli bir ID içermiyor |
| `1001` | 400 | `modified_after` tarih olarak çözülemedi |

---

## Hangi ürünler döner?

Yalnızca müşterinin mağazada ulaşabileceği ürünler: **etkin**, isteğin geldiği
**website'a atanmış** ve görünürlüğü *Katalog*, *Arama* veya *Katalog, Arama*
olanlar. "Tek başına görünmez" (Not Visible Individually) ürünler — tipik olarak
configurable alt ürünleri — listede yer almaz; üst ürünün `variations` alanında
döner.

## Ürün Tipleri

Magento tip kodları, Kolai'nin kurulduğu WooCommerce adlarıyla raporlanır:

| Magento | `productType` | Not |
|---|---|---|
| `simple` | `simple` | |
| `configurable` | `variable` | Alt ürünler `variations` içinde |
| `bundle` | `bundle` | `isBundle: "yes"`, içerik `bundledItems` içinde |
| `virtual` / `downloadable` | `virtual` / `downloadable` | Kargo gerektirmez |
| `grouped` | `grouped` | |

---

## Vergi Alanları (Tax)

Tüm ürün/varyasyon yanıtlarında fiyatlar **KDV dahil** döner ve vergi kırılımı
ayrı alanlarla verilir. Hesaplama Magento'nun kendi `Catalog\Helper\Data::getTaxPrice()`
yardımcısıyla, mağazanın vergi ayarları (*Katalog fiyatları KDV dahil mi*,
vergi kuralları, varsayılan vergi hedefi) ve **misafir (NOT LOGGED IN) müşteri
grubu** için yapılır.

| Alan | Tip | Açıklama |
|---|---|---|
| `price` | string | KDV **dahil** normal fiyat |
| `salePrice` | string | KDV **dahil** indirimli fiyat — yalnızca nihai fiyat normal fiyattan düşükse döner |
| `includedTax` | bool | Ürünün vergi sınıfı varsa (`tax_class_id > 0`) `true` |
| `taxPrice` | string | **Efektif** fiyat üzerindeki vergi tutarı (indirim varsa `salePrice`, yoksa `price`) |
| `taxPercentage` | number | Efektif vergi oranı (%); `taxPrice / net efektif fiyat` üzerinden türetilir |

> **Notlar**
> - Ürünün vergi sınıfı **yoksa**: `includedTax = false`, `taxPrice = "0.00"`,
>   `taxPercentage = 0` döner; `price`/`salePrice` net değerleriyle kalır.
> - "Nihai fiyat" Magento'nun hesapladığı fiyattır: özel fiyat (`special_price`),
>   katalog fiyat kuralı ve kademeli fiyat dahil. İleri tarihli bir özel fiyat
>   nihai fiyata yansımadığı için `salePrice` olarak hiç dönmez.
> - Net (KDV hariç) değer gerekirse `salePrice - taxPrice` (veya indirim yokken
>   `price - taxPrice`) ile hesaplanabilir.

### İndirim Dönemi (`salePriceEffectiveDate`)

Yalnızca müşterinin ödediği fiyat gerçekten özel fiyat (`special_price`) ise ve
hem başlangıç hem bitiş tarihi tanımlıysa döner:

```
"salePriceEffectiveDate": "2026-11-01T00:00:00+00:00/2026-11-30T23:59:59+00:00"
```

Bir katalog kuralı özel fiyatın da altına iniyorsa tarih aralığı dönmez — o
tarihler ilan edilen fiyat hakkında bir şey söylemez.

---

## Değerlendirme Alanları (Rating)

Hem liste hem detay yanıtlarında döner. Kaynak Magento'nun yorum özeti
(`review_entity_summary`) — yalnızca **onaylı** yorumlar, isteğin geldiği mağaza
görünümü için.

| Alan | Tip | Açıklama |
|---|---|---|
| `averageRating` | number | Ortalama puan (0–5), 2 haneye yuvarlanır. Magento yüzdesi / 20. Yorum yoksa `0` |
| `reviewCount` | int | Onaylı yorum sayısı. Yorum yoksa `0` |

> Varyasyonlarda ayrı puan dönmez; puan üst ürün seviyesindedir.

---

## Ölçü ve Ağırlık

| Alan | Kaynak |
|---|---|
| `productWeight` | `weight` özelliği (0 ise dönmez) |
| `productLength` / `productWidth` / `productHeight` | `length` / `width` / `height` kodlu özellikler — Magento'da varsayılan olarak yoktur; **yalnızca bu kodlarla bir özellik tanımlıysa** döner |

---

## GET /products

Ürünleri **sayfalanmış** olarak listeler. Sayfalama zorunludur.

### Query parametreleri

| Param | Tip | Default | Açıklama |
|---|---|---|---|
| `page` | int | `1` | 1-tabanlı sayfa indeksi |
| `per_page` | int | `100` | Sayfa başı kayıt; **maksimum 200** |
| `ids` | csv int[] | — | Yalnızca bu ID'ler (en fazla 200); sayfalama atlanır. `ids=12,34` veya `ids[]=12&ids[]=34` |
| `modified_after` | ISO-8601 | — | `updated_at` bu tarihe eşit veya sonra olan ürünler (artımlı senkron) |

> Son sayfanın ötesi istenirse (`page` > `totalPages`) **boş** `data` döner —
> Magento'nun kendi davranışı olan "son sayfayı tekrar döndürme" uygulanmaz.

### Pagination (Body)

Sayfalama bilgisi HTTP header'da değil, yanıt gövdesinde `data` dizisinin
yanındaki `pagination` alanındadır:

| Alan | Açıklama |
|---|---|
| `pagination.total` | Filtreye uyan toplam ürün sayısı |
| `pagination.totalPages` | Toplam sayfa sayısı |
| `pagination.page` | Mevcut sayfa |
| `pagination.perPage` | Mevcut sayfa boyutu |

### Liste Ürün Şeması (Lite)

Liste yanıtı **özet** alanları içerir. `description`, `additionalImageLinks`,
`attributes`, `variations`, `bundledItems` liste yanıtında yoktur — bunlar için
`GET /products/{id}` veya `GET /products-with-variants/{id}` çağrılır.

```
id, title, link, imageLink, inStock, currency, price, salePrice,
includedTax, taxPrice, taxPercentage, salePriceEffectiveDate,
averageRating, reviewCount, productType, isBundle, gtin, mpn,
productWeight, productLength, productWidth, productHeight
```

> `gtin` ve `mpn` ürünün SKU'sudur. Fiyatı olmayan alanlar (ör. görsel yoksa
> `imageLink`) yanıtta hiç yer almaz.

### Request örnekleri

```
# İlk sayfa (default 100 ürün)
GET /kolai/v1/products

# 200'er ürünlük 3. sayfa
GET /kolai/v1/products?page=3&per_page=200

# Belirli ID'leri toplu çek
GET /kolai/v1/products?ids=12,34,56

# Son 24 saatte değişenler
GET /kolai/v1/products?modified_after=2026-11-05T00:00:00Z
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
  "data": [
    {
      "id": "12",
      "title": "Pamuklu Tişört",
      "link": "https://magaza-adresiniz.com/pamuklu-tisort.html",
      "imageLink": "https://magaza-adresiniz.com/media/catalog/product/t/s/tshirt.jpg",
      "inStock": true,
      "currency": "TRY",
      "price": "120.00",
      "salePrice": "106.80",
      "includedTax": true,
      "taxPrice": "17.80",
      "taxPercentage": 20,
      "averageRating": 4.33,
      "reviewCount": 12,
      "productType": "variable",
      "gtin": "TS-001",
      "mpn": "TS-001",
      "productWeight": "0.5"
    }
  ],
  "pagination": {
    "page": 1,
    "perPage": 200,
    "total": 12000,
    "totalPages": 60
  }
}
```

> **Configurable ve bundle fiyatı listede ve detayda farklı kaynaktan gelir.**
> Listede configurable fiyatı Magento'nun fiyat bilgisinden (en düşük alt ürün)
> okunur; detayda en ucuz **satılabilir** alt üründen hesaplanır. Bundle'da her
> iki uçta da varsayılan paket içeriğinin toplamı kullanılır. Stok durumuna bağlı
> olarak liste ve detay fiyatı nadiren farklı olabilir; sipariş fiyatı için
> detay yanıtı esas alınmalıdır.

### Senkronizasyon Önerisi

1. İlk tam senkron için sayfa sayfa dolaşın — `pagination.totalPages` toplam
   sayfa sayısını verir:
   ```
   GET /products?page=1&per_page=200
   GET /products?page=2&per_page=200
   ...
   ```
2. Detay gerekiyorsa her ürün için `GET /products/{id}`.
3. Sonraki senkronlarda `GET /products?modified_after={son_senkron_zamanı}`.

> Magento'da stok ve fiyat değişiklikleri indeksleyicilerle yansır. İndeksler
> "Zamanlamaya göre güncelle" modundaysa ve cron çalışmıyorsa ürünler stokta yok
> veya eski fiyatla görünebilir (bkz. [KURULUM.md](KURULUM.md#0-ön-koşullar)).

---

## GET /products/{id}

Tek ürünü **tüm detaylarıyla** (açıklama, galeri, özellikler, varyasyonlar,
paket içeriği) getirir.

### Request

```
GET /kolai/v1/products/12
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
    "id": "12",
    "title": "Pamuklu Tişört",
    "description": "<p>%100 pamuk.</p>",
    "link": "https://magaza-adresiniz.com/pamuklu-tisort.html",
    "imageLink": "https://magaza-adresiniz.com/media/catalog/product/t/s/tshirt.jpg",
    "additionalImageLinks": [
      "https://magaza-adresiniz.com/media/catalog/product/t/s/tshirt-2.jpg"
    ],
    "inStock": true,
    "currency": "TRY",
    "price": "120.00",
    "salePrice": "106.80",
    "includedTax": true,
    "taxPrice": "17.80",
    "taxPercentage": 20,
    "salePriceEffectiveDate": "2026-11-01T00:00:00+00:00/2026-11-30T23:59:59+00:00",
    "averageRating": 4.33,
    "reviewCount": 12,
    "productType": "variable",
    "gtin": "TS-001",
    "mpn": "TS-001",
    "productWeight": "0.5",
    "variations": [
      {
        "id": 101,
        "sku": "TS-001-RED-M",
        "description": "Kırmızı / M",
        "price": "120.00",
        "salePrice": "106.80",
        "includedTax": true,
        "taxPrice": "17.80",
        "taxPercentage": 20,
        "inStock": true,
        "attributes": [
          { "id": 11, "name": "Renk", "slug": "color", "value": "Kırmızı" },
          { "id": 22, "name": "Beden", "slug": "size", "value": "M" }
        ],
        "image": {
          "url": "https://magaza-adresiniz.com/media/catalog/product/t/s/tshirt-red.jpg",
          "alt": "Pamuklu Tişört Kırmızı"
        }
      }
    ],
    "attributes": [
      {
        "name": "Renk",
        "slug": "color",
        "type": "taxonomy",
        "visible": true,
        "options": [
          { "id": 11, "name": "Kırmızı", "slug": "kirmizi" }
        ]
      },
      {
        "name": "Materyal",
        "slug": "material",
        "type": "custom",
        "visible": true,
        "options": [{ "name": "Pamuk" }]
      }
    ]
  }
}
```

### Alanlar (detay)

| Alan | Açıklama |
|---|---|
| `description` | `description` özelliği, HTML olduğu gibi |
| `additionalImageLinks` | Medya galerisindeki etkin görseller, ana görsel hariç |
| `variations` | Configurable'ın **etkin** alt ürünleri; diğer tiplerde `[]` |
| `variations[].id` | Alt ürünün (simple) ID'si — `POST /orders` satırında `variationId` |
| `variations[].description` | Configurable özellik değerleri, ` / ` ile birleştirilir |
| `variations[].attributes` | Her configurable özelliğin bu alt üründeki değeri (`id` = seçenek ID'si) |
| `variations[].image` | Alt ürünün kendi görseli varsa |
| `attributes` (`type: "taxonomy"`) | Configurable özellikler ve tüm seçenekleri |
| `attributes` (`type: "custom"`) | *Ürün sayfasında görünür* (`is_visible_on_front`) ve dolu olan diğer özellikler |

> **Notlar**
> - Configurable ürünlerde **en fazla 100 varyasyon** döner. Fazlası varsa üst
>   seviyede `"variationsTruncated": true` ve `"variationsMax": 100` eklenir,
>   `product` bağlamında `warning` log üretilir.
> - Configurable'ın `price` alanları en ucuz satılabilir alt üründen gelir.
> - Bir alt ürün ID'si ile çağrıldığında üst ürüne yönlendirme yapılmaz; alt
>   ürün tek başına görünür değilse `2002` döner. Bunun için
>   `/products-with-variants/{id}` kullanın.

### Bundle ürünler (`bundledItems`)

`productType: "bundle"` olan ürünlerin detay yanıtı (`/products/{id}` ve
`/products-with-variants/{id}`) paketin **varsayılan seçimlerini** `bundledItems`
alanında döner. Liste ucunda bu alan yoktur.

Bu ürünler `POST /orders` ile paket sipariş edildiğinde siparişe otomatik eklenen
satırlardır; sipariş isteğinde ayrıca **gönderilmemelidir**. Kolai paket
seçeneklerini seçemediği için yalnızca varsayılan seçimler kullanılır.

```json
"productType": "bundle",
"isBundle": "yes",
"price": "100.05",
"bundledItems": [
  {
    "id": "456",
    "title": "Mousepad",
    "sku": "MP-01",
    "link": "https://magaza-adresiniz.com/mousepad.html",
    "imageLink": "https://magaza-adresiniz.com/media/catalog/product/m/p/mousepad.jpg",
    "quantity": 1,
    "price": "0.05",
    "pricedIndividually": true,
    "inStock": true
  }
]
```

| Alan | Tip | Açıklama |
|---|---|---|
| `id` | string | Paketteki ürünün ID'si |
| `title` | string | Ürün adı |
| `sku` | string | SKU (boşsa alan dönmez) |
| `link` / `imageLink` | string | Ürün sayfası ve ana görsel |
| `quantity` | int | Bir paket içindeki varsayılan adet |
| `price` | string | Paket içindeki **birim** fiyat, KDV dahil. Sabit fiyatlı pakette fiyatı olmayan seçim için `"0.00"` |
| `pricedIndividually` | bool | Dinamik fiyatlı pakette veya seçimin kendi fiyatı varsa `true`; `false` ise fiyat paketin taban fiyatına dahildir |
| `inStock` | bool | Stok durumu |

Paketin `price` değeri taban fiyat değil, **varsayılan içerikle birlikte**
toplam fiyattır — `POST /orders` ile oluşan sipariş satırlarının toplamıyla aynı.

### Response (error)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T10:15:30+00:00",
  "errorCode": "2001",
  "errorMessage": "Product not found",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

---

## GET /products-with-variants/{id}

Ürün veya varyasyon ID'si ile çağrılır:

- ID bir configurable **alt ürünü** ise, **üst ürün** tüm varyasyonlarıyla döner.
  Alt ürünün yalnızca etkin olması yeterlidir (görünürlüğü üst ürünün işidir);
  üst ürün görünür olmalıdır.
- Aksi halde ürün `GET /products/{id}` ile aynı şekilde döner.

### Request

```
GET /kolai/v1/products-with-variants/101
```

Yanıt yapısı `GET /products/{id}` ile aynıdır.

| Durum | Hata |
|---|---|
| Alt ürün devre dışı | `2002 Product not visible` |
| Üst ürün bulunamadı | `2003 Variation parent product not found` |
| Üst ürün görünür değil | `2002 Variation parent product not visible` |

---

## Performans Notları

- **Toplu sorgu**: Liste, URL rewrite'ları, stok ve yorum özetleri toplu olarak
  bağlanmış tek bir ürün koleksiyonu sorgusundan üretilir. İstisna: sayfadaki her
  **bundle** ürün için paket seçenekleri ayrıca sorgulanır (fiyat varsayılan
  içerikten hesaplandığı için).
- **Sert limit `per_page = 200`**, `ids` en fazla 200 ID.
- **Lite format**: Liste yanıtı galeri, özellik ve varyasyon yüklemez.
- **Varyasyon tavanı**: Ürün başına 100.

Sorgu süreleri `product` bağlamında `duration_ms` ile loglanır — bkz. [LOGS.md](LOGS.md).
