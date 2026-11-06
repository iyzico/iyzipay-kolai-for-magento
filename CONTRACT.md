# Kolai API – Sözleşme (Contract) Uçları

Base URL: `https://magaza-adresiniz.com/kolai/v1`

Genel yanıt formatı ve hata kodları için [README.md](README.md) dosyasına bakın.

## Hata Kodları (Contract)

| Kod | HTTP | Ne zaman |
|---|---|---|
| `1002` | 404 | Aydınlatma Metni sayfası seçilmemiş, pasif ya da bu mağaza görünümüne atanmamış (yalnızca `GET /contracts/clarification-text`) |
| `5002` | 404 | Şablon bulunamadı: kayıtlı şablon boş ve paket şablonu okunamadı (`Default template missing for type: …`) ya da şablon yalnızca boşluktan oluşuyor (`Contract template not found for type: …`) |

---

## POST /contracts

Her iki sözleşmeyi tek istekte döner: `distance_sales` (Mesafeli Satış Sözleşmesi)
ve `preliminary_info` (Ön Bilgilendirme Formu), ayrıca `clarificationText`.

Satıcı bilgileri (`{{seller_*}}`), tahmini teslim süresi (`{{delivery_date}}`) ve
cayma hakkı süresi (`{{right_of_withdrawal_period}}`) yönetim ayarlarından
doldurulur. Alıcı ve sipariş yer tutucuları metinde bırakılır; çıkışta Kolai
(mobil istemci) doldurur.

### Request

```
POST /kolai/v1/contracts
```

