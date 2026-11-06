# Kolai – iyzico İade (Refund) & İptal (Cancel)

Kolai ile oluşturulan siparişlerde, Magento yönetim panelinden yapılan **online
iade** ve **iptal** işlemleri otomatik olarak iyzico'ya iletilir. Entegrasyon,
`PATCH /orders/{orderId}` ile kaydedilen ödeme bilgilerini kullanır (bkz.
[ORDER.md](ORDER.md#patch-ordersorderid)). iyzico çağrıları `kolai-php`
kütüphanesindeki `Kolai\Payment\IyzicoClient` üzerinden `iyzico/iyzipay-php`
SDK'sı ile yapılır.

## Gereken sipariş verisi

PATCH ile şu alanlar `kolai_order` tablosuna yazılmış olmalıdır:

| Alan | Ne için |
|---|---|
| `payment_id` — iyzico `paymentId` | İptal |
| `item_transactions` — kalem bazlı `paymentTransactionId` + `paidPrice` | İade |

Ayrıca **fatura** olmalıdır: Magento online iadeyi yalnızca faturası olan
siparişte sunar. Fatura, `paymentId` ile ödenmiş bir duruma (`processing` /
`complete`) geçen `PATCH` ile otomatik oluşur.

## Ayarlar

Mağazalar › Yapılandırma › Satışlar › Kolai › **iyzico (iade / iptal)**:

| Alan | Açıklama |
|---|---|
| Ortam | `Sandbox` (varsayılan) veya `Canlı` |
| API anahtarı | iyzico merchant API anahtarı (şifreli saklanır) |
| Gizli anahtar | iyzico merchant gizli anahtarı (şifreli saklanır) |

> Bu anahtarlar REST kimlik doğrulamasında kullanılan Kolai API anahtarlarından
> **ayrıdır**. Ortama göre baseUrl otomatik seçilir: sandbox →
> `https://sandbox-api.iyzipay.com`, canlı → `https://api.iyzipay.com`.
> Ayarlar website kapsamındadır; siparişin ait olduğu website'ın anahtarları
> kullanılır.

> **iyzipay-php 2.0.63 veya üstü gerekir.** 2.0.62 ve öncesi PHP 8.4+ üzerinde
> deprecation uyarısı üretir; Magento bu uyarıyı web isteklerinde exception'a
> çevirdiği için yönetimden yapılan iade/iptal iyzico'ya hiç ulaşmaz (CLI'da
> çalışıyor görünmesi yanıltıcıdır).

---

## İade (Refund)

Sipariş › Faturalar › fatura › **Credit Memo** › **Refund** düğmesi (ödeme
yöntemi: *Kolai App (iyzico)*).

> **"Refund Offline"** yalnızca Magento kaydı oluşturur, iyzico'ya **iletilmez**.
> Sipariş ekranındaki *Credit Memo* düğmesi faturasız çevrimdışı iadedir; online
> iade için faturanın kendi ekranından credit memo açın.

### Nasıl çalışır?

1. Credit memo'nun **genel toplamı** (siparişin para biriminde — iyzico o para
   biriminde tahsil etti) iyzico'ya iade edilecek tutardır. Tam veya **kısmi**
   iade desteklenir.
2. Sipariş bazlı bir kilit alınır; aynı siparişe eşzamanlı ikinci iade
   *"Bu sipariş için başka bir iade işlemi devam ediyor"* hatasıyla reddedilir.
3. Tutar kayıtlı `itemTransactions` kalemlerine dağıtılır (`kolai-php`
   `RefundAllocator`) ve her kalem için iyzico `paymentTransactionId` üzerinden
   ayrı bir iade çağrısı yapılır. Hiçbir kalem kendi `paidPrice` değerinin
   üzerinde iade edilmez.
4. Her başarılı adımdan sonra **iade defteri** (`refunded_transactions`) ve işlem
   kaydı (`refund_operations`) **ayrı bir veritabanı bağlantısıyla** yazılır.
   Magento credit memo'yu kendi DB transaction'ı içinde kaydeder ve hata olursa
   geri alır; iyzico'da hareket etmiş para geri alınamayacağı için bu kayıtlar o
   geri almadan etkilenmez.
