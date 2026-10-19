# النشر والتشغيل

## المتطلبات

- PHP 8.2+ بالامتدادات: `pdo_mysql mbstring intl openssl zip bcmath gd xml dom`.
- MySQL 8.0+ (أو MariaDB 10.6+)، وRedis 6+.
- Node 20 للبناء فقط، وNginx، وSupervisor.

## أول تشغيل إنتاجي (خطوة بخطوة)

1. **الخادم:** أنشئ قاعدة MySQL ومستخدماً لها، وثبّت Redis وNginx وSupervisor (انظر المتطلبات).
2. **الكود:**
   ```bash
   git clone <repo> /var/www/diwan && cd /var/www/diwan
   git checkout stable        # فرع/وسم الإصدارات المستقرة، لا فرع التطوير
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```
3. **الإعدادات:**
   - انسخ القالب: `cp .env.production.example .env`.
   - املأ كل قيمة فارغة: قاعدة البيانات، البريد، `BACKUP_PASSWORD`، حساب التخزين الخارجي.
   - ثم: `php artisan key:generate`.
   - احفظ `APP_KEY` و`BACKUP_PASSWORD` في مخزن أسرار خارج الخادم.
4. **قاعدة البيانات:** `php artisan migrate --force --seed`. في الإنتاج يُنشئ المُبذِر الباقات فقط، بلا شركة تجريبية ولا كلمات مرور.
5. **مدير المنصة:** `php artisan erp:create-super-admin you@example.com`. تُطلب كلمة المرور سرّاً، ويُفرض التحقق الثنائي عند أول دخول.
6. **العمليات الخلفية:** انسخ `deploy/supervisor.conf` إلى `/etc/supervisor/conf.d/`، ثم `supervisorctl reread && supervisorctl update`. الملف يشمل:
   - عمّال الطوابير.
   - **المُجدول** (`schedule:work`).
7. **التخزين المؤقت:** `php artisan optimize`.
8. **الفحص:** `php artisan erp:preflight`. يجب ألا يظهر أي «خطأ».
9. **المراقبة:** اربط مراقب جاهزية (UptimeRobot أو غيره) بـ `https://diwan.io/health`. يرد 503 عند:
   - تعطل قاعدة البيانات.
   - توقف عامل الطوابير.
   - توقف المُجدول.
   - تقادم النسخة الاحتياطية.

## الإعدادات الأساسية (`.env`)

