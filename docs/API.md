# الواجهة البرمجية (REST API v1)

- **المصادقة:** Bearer token يُنشأ من **الإعدادات ← الربط البرمجي**.
- **النطاق:** الرمز مقيد بمنشأة صاحبه وبالصلاحيات المختارة له.
- **حد الطلبات:** 120 طلباً في الدقيقة لكل مستخدم.
- **العنوان الأساسي:** `https://{subdomain}.diwan.io/api/v1`.

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" https://anas.diwan.io/api/v1/customers?search=أحمد
```

| الطريقة | المسار | الصلاحية | الوصف |
|---|---|---|---|
| GET | `/me` | — | المستخدم والمنشأة وصلاحيات الرمز |
| GET | `/customers?search=&page=&per_page=` | `sales.clients.view` | قائمة العملاء |
| GET | `/customers/{id}` | `sales.clients.view` | عميل |
| POST | `/customers` | `sales.clients.create` | إنشاء عميل |
| GET | `/products?search=` | `inventory.products.view` | الأصناف |
| GET | `/products/{id}` | `inventory.products.view` | صنف مع أرصدته في كل مستودع |
| GET | `/invoices?from=&to=&customer_id=&payment_status=` | `sales.invoices.view` | الفواتير |
| GET | `/invoices/{id}` | `sales.invoices.view` | فاتورة مع البنود وحالة ZATCA و QR |
| POST | `/invoices` | `sales.invoices.create` | إصدار فاتورة (تُصرف من المخزون وتُقيد وتُرسل لهيئة الزكاة آلياً) |

## مثال: إصدار فاتورة

```json
POST /api/v1/invoices
{
  "customer_id": 12,
  "invoice_date": "2026-10-05",
  "invoice_type": "standard",
  "payment_status": "unpaid",
  "items": [
    {"product_id": 5, "item_name": "قطعة غيار", "quantity": 2, "unit_price": 150, "tax_rate": 15}
  ]
}
```

## الأخطاء

| الرمز | المعنى |
|---|---|
| 401 | رمز غير صالح |
| 403 | صلاحية غير ممنوحة للرمز أو للمستخدم، أو وحدة خارج الباقة |
| 402 | انتهى الاشتراك (قراءة فقط) |
| 404 | السجل غير موجود في منشأتك |
| 422 | أخطاء التحقق في `errors` |

# Webhooks

- **الإنشاء:** من **الإعدادات ← الربط البرمجي**. يُعطى مفتاح التوقيع `whsec_...` مرة واحدة.
- **الأحداث:** `invoice.created`، `payment.received`، `credit_note.created`، `zatca.status_changed`.

## شكل الطلب

```
POST <your url>
X-Diwan-Event: invoice.created
X-Diwan-Delivery: <uuid>
X-Diwan-Timestamp: 1791234567
X-Diwan-Signature: sha256=<hex>

{"id":"<uuid>","event":"invoice.created","created_at":"...","data":{...}}
```

## التحقق من التوقيع

```php
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
hash_equals($expected, $signatureHeader); // ارفض الطلبات الأقدم من 5 دقائق
```

## قواعد التسليم

- **إعادة المحاولة:** تُعاد المحاولة حتى 5 مرات، بعد 30 ثانية ثم 2 دقيقة ثم 10 دقائق ثم ساعة.
- **الإيقاف التلقائي:** يُوقف الـ endpoint تلقائياً بعد 50 فشلاً متتالياً.
- **العناوين المقبولة:** عناوين HTTPS عامة فقط؛ تُرفض عناوين الشبكات الداخلية.
