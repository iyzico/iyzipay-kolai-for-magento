# Kolai API – Kimlik Doğrulama

## Genel Bakış

Tüm `/kolai/v1` uçları HMAC-SHA256 imza doğrulaması ile korunur. Kolai (iyzipay
shoplist, Java) her isteği `IYZ-TP-v2` yetkilendirme şemasıyla imzalar; modül
isteği işlemeden önce imzayı doğrular. Doğrulama mantığı `iyzico/kolai-php`
kütüphanesindeki `Kolai\Auth\RequestVerifier` sınıfındadır — WooCommerce
eklentisiyle birebir aynı algoritma.

Doğrulama sırası:

1. Rota eşleşmesi (yoksa `404 / 1002`)
2. Entegrasyon aktif mi (kapalıysa `503 / 1003`)
3. İmza, scope, tekrar koruması (başarısızsa `401 / 1005`)

## Yapılandırma

Mağazalar › Yapılandırma › Satışlar › **Kolai** › *Kolai API kimlik bilgileri*:

| Config path | Görevi |
|---|---|
| `kolai/api/api_key` | Client ID — arayan sistemi tanımlar |
| `kolai/api/secret_key` | Paylaşılan gizli anahtar — HMAC hesaplamada kullanılır |

İki alan da `obscure` tipindedir ve `core_config_data` tablosunda Magento'nun
şifreleme anahtarıyla **şifreli** saklanır. Ayarlar **website** kapsamındadır:
istek hangi website'ın adresine gelirse o website'ın anahtarları kullanılır.

## Header Formatı

```
Authorization: IYZ-TP-v2 {base64_payload}
```

Base64 payload çözüldüğünde:

```
clientId:{değer}&salt:{değer}&scope:{değer}&timestamp:{değer}&signature:{değer}
```

| Alan | Açıklama |
|---|---|
| `clientId` | `kolai/api/api_key` ile eşleşmelidir |
| `salt` | Her istek için üretilen rastgele UUID |
| `scope` | Uca özel enum değeri (aşağıdaki tabloya bakın) |
| `timestamp` | (Opsiyonel, tavsiye edilir) İsteğin üretildiği unix epoch saniyesi |
| `signature` | HMAC-SHA256 hex özeti |