Gövde zorunlu değildir ve **yok sayılır** — iki sözleşme her zaman birlikte döner.
Boş gövde veya `{}` gönderilebilir.

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T12:00:00+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "distance_sales": {
      "title": "Mesafeli Satis Sozlesmesi",
      "content": "<h1>Mesafeli Satis Sozlesmesi</h1>\n\n<h2>MADDE 1 - TARAFLAR</h2>\n\n<h3>1.1 SATICI</h3>\n<p>\n<strong>Unvan:</strong> Örnek Ticaret A.Ş.<br>\n...",
      "placeholders": {
        "{{buyer_name}}": "Alıcı adı",
        "{{buyer_company_name}}": "Alıcı firma unvanı",
        "{{buyer_tax_id}}": "Alıcı VKN / TCKN",
        "{{buyer_tax_office}}": "Alıcı vergi dairesi",
        "{{buyer_email}}": "Alıcı e-posta adresi",
        "{{buyer_phone}}": "Alıcı telefonu",
        "{{buyer_address}}": "Alıcı adresi",
        "{{order_date}}": "Sipariş tarihi",
        "{{order_total}}": "Sipariş toplamı",
        "{{order_currency}}": "Para birimi",
        "{{payment_method}}": "Ödeme yöntemi",
        "{{shipping_method}}": "Kargo yöntemi",
        "{{shipping_cost}}": "Kargo ücreti",
        "{{product_list}}": "Ürün listesi (HTML tablo)"
      }
    },
    "preliminary_info": {
      "title": "On Bilgilendirme Formu",
      "content": "<h1>On Bilgilendirme Formu</h1>\n\n<p>6502 sayili Tuketicinin Korunmasi Hakkinda Kanun ...",
      "placeholders": {
        "{{buyer_name}}": "Alıcı adı",
        "...": "..."
      }
    },
    "clarificationText": {
      "pageId": 4,
      "title": "Aydınlatma Metni",
      "url": "https://magaza-adresiniz.com/aydinlatma-metni"
    }
  }
}
```

| Alan | Açıklama |
|---|---|
| `title` | Sözleşmenin sabit başlığı |
| `content` | Satıcı yer tutucuları doldurulmuş HTML |
| `placeholders` | `content` içinde geçen **alıcı/sipariş** yer tutucuları ve açıklamaları — istemcinin dolduracakları |
| `clarificationText` | `GET /contracts/clarification-text` yanıtının `data`'sı; sayfa ayarlı/erişilebilir değilse **`null`** (istek hata vermez) |

> Bir satıcı alanı boş bırakılırsa yer tutucusu (ör. `{{seller_mersis_no}}`)
> metinde **olduğu gibi** kalır — yasal bir metinde boş bir MERSİS satırı,
> görünür biçimde doldurulmamış bir satırdan daha kötüdür. Bu yer tutucular
> `placeholders` listesinde **yer almaz** (istemci onları dolduramaz); canlıya
> almadan önce tüm satıcı alanlarını doldurun.

### Response (template not found)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T12:00:00+00:00",
  "errorCode": "5002",
  "errorMessage": "Contract template not found for type: distance_sales",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

---

## GET /contracts/clarification-text

Ayarlarda seçilen Aydınlatma Metni **CMS sayfasının** bağlantısını döner.

### Request

```
GET /kolai/v1/contracts/clarification-text
```

### Response (success)

```json
{
  "status": "success",
  "systemTime": "2026-11-06T12:00:00+00:00",
  "errorCode": null,
  "errorMessage": null,
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": {
    "pageId": 4,
    "title": "Aydınlatma Metni",
    "url": "https://magaza-adresiniz.com/aydinlatma-metni"
  }
}
```

`url`, sayfanın isteğin geldiği mağaza görünümündeki adresidir (Magento
`Cms\Helper\Page::getPageUrl`).

### Response (page not configured or not found)

```json
{
  "status": "failure",
  "systemTime": "2026-11-06T12:00:00+00:00",
  "errorCode": "1002",
  "errorMessage": "Clarification text page is not configured",
  "magentoVersion": "2.4.9",
  "phpVersion": "8.4.10",
  "data": null
}
```

Sayfa seçilmiş ama yoksa, pasifse ya da bu mağaza görünümüne atanmamışsa mesaj
`Clarification text page not found` olur.

---

## Yer Tutucular (Placeholders)

Şablonlarda `{{yer_tutucu}}` biçimi kullanılır.

### Yönetimden doldurulanlar

| Yer tutucu | Ayar (Mağazalar › Yapılandırma › Satışlar › Kolai) | Config path |
|---|---|---|
| `{{seller_name}}` | Satıcı bilgileri › Unvan | `kolai/seller/name` |
| `{{seller_address}}` | Satıcı bilgileri › Adres | `kolai/seller/address` |
| `{{seller_phone}}` | Satıcı bilgileri › Telefon | `kolai/seller/phone` |
| `{{seller_email}}` | Satıcı bilgileri › E-posta | `kolai/seller/email` |
| `{{seller_tax_id}}` | Satıcı bilgileri › VKN | `kolai/seller/tax_id` |
| `{{seller_mersis_no}}` | Satıcı bilgileri › MERSİS numarası | `kolai/seller/mersis_no` |
| `{{delivery_date}}` | Sözleşmeler › Tahmini teslim süresi (varsayılan `30 gün`) | `kolai/contract/delivery_date` |
| `{{right_of_withdrawal_period}}` | Sözleşmeler › Cayma hakkı süresi (varsayılan `14 gün`) | `kolai/contract/right_of_withdrawal_period` |

### İstemci tarafında doldurulanlar

Yanıtta yer tutucu olarak korunur; Kolai sipariş anında doldurur:

| Yer tutucu | Açıklama |
|---|---|
| `{{buyer_name}}` | Alıcı adı |
| `{{buyer_company_name}}` | Alıcı firma unvanı |
| `{{buyer_tax_id}}` | Alıcı VKN / TCKN |
| `{{buyer_tax_office}}` | Alıcı vergi dairesi |
| `{{buyer_email}}` | Alıcı e-posta adresi |
| `{{buyer_phone}}` | Alıcı telefonu |
| `{{buyer_address}}` | Alıcı adresi |
| `{{order_date}}` | Sipariş tarihi |
| `{{order_total}}` | Sipariş toplamı |
| `{{order_currency}}` | Para birimi |
| `{{payment_method}}` | Ödeme yöntemi |
| `{{shipping_method}}` | Kargo yöntemi |
| `{{shipping_cost}}` | Kargo ücreti |
| `{{product_list}}` | Ürün listesi (HTML tablo olarak istemci oluşturur) |

---

## Yönetim Paneli

Mağazalar › Yapılandırma › Satışlar › **Kolai** (veya Sistem › Kolai › Kolai
Ayarları):

- **Satıcı bilgileri** grubu: unvan, adres, telefon, e-posta, VKN, MERSİS.
- **Sözleşmeler** grubu:
  - Tahmini teslim süresi ve cayma hakkı süresi
  - **Aydınlatma Metni sayfası**: mağazadaki CMS sayfalarından seçilir
  - **Mesafeli Satış Sözleşmesi** ve **Ön Bilgilendirme Formu** metin alanları.
    Her alanın altında yer tutucu düğmeleri vardır; tıklanan yer tutucu imlecin
    olduğu yere eklenir. Şablonda tanınmayan bir yer tutucu (ör. yazım hatası)
    varsa alanın altında *"Bilinmeyen yer tutucu, müşteriye olduğu gibi gider"*
    uyarısı çıkar.
- Şablon alanı **boş bırakılırsa** `kolai-php` paketindeki varsayılan Türkçe
  şablon kullanılır.
- Tüm alanlar **website** kapsamındadır; çok website'lı mağazada her website
  kendi satıcı bilgilerini ve şablonlarını taşıyabilir.
- Değerler `core_config_data` tablosunda yukarıdaki config path'leriyle saklanır
  (şablonlar: `kolai/contract/distance_sales`, `kolai/contract/preliminary_info`;
  sayfa: `kolai/contract/clarification_page`).
