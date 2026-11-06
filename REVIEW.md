# Kolai API – Yorum / Değerlendirme (Review) Uçları

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Genel yanıt formatı ve hata kodları için [README.md](README.md) dosyasına bakın.

Magento yorumları `review` / `review_detail` tablolarında, puanları ise
`rating_option_vote` tablosunda (birden fazla puanlama kriteri olabilir) saklar.
Bu uçlar o yapıyı okuyup WooCommerce eklentisiyle aynı hafif DTO'yu döner.

## Hata Kodları (Review)

| Kod | HTTP | Ne zaman |
|---|---|---|
| `6000` | 400 | Geçersiz `status` filtresi, geçersiz `modified_after`, geçersiz yorum ID'si |
| `6001` | 404 | Yorum yok ya da isteğin geldiği mağaza görünümünde değil |
| `6003` | 400 | `rating` 1–5 arası bir tam sayı değil |
| `2000` | 400 | Ürün ID'si pozitif bir tam sayı değil |
| `2001` | 404 | Ürün yok |

> `6004 Reviews disabled` rezervdir; Magento modülü bu kodu döndürmez.

## Yetkilendirme

| Uç | Scope |
|---|---|
| `GET /products/{id}/reviews` | `RETRIEVE_REVIEWS` |
| `GET /reviews/{id}` | `RETRIEVE_REVIEW` |

---

## GET /products/{id}/reviews

Bir ürünün yorumlarını **sayfalı** listeler. Varsayılan olarak yalnızca **onaylı**
yorumlar döner. Sıralama: en yeni önce.

### Query parametreleri

| Param | Tip | Default | Açıklama |
|---|---|---|---|
| `page` | int | `1` | 1-tabanlı sayfa indeksi |
| `per_page` | int | `100` | Sayfa başı kayıt; **maksimum 200** |
| `status` | string | `approved` | `approved` / `hold` / `spam` / `trash` / `all` |
| `rating` | int | — | 1–5, yorumun ortalama puanıyla tam eşleşme |
| `modified_after` | ISO-8601 | — | Bu tarihte veya sonra **oluşturulan** yorumlar (Magento'da yorum güncelleme zamanı tutulmaz) |

### Durum eşlemesi

Kolai WooCommerce durum adlarını kullanır; Magento karşılıkları:

| `status` | Magento |
|---|---|
| `approved` | Onaylı (Approved) |
| `hold` | Beklemede (Pending) |
| `spam` | Onaylanmadı (Not Approved) |
| `trash` | Magento'da karşılığı yok — her zaman boş liste döner |
| `all` | Tüm durumlar |

### Pagination

`/products` ile aynı: yanıt gövdesinde `pagination` alanı.

| Alan | Açıklama |
|---|---|
| `pagination.total` | Toplam yorum sayısı |
| `pagination.totalPages` | Toplam sayfa |
| `pagination.page` | Mevcut sayfa |
| `pagination.perPage` | Mevcut sayfa boyutu |

### Request örnekleri

```
# Varsayılan: ilk sayfa, onaylı yorumlar
GET /kolai/v1/products/23/reviews

# 200'erlik 2. sayfa, yalnızca 5 yıldızlılar
GET /kolai/v1/products/23/reviews?page=2&per_page=200&rating=5

# Moderasyon bekleyen yorumlar
GET /kolai/v1/products/23/reviews?status=hold

# Son 24 saatte eklenenler
GET /kolai/v1/products/23/reviews?modified_after=2026-11-05T00:00:00Z
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
      "id": 142,
      "productId": "23",
      "rating": 5,
      "author": "Mehmet K.",
      "content": "Hızlı kargo, kaliteli ürün.",
      "date": "2026-11-01T10:00:00+00:00",
      "status": "approved",
      "verifiedBuyer": true
    }
  ],
  "pagination": {
    "page": 1,
    "perPage": 100,
    "total": 42,
    "totalPages": 1
  }
}
```

### Response (error örnekleri)

Ürün yok:

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

Geçersiz rating filtresi:

```json
{
  "status": "failure",
  "errorCode": "6003",
  "errorMessage": "rating must be between 1 and 5",
  "data": null
}
```

Geçersiz durum filtresi:

```json
{
  "status": "failure",
  "errorCode": "6000",
  "errorMessage": "Invalid status filter; allowed: approved, hold, spam, trash, all",
  "data": null
}
```

---

## GET /reviews/{id}

Tek bir ürün yorumunu getirir. Yorum yoksa, bir ürüne ait değilse ya da isteğin
geldiği mağaza görünümüne atanmamışsa `404 / 6001` döner.

### Request

```
GET /kolai/v1/reviews/142
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
    "id": 142,
    "productId": "23",
    "rating": 5,
    "author": "Mehmet K.",
    "content": "Hızlı kargo, kaliteli ürün.",
    "date": "2026-11-01T10:00:00+00:00",
    "status": "approved",
    "verifiedBuyer": true
  }
}
```

> `pagination` alanı yalnızca liste ucunda döner.

### Response (error)

```json
{
  "status": "failure",
  "errorCode": "6001",
  "errorMessage": "Review not found",
  "data": null
}
```

---

## Alanlar

| Alan | Tip | Açıklama |
|---|---|---|
| `id` | int | Yorum ID'si (`review_id`) |
| `productId` | string | Yorumun ait olduğu ürün ID'si |
| `rating` | int / null | Yorumun tüm puanlama kriterlerindeki oylarının ortalaması, en yakın tam sayıya yuvarlanır (1–5). Oy yoksa `null` |
| `author` | string | Takma ad (`nickname`) |
| `content` | string | Yorum metni (`detail`). Yorum başlığı (`title`) dönmez |
| `date` | ISO-8601 | Oluşturma zamanı, UTC |
| `status` | string | `approved` / `hold` / `spam` (bkz. durum eşlemesi) |
| `verifiedBuyer` | bool | Yorumu yazan **müşteri hesabının** bu ürünü içeren, iptal edilmemiş bir siparişi varsa `true`. Misafir yorumlarında her zaman `false` — Magento'da ayrı bir "doğrulanmış" bayrağı yoktur, bu değer türetilir |

> Magento'da yanıt-yorum ilişkisi olmadığı için `parentId` hiç dönmez.

## Gizlenen Alanlar (PII)

Yorum yapanın e-postası, IP adresi ve User-Agent KVKK/GDPR uyumu için **SQL
sorgusunda bile seçilmez**; yanıta sızmaları mümkün değildir. `customer_id`
yalnızca `verifiedBuyer` hesabı için okunur, yanıtta dönmez.

## Performans Notları

- Sayfa verisi tek bir SQL sorgusudur: yorum + detay + mağaza + ortalama oy
  birlikte çekilir (ayrıca ürün kontrolü ve toplam sayım). Rating filtresi
  ortalama oy üzerinden uygulanır (Magento'nun yorum koleksiyonu bunu
  desteklemediği için doğrudan SQL).
- `verifiedBuyer` sayfadaki tüm yorumlar için **tek** bir sipariş sorgusuyla
  hesaplanır.
- Sayfalama zorunludur (`per_page` ≤ 200).

## Loglama

Log açıksa her sorgu `review` bağlamında süresi (`duration_ms`) ve bulunan kayıt
sayısıyla yazılır. Detay için [LOGS.md](LOGS.md).