> Bazı FastCGI kurulumları `Authorization` başlığını PHP'ye iletmez. Modül bu
> durumda `HTTP_AUTHORIZATION` ve `REDIRECT_HTTP_AUTHORIZATION` sunucu
> değişkenlerine de bakar; yine de boş geliyorsa web sunucusunun başlığı
> geçirdiğinden emin olun (bkz. [KURULUM.md](KURULUM.md#9-sorun-giderme)).

## İmza Hesaplama

```
signature = HMAC-SHA256(secretKey, salt + scope + uriPath + requestBody + timestamp)
```

- **secretKey**: `kolai/api/secret_key` değeri
- **salt**: Header'daki rastgele UUID
- **scope**: Header'daki scope dizesi
- **uriPath**: İsteğin **ham** yolu, sorgu dizesi hariç — `$_SERVER['REQUEST_URI']`
  üzerinden okunur. Örnekler:
  - `/kolai/v1/products/123`
  - `/tr/kolai/v1/products/123` (mağaza kodu URL'de ise)
  - `/index.php/kolai/v1/products/123` (web sunucusu yeniden yazımı kapalıysa)
- **requestBody**: POST/PATCH için ham JSON gövde; GET için **her zaman** boş
  string (istemci gövde eklese bile)
- **timestamp**: Gönderildiğinde imza mesajının **sonuna** eklenir. Gönderilmezse
  hiçbir şey eklenmez (geriye dönük uyumlu).

Sonuç küçük harfli hex olarak kodlanır.

İki kolay hata, ikisi de aynı belirtiyi verir (`Invalid signature`):

- **`uriPath` Kolai'nin gönderdiği yol olmalı.** Magento'nun rotaladığı
  `pathInfo` değil, bir proxy/CDN'in yeniden yazdığı hali değil. Kolai panelindeki
  Base URL ile isteğin gerçekten vardığı yol aynı olmalıdır.
- **`requestBody` ham bayt olmalı.** JSON'u çözüp yeniden kodlamak anahtar
  sırasını ve boşlukları değiştirir.

## Tekrar Saldırısı (Replay) Koruması

- **Tek kullanımlık salt**: Doğrulanan her isteğin `salt` değeri 600 saniye
  boyunca `kolai_nonce` tablosunda tutulur (birincil anahtar üzerinde `INSERT`,
  PHP-FPM süreçleri arasında atomik). Aynı istek aynen tekrar gönderilirse
  `Replayed request rejected` ile reddedilir. İstemci tarafında değişiklik
  gerekmez — her istek zaten yeni bir salt üretir.
- **Zaman damgası**: İmzalı `timestamp` gönderildiğinde, sunucu saatinden
  ±300 saniyeden fazla sapan istekler `Request timestamp outside allowed window`
  ile reddedilir.
- **Geçiş**: `timestamp` göndermeyen istemciler çalışmaya devam eder, ancak
  `auth` bağlamında deprecation uyarısı loglanır.
- **Gizli anahtar zorunluluğu**: API anahtarı veya gizli anahtar boşsa tüm
  istekler `Server credentials not configured` ile reddedilir (fail-closed).
- Süresi dolan salt kayıtları saatlik `kolai_cleanup` cron'u ile silinir.

## Scope Eşlemesi

Her rota tek bir scope ister; ürün okumak için üretilmiş bir imza sipariş
oluşturamaz.

| Uç | Metod | Scope |
|---|---|---|
| `/kolai/v1/products` | GET | `RETRIEVE_PRODUCTS` |
| `/kolai/v1/products/{id}` | GET | `RETRIEVE_PRODUCT` |
| `/kolai/v1/products-with-variants/{id}` | GET | `RETRIEVE_PRODUCT_WITH_VARIANTS` |
| `/kolai/v1/products/{id}/reviews` | GET | `RETRIEVE_REVIEWS` |
| `/kolai/v1/reviews/{id}` | GET | `RETRIEVE_REVIEW` |
| `/kolai/v1/shipment-options` (alias `/shipping-options`) | POST | `RETRIEVE_SHIPMENT_OPTIONS` |
| `/kolai/v1/order-types` | GET | `RETRIEVE_ORDER_TYPES` |
| `/kolai/v1/orders` | POST | `CREATE_ORDER` |
| `/kolai/v1/orders/{id}` | GET | `RETRIEVE_ORDER` |
| `/kolai/v1/orders/{id}` | PATCH | `UPDATE_ORDER_STATUS` |
| `/kolai/v1/contracts` | POST | `RETRIEVE_CONTRACT` |
| `/kolai/v1/contracts/clarification-text` | GET | `RETRIEVE_CONTRACT` |

## Hata Yanıtı

Kimlik doğrulama başarısız olduğunda standart zarf HTTP 401 ile döner:

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T12:00:00+00:00",
  "errorCode": "1005",
  "errorMessage": "Invalid signature",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

| `errorMessage` | Anlamı |
|---|---|
| `Missing or invalid authorization header` | Başlık yok ya da `IYZ-TP-v2 ` ile başlamıyor |
| `Duplicate Authorization headers detected. Send only the IYZ-TP-v2 header.` | İki `Authorization` başlığı geldi (`, ` ile birleşmiş) ya da başlıkta `Bearer ` var — ör. Postman'de hem imza script'i hem Auth sekmesi açık |
| `Invalid authorization encoding` / `Malformed authorization payload` | Base64 ya da `anahtar:değer&…` biçimi bozuk |
| `Missing authorization parameter: {ad}` | `clientId`, `salt`, `scope` veya `signature` eksik |
| `Server credentials not configured` | Mağazada API anahtarı veya gizli anahtar boş |
| `Invalid client credentials` | `clientId` mağazadaki API anahtarıyla uyuşmuyor |
| `Invalid scope` | Scope bu rotanın beklediği scope değil |
| `Invalid signature` | İmza tutmuyor (yanlış gizli anahtar, yol veya gövde) |
| `Invalid timestamp` / `Request timestamp outside allowed window` | Zaman damgası sayı değil ya da ±300 sn dışında |
| `Replayed request rejected` | Aynı `salt` ile ikinci istek |

## Uygulama Detayları

- **Rota**: İstekler Magento'nun REST katmanından (`webapi.xml`) değil, özel bir
  router'dan (`Controller\Router`) geçer. Sebep: Kolai `PATCH`, kendi yanıt zarfı
  ve HTTP durum kodları ile imza için ham gövde baytlarına ihtiyaç duyar —
  Magento REST bunların hiçbirini sunmaz.
- **Zamanlama-güvenli karşılaştırma**: `clientId`, scope ve imza `hash_equals()`
  ile karşılaştırılır.
- **CSRF**: API denetleyicisi form key doğrulamasından muaftır; isteğin kökeninin
  kanıtı HMAC imzasıdır.
- **Önbellek**: Yanıtlar `Cache-Control: no-store` ile döner; tam sayfa önbelleği
  veya Varnish bir Kolai yanıtını saklamaz.
- **Log**: İmza, gizli anahtar ve `clientId` değeri hiçbir zaman loglanmaz;
  `auth` bağlamında scope, yol, uzunluklar ve eşleşme sonucu yazılır (bkz. [LOGS.md](LOGS.md)).
