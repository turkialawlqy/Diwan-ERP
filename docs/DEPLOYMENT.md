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

النظام ينسخ نفسه تلقائياً بأمر `erp:backup`، المجدول يومياً الساعة 02:40، مع اختبار استعادة كل أحد. يتطلب ذلك تشغيل المجدول (`schedule:run`).

### محتوى النسخة

- **قاعدة البيانات:** `mysqldump --single-transaction` أو نسخة SQLite.
- **الملفات:** `storage/app/public` و`storage/app/private`.
- **ملف `manifest.json`:** عدد سجلات كل جدول.
- **التشفير:** الأرشيف مشفّر بـ AES-256 بكلمة مرور `BACKUP_PASSWORD`. **احفظ كلمة المرور خارج الخادم**، فبدونها لا يمكن الاستعادة.

### الإعدادات (`.env`)

| المتغير | الوصف |
|---|---|
| `BACKUP_PASSWORD` | كلمة مرور التشفير (إلزامية عملياً). |
| `BACKUP_DISK` | قرص التخزين. الافتراضي `backups` (`storage/app/backups`). اربطه بقرص S3 لحفظ النسخ خارج الخادم. |
| `BACKUP_KEEP_DAILY` / `_WEEKLY` / `_MONTHLY` | الاحتفاظ. الافتراضي 7 يومية و4 أسبوعية و6 شهرية. |
| `BACKUP_VERIFY_DATABASE` | قاعدة MySQL فارغة مخصصة لاختبار الاستعادة الكامل (تُحذف وتُنشأ في كل اختبار). بدونها يكتفي الاختبار بفحص بنية الملف. |
| `BACKUP_NOTIFY_EMAIL` | بريد يُنبَّه عند فشل النسخ أو الاختبار. |

### المتابعة

- **من الواجهة:** صفحة «النسخ الاحتياطي» في لوحة مدير المنصة، وفيها آخر نسخة واختبار الاستعادة والملفات، وزر نسخ فوري.
- **يدوياً:** `php artisan erp:backup` لإنشاء نسخة، و`php artisan erp:backup --verify` لاختبار الاستعادة.

### الاستعادة

1. **فك الأرشيف** (يتطلب `7z` أو `unzip` بنسخة تدعم AES):

   ```bash
   7z x alostaz-backup-YYYY-MM-DD-HHMMSS.zip -p"$BACKUP_PASSWORD" -o/tmp/restore
   ```

2. **قاعدة البيانات:**

   ```bash
   mysql alostaz < /tmp/restore/database.sql
   ```

3. **الملفات:** انسخ `files/public` إلى `storage/app/public`، و`files/private` إلى `storage/app/private`.
4. **إنهاء الاستعادة:** شغّل `php artisan optimize:clear`، ثم أعد تشغيل الطوابير.

### نسخة منشأة واحدة

يستطيع مالك المنشأة طلب نسخة كاملة من بياناته (CSV مضغوط) من صفحة الاشتراك.

## التحقق الثنائي (2FA)

- **مدير المنصة:** إلزامي له دائماً. يمكن تعطيله فقط للعروض المحلية بـ `TWO_FACTOR_SUPER_ADMIN=false`.
- **مدير المنشأة:** يحدد الأدوار الملزمة به من «الإعدادات ← النظام ← الأمان».
- **أي مستخدم:** يستطيع تفعيله من «الملف الشخصي» عبر تطبيق مصادقة. يحصل عند التفعيل على 8 رموز استرداد، كل رمز يُستخدم مرة واحدة.
- **رموز التطبيق:** تعتمد على الوقت، لذلك يجب أن تكون ساعة الخادم مضبوطة (NTP).

## المراقبة والأداء

- **الصحة:** المسار `/up` لفحص الصحة.
- **الفهارس:** كل الجداول مفهرسة بـ `company_id` مع أعمدة البحث والتواريخ.
- **الأرصدة:** ميزان المراجعة يقرأ من أرصدة شهرية مجمعة (`account_balances`). يُعاد بناؤها عند الحاجة بـ `php artisan erp:accounting rebuild-balances`.
- **اختبار الحمل:** `php artisan erp:load-test` ينشئ بيانات ضخمة ويقيس زمن التقارير.
- **التزامن:** الترقيم والمخزون وسلسلة الفواتير الإلكترونية محمية بأقفال الصفوف `SELECT ... FOR UPDATE`. جُرّب ذلك على MySQL 8 بأربع عمليات متزامنة: لا أرقام مكررة، والمخزون والقيود متطابقة.
