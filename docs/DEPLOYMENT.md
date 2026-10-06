# النشر والتشغيل

## المتطلبات

- PHP 8.2+ بالامتدادات: `pdo_mysql mbstring intl openssl zip bcmath gd xml dom`.
- MySQL 8.0+ (أو MariaDB 10.6+)، وRedis 6+.
- Node 20 للبناء فقط، وNginx، وSupervisor.

## الإعدادات الأساسية (`.env`)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://alostaz.io
APP_DOMAIN=alostaz.io                 # النطاق المركزي؛ المنشآت على {subdomain}.alostaz.io
TENANCY_ENFORCE_SUBDOMAIN=true
TENANCY_ALLOW_DEV_OVERRIDE=false      # لا تسمح بـ ?tenant= في الإنتاج
SESSION_DOMAIN=null                   # كوكي مستقلة لكل نطاق فرعي (لا تجعلها .alostaz.io)
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
DB_CONNECTION=mysql
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
TRUSTED_PROXIES=10.0.0.0/8            # عند وجود موازن أحمال
MAIL_MAILER=smtp
```

> **تنبيه:** `APP_KEY` يُشفّر مفاتيح أجهزة الفوترة الإلكترونية وأسرار الـ Webhooks. احفظه في مخزن أسرار، ولا تغيّره بعد التشغيل.

## DNS وشهادات SSL

- **السجلات:**
  - `A alostaz.io → IP`
  - `A *.alostaz.io → IP` (wildcard)
  - `A admin.alostaz.io → IP`
- **الشهادة:** شهادة wildcard عبر DNS-01، مثلاً: `certbot certonly --dns-<provider> -d alostaz.io -d '*.alostaz.io'`.
- **النطاقات الخاصة للعملاء:** يضيف العميل سجل `CNAME` إلى `{subdomain}.alostaz.io` وسجل `TXT _alostaz-verify` من صفحة الاشتراك. لا يُخدم النطاق قبل التحقق. أصدر شهادته عبر HTTP-01 أو استخدم on-demand TLS في Caddy.

## Nginx

انظر [`deploy/nginx.conf`](../deploy/nginx.conf).

## الطوابير والمجدول

انظر [`deploy/supervisor.conf`](../deploy/supervisor.conf). الطوابير المستخدمة:

| الطابور | الاستخدام |
|---|---|
| `zatca` | الاعتماد المسبق والإبلاغ لدى هيئة الزكاة (مهلة الإبلاغ 24 ساعة) |
| `webhooks` | إرسال Webhooks |
| `exports` | نسخ بيانات المنشآت |
| `default` | الإشعارات والبريد |

أضف كذلك سطر cron للمجدول:

```cron
* * * * * cd /var/www/alostaz && php artisan schedule:run >> /dev/null 2>&1
```

| المهمة | الموعد | الوظيفة |
|---|---|---|
| `erp:zatca-submit` | كل 10 دقائق | إعادة جدولة المستندات المعلقة أو المتعثرة |
| `erp:alerts` | يومياً 06:50 | ملخص التنبيهات |
| `erp:accounting-schedules recurring` | يومياً | القيود المتكررة |
| `erp:accounting-schedules depreciation` | شهرياً | الإهلاك |
| `erp:hr-provision` | شهرياً | مخصص نهاية الخدمة |

## ملاحظات بيئة التشغيل

- **شعارات المنشآت:** يعرضها النظام عبر المسار `/company/logo`، فلا تحتاج `php artisan storage:link`. يبقى الأمر مفيداً لأي ملفات عامة أخرى.
- **Windows (Laragon / XAMPP):** إنشاء مفاتيح أجهزة ZATCA لا يعتمد على `openssl.cnf` الخاص بالنظام، لأن النظام يولّد ملف إعداد خاصاً به.
- **اللغة الافتراضية:** العربية (`APP_LOCALE=ar`)، ورسائل التحقق مترجمة في `lang/ar`.

## خطوات النشر

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan erp:sync-permissions          # بعد أي تحديث للصلاحيات
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

## النسخ الاحتياطي

- **قاعدة البيانات:** نسخ يومي، مثلاً `mysqldump --single-transaction --routines alostaz | gzip`، مع الاحتفاظ 30 يوماً ونسخة خارج الخادم.
- **الملفات الخاصة:** نسخ `storage/app/private` (مرفقات الموظفين والعملاء وصادرات البيانات).
- **نسخة منشأة واحدة:** يستطيع مالك المنشأة طلب نسخة كاملة من بياناته (CSV مضغوط) من صفحة الاشتراك.

## المراقبة والأداء

- **الصحة:** المسار `/up` لفحص الصحة.
- **الفهارس:** كل الجداول مفهرسة بـ `company_id` مع أعمدة البحث والتواريخ.
- **الأرصدة:** ميزان المراجعة يقرأ من أرصدة شهرية مجمعة (`account_balances`). يُعاد بناؤها عند الحاجة بـ `php artisan erp:accounting rebuild-balances`.
- **اختبار الحمل:** `php artisan erp:load-test` ينشئ بيانات ضخمة ويقيس زمن التقارير.
- **التزامن:** الترقيم والمخزون وسلسلة الفواتير الإلكترونية محمية بأقفال الصفوف `SELECT ... FOR UPDATE`. جُرّب ذلك على MySQL 8 بأربع عمليات متزامنة: لا أرقام مكررة، والمخزون والقيود متطابقة.