5. Tüm tutar iade edilince credit memo kaydedilir; iyzico işlemleri sipariş
   geçmişine not olarak düşülür (*"iyzico iade başarılı: 50.00 (işlem 34426266)"*).
   Credit memo'nun işlem numarası `{paymentId}-refund-{işlem kimliğinin ilk 8
   karakteri}` biçimindedir (`paymentId` yoksa önek `kolai-{orderId}`); ardışık
   kısmi iadeler çakışmaz.
6. İşlem bu noktada `awaiting_credit_memo` olarak kaydedilir, `success` değil:
   Magento credit memo'yu modülün kodu döndükten **sonra** kaydeder ve bu kayıt
   hâlâ geri alınabilir. Bir sonraki bakışta (sipariş ekranı veya sonraki iade)
   işlem, credit memo'nun **kalıcı olarak** kaydedilip kaydedilmediğine göre
   sonuçlandırılır — bkz. [Credit memo geri alınırsa](#credit-memo-geri-alınırsa).

### Hata durumları

| Durum | Ne olur |
|---|---|
| iyzico anahtarları ayarlı değil | Credit memo oluşmaz; anahtarları ayarlamanız istenir |
| `itemTransactions` yok | Credit memo oluşmaz; gerekirse çevrimdışı iade oluşturun |
| İade edilebilir kalan tutar yetersiz | Hiçbir çağrı yapılmaz; kalan iade edilebilir tutar mesajda gösterilir |
| **Kısmi başarısızlık** (bazı kalemler iade edildi, biri reddedildi) | Credit memo oluşmaz, işlem `partial` olarak kalır. **Aynı tutarla** tekrar denemek yalnızca **kalan** kısmı gönderir |
| iyzico'dan yanıt alınamadı (zaman aşımı vb.) | Adım `needs_reconciliation` olarak işaretlenir. iyzico panelinden kontrol **edilmeden tekrar denenmemelidir** |
| Farklı bir tutarla tekrar deneme | Açık işlem `abandoned` olarak bırakılır (elle mutabakat için saklanır), yeni işlem başlar; defter her kalemi yine `paidPrice` ile sınırlar |
| Yerel iade defteri yazılamadı (iyzico iadesi alındıktan sonra) | İşlem `needs_reconciliation` olur; iyzico panelinden **elle kontrol edilmeden** tekrar denenmemelidir |
| Bir deneme işlem ortasında çöktü (bazı adımlar gitti, işlem `in_progress`/`partial` kaldı) | Aynı tutarla tekrar deneme yalnızca kalanı gönderir; her şey gitmişse iyzico'ya gitmeden yalnızca credit memo'yu kaydeder |
| iyzico iadesi tamam, Magento credit memo'yu geri aldı | İşlem `awaiting_credit_memo` kalır. **Aynı tutarla** tekrar deneme iyzico'ya gitmeden yalnızca credit memo'yu kaydeder (aşağıya bakın) |

### Credit memo geri alınırsa

Tüm iyzico adımları başarılı olduktan sonra Magento credit memo'yu yine de geri
alabilir (ör. başka bir modülün observer'ı veya bir DB hatası). Para iyzico'da
iade edilmiştir ama Magento'da credit memo yoktur.

Modül bu yüzden işlemi önce `awaiting_credit_memo` olarak bırakır ve sonra
veritabanında olanlara bakarak sonuçlandırır (`Model/Payment/RefundRecord`):

| Veritabanında | İşlem |
|---|---|
| İşlemin iade kaydı (`sales_payment_transaction`, `txn_id` = credit memo işlem numarası) var | `success` — credit memo kalıcı olarak kaydedilmiş |
| Kayıt yok ve iyzico'ya iade edilen toplam (defter), siparişin kayıtlı `total_refunded` değerini bu işlemin tutarı kadar aşıyor | `awaiting_credit_memo` kalır — credit memo gerçekten eksik |
| Kayıt yok ama tutar başka bir credit memo ile (ör. **Refund Offline**) kayda geçmiş | `success` — *"Recorded by another credit memo"* notuyla |

Eksik credit memo'yu tamamlamak için credit memo'yu **aynı tutarla** tekrar
oluşturup **Refund** ile gönderin: işlem kaldığı yerden devam eder, gönderilecek
tutar kalmadığı için iyzico'ya **hiçbir çağrı yapılmaz**, yalnızca credit memo
kaydedilir (sipariş notu: *"iyzico iadesi daha önce tamamlanmıştı …; iade kaydı
oluşturuldu"*). Kolai kutusu bu işlemi sarı satırla ve ne yapılacağını anlatan
bir uyarıyla gösterir. **Refund Offline** da kullanılabilir; o zaman işlem
yukarıdaki üçüncü satıra göre `success` olur.

> Bu işlem açıkken farklı bir tutarla iade istenirse işlem `abandoned` olur
> (elle mutabakat). Aynı siparişte daha önce yarım kalmış (`partial`/`abandoned`)
> bir işlem de varsa iki durum birbirinden her zaman ayırt edilemez; Kolai
> kutusundaki *Kalem işlemleri* ile iyzico panelini karşılaştırın.

> Modülün bu sürümünden önce kaydedilmiş `success` işlemleri olduğu gibi kalır.

İşlem durumları: `in_progress`, `awaiting_credit_memo`, `success`, `partial`,
`failed`, `needs_reconciliation`, `abandoned`.

---

## İptal (Cancel)

Sipariş **iptal edildiğinde** (`order_cancel_after` olayı — yönetimden *Cancel*
düğmesi veya `PATCH` ile `canceled`) iyzico iptali `paymentId` ile, tüm ödeme
için tetiklenir.

- Magento **tamamen faturalanmış** siparişin iptaline izin vermez. Kolai
  siparişleri `paymentId` ile ödenince tüm tutarla faturalandığı için bu yol dar
  bir durumu kapsar: ödeme bilgisi gelmiş ama fatura oluşmamış sipariş.
  Faturalanmış sipariş credit memo ile iade edilmelidir.
- `paymentId` yoksa (ör. süresi dolan, hiç ödenmemiş sipariş) iyzico'ya gidilmez.
- iyzico iptali **yalnızca ödeme günü** kabul eder; sonrasında iade
  kullanılmalıdır.
- Sonuç `kolai_order.cancel_result` alanına yazılır ve sipariş geçmişine not
  düşülür. **Idempotent**: iyzico bir kez başarıyla iptal ettiyse tekrar denenmez.
- **iyzico'nun reddi Magento iptalini engellemez.** Hata sipariş notuna
  (*"iyzico iptal BAŞARISIZ: … tutarı iyzico panelinden iade edin"*) ve loga
  yazılır. Anahtarlar ayarlı değilse iptal yine yapılır, not düşülür.

---

## Sipariş ekranındaki Kolai kutusu

Satışlar › Siparişler › sipariş görünümünde **Kolai / iyzico** kutusu şunları
gösterir (PATCH gelmemişse *"Bu sipariş için henüz Kolai ödeme bilgisi yok"*):

- iyzico `paymentId`, taksit sayısı
- Fatura tipi (Bireysel/Kurumsal), VKN/TCKN, vergi dairesi
- iyzico iptal sonucu
- **Kalem işlemleri**: her `paymentTransactionId` için ödenen, iade edilen ve
  hâlâ iade edilebilir tutar (defter)
- **İade işlemleri**: zaman, işlem kimliği, istenen ve gerçekleşen tutar, durum,
  mesaj. `awaiting_credit_memo` işlemleri veritabanına göre sonuçlandırılmış
  haliyle gösterilir (kaydı kalıcı credit memo'ya bağlı olanlar `success`).
  Dikkat gerektiren durumlar (`partial`, `failed`, `needs_reconciliation`,
  `abandoned`, credit memo'su eksik `awaiting_credit_memo`) sarı satırla vurgulanır.

## Loglama

Tüm iade/iptal adımları `payment` bağlamında loglanır (bkz. [LOGS.md](LOGS.md)).