القالب الكامل في [`.env.production.example`](../.env.production.example). أهم القيم:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://diwan.io
APP_DOMAIN=diwan.io                 # النطاق المركزي؛ المنشآت على {subdomain}.diwan.io
TENANCY_ENFORCE_SUBDOMAIN=true
TENANCY_ALLOW_DEV_OVERRIDE=false      # لا تسمح بـ ?tenant= في الإنتاج
SESSION_DOMAIN=null                   # كوكي مستقلة لكل نطاق فرعي (لا تجعلها .diwan.io)
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
DB_CONNECTION=mysql
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
TRUSTED_PROXIES=10.0.0.0/8            # عند وجود موازن أحمال
MAIL_MAILER=smtp
APP_TIMEZONE=Asia/Riyadh              # تواريخ المستندات والترقيم والتقارير
TENANCY_REJECT_UNKNOWN_HOSTS=true     # يرفض Host مزوّراً (روابط إعادة كلمة المرور)
BACKUP_PASSWORD=...                   # إلزامي: لا تُنشأ نسخ غير مشفرة في الإنتاج
BACKUP_OFFSITE_DISK=offsite           # نسخة من كل نسخة احتياطية في S3 خارج الخادم
```

> **تنبيه:** `APP_KEY` يُشفّر مفاتيح أجهزة الفوترة الإلكترونية وأسرار الـ Webhooks. احفظه في مخزن أسرار، ولا تغيّره بعد التشغيل.

## DNS وشهادات SSL

- **السجلات:**
  - `A diwan.io → IP`
  - `A *.diwan.io → IP` (wildcard)
  - `A admin.diwan.io → IP`
- **الشهادة:** شهادة wildcard عبر DNS-01، مثلاً: `certbot certonly --dns-<provider> -d diwan.io -d '*.diwan.io'`.
- **النطاقات الخاصة للعملاء:** يضيف العميل سجل `CNAME` إلى `{subdomain}.diwan.io` وسجل `TXT _diwan-verify` من صفحة الاشتراك. لا يُخدم النطاق قبل التحقق. أصدر شهادته عبر HTTP-01 أو استخدم on-demand TLS في Caddy.

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

المُجدول يعمل كبرنامج `diwan-scheduler` في الملف نفسه (`schedule:work`). أو بدلاً منه سطر cron:

```cron
* * * * * cd /var/www/diwan && php artisan schedule:run >> /dev/null 2>&1
```

بدون المُجدول لا تعمل أي من المهام في الجدول التالي، ومنها النسخ الاحتياطي والإبلاغ لهيئة الزكاة. يكتب المُجدول نبضاً كل دقيقة يقرؤه `/health`.

| المهمة | الموعد | الوظيفة |
|---|---|---|
| `erp:zatca-submit` | كل 10 دقائق | إعادة جدولة المستندات المعلقة أو المتعثرة |
| `erp:alerts` | يومياً 06:50 | ملخص التنبيهات |
| `erp:accounting-schedules recurring` | يومياً | القيود المتكررة |
| `erp:accounting-schedules depreciation` | شهرياً | الإهلاك |
| `erp:hr-provision` | شهرياً | مخصص نهاية الخدمة والإجازات |
| `erp:backup` | يومياً 02:40 | نسخة مشفرة (ونسخة خارج الخادم إن ضُبط `BACKUP_OFFSITE_DISK`) |
| `erp:backup --verify` | الأحد 04:20 | اختبار استعادة |

## ملاحظات بيئة التشغيل

- **بعد التحديث:** شغّل `php artisan erp:sync-permissions`، لإضافة صلاحيات الفواتير الدورية ولوحات القيادة للأدوار. ثم شغّل `php artisan erp:accounting provision` مرة واحدة لإضافة الحسابات الجديدة في دليل الحسابات للمنشآت القائمة، مثل حساب «أرباح وخسائر فروق العملة». ستظهر رسالة واضحة عند سداد بعملة أجنبية إن لم يُضف الحساب.

- **شعارات المنشآت:** يعرضها النظام عبر المسار `/company/logo`، فلا تحتاج `php artisan storage:link`. يبقى الأمر مفيداً لأي ملفات عامة أخرى.
- **Windows (Laragon / XAMPP):** إنشاء مفاتيح أجهزة ZATCA لا يعتمد على `openssl.cnf` الخاص بالنظام، لأن النظام يولّد ملف إعداد خاصاً به.
- **اللغة الافتراضية:** العربية (`APP_LOCALE=ar`)، ورسائل التحقق مترجمة في `lang/ar`.

## خطوات النشر

```bash
php artisan erp:backup --tag=preupdate     # نسخة قبل التحديث خارج التدوير اليومي
php artisan down --retry=60
git pull                                   # من فرع/وسم stable
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force                # إن فشل: ابقَ في الصيانة واستعد النسخة (انظر الاستعادة)
php artisan erp:sync-permissions
php artisan erp:accounting provision       # الحسابات الجديدة للمنشآت القائمة
php artisan optimize
php artisan queue:restart
php artisan up
php artisan erp:preflight
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
| `BACKUP_DISK` | القرص الأساسي. الافتراضي `backups` (`storage/app/backups`). |
| `BACKUP_OFFSITE_DISK` | `offsite` لنسخ كل نسخة إلى تخزين S3 متوافق: AWS أو Wasabi أو Backblaze أو MinIO. الإعدادات في `BACKUP_S3_*`. |
| `BACKUP_KEEP_DAILY` / `_WEEKLY` / `_MONTHLY` | الاحتفاظ. الافتراضي 7 يومية و4 أسبوعية و6 شهرية. |
| `BACKUP_VERIFY_DATABASE` | قاعدة MySQL فارغة مخصصة لاختبار الاستعادة الكامل (تُحذف وتُنشأ في كل اختبار). بدونها يكتفي الاختبار بفحص بنية الملف. |
| `BACKUP_NOTIFY_EMAIL` | بريد يُنبَّه عند فشل النسخ أو الاختبار. |

### المتابعة

- **من الواجهة:** صفحة «النسخ الاحتياطي» في لوحة مدير المنصة، وفيها آخر نسخة واختبار الاستعادة والملفات، وزر نسخ فوري.
- **يدوياً:** `php artisan erp:backup` لإنشاء نسخة، و`php artisan erp:backup --verify` لاختبار الاستعادة.

### الاستعادة

```bash
php artisan erp:backup --tag=prerestore    # احتياطاً، نسخة من الوضع الحالي
php artisan erp:restore diwan-backup-YYYY-MM-DD-HHMMSS.zip            # من القرص الأساسي
php artisan erp:restore diwan-backup-YYYY-MM-DD-HHMMSS.zip --disk=offsite   # من النسخة الخارجية
```

**ما يفعله الأمر:**
- يضع الموقع في الصيانة.
- يفك الأرشيف بـ `BACKUP_PASSWORD`.
- يستعيد قاعدة البيانات والملفات المرفوعة.
- يشغّل الترحيل، ثم يعيد الموقع.

**على خادم جديد:** الأرشيف يحتوي `APP_KEY` الأصلي، وبدونه لا تُقرأ مفاتيح أجهزة ZATCA. إن اختلف عن الحالي يطبعه الأمر لتضعه في `.env`.

**يدوياً بدون الأمر:**
1. `7z x <file> -p"$BACKUP_PASSWORD"`.
2. `mysql diwan < database.sql`.
3. انسخ `files/public` و`files/private` إلى `storage/app/`.
4. `php artisan optimize:clear`.

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
