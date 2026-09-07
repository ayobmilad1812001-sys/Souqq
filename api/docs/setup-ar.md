# دليل التنصيب والتشغيل — LibyaMarket API

> هذا الملف بالعربية. للتوثيق التقني الكامل انظر [docs/README.md](README.md).

---

## أولاً: تنزيل المشروع على جهازك

المشروع موجود حالياً في مجلد مؤقت خاص بالجلسة، ويُحذف تلقائياً عند انتهائها.
لذلك يجب نقله إلى مكان دائم على جهازك بإحدى الطريقتين:

### الطريقة 1 — ملف مضغوط (الأسهل)

يوجد ملف `libyamarket.zip` مرفق في المحادثة. اضغط عليه لتنزيله، ثم:

1. انقل الملف إلى المكان الذي تريده، مثلاً `C:\projects`
2. اضغط بالزر الأيمن على الملف واختر **Extract All** (استخراج الكل)
3. سيصبح لديك مجلد `libyamarket` يحتوي على المشروع كاملاً

**ملاحظة:** الملف المضغوط لا يحتوي على مجلد `vendor` (مكتبات Laravel)
ولا على ملف `.env`، لأنهما يُنشآن محلياً. الخطوات أدناه تشرح كيف تنشئهما.

### الطريقة 2 — نسخ المجلد مباشرة

المشروع منسوخ أيضاً إلى مجلد دائم على جهازك. الرسالة في المحادثة تذكر المسار
بالضبط. هذه النسخة **كاملة** وتشمل `vendor`، أي أنها جاهزة للتشغيل فوراً بدون
أي تنزيل إضافي.

---

## ثانياً: متطلبات التشغيل

| المتطلب | الإصدار | ملاحظة |
| --- | --- | --- |
| PHP | 8.2 أو أحدث | موجود لديك في `C:\xampp\php` |
| Composer | 2.x | ملف `composer.phar` مرفق داخل المشروع |
| MySQL | 8.0 أو أحدث | أو استخدم Docker |
| Redis | 7.x | للكاش والطوابير |
| Docker Desktop | اختياري | يوفّر كل ما سبق دفعة واحدة |

الامتدادات المطلوبة في PHP: `pdo_mysql`، `mbstring`، `openssl`، `tokenizer`،
`xml`، `ctype`، `json`، `fileinfo`، `curl`.

---

## ثالثاً: التشغيل باستخدام Docker (الطريقة المُوصى بها)

هذه أسهل طريقة، لأنها تشغّل MySQL و Redis والخادم تلقائياً.

### 1. نزّل Docker Desktop

من الموقع الرسمي: <https://www.docker.com/products/docker-desktop/>
ثم ثبّته وأعد تشغيل الجهاز، وتأكد أنه يعمل (أيقونة الحوت في شريط المهام).

### 2. شغّل المشروع

افتح **PowerShell** داخل مجلد المشروع ونفّذ:

```bash
cp .env.example .env
docker compose up -d --build
```

البناء في المرة الأولى يستغرق عدة دقائق (تنزيل الصور وتثبيت المكتبات).

### 3. جهّز قاعدة البيانات

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

### 4. جرّب الـ API

افتح المتصفح على:

```
http://localhost:8000/api/v1/products
```

### أوامر Docker مفيدة

```bash
docker compose ps                      # حالة الخدمات
docker compose logs -f app             # سجلات التطبيق
docker compose logs -f queue-worker    # سجلات معالج الطوابير
docker compose exec app bash           # الدخول إلى الحاوية
docker compose exec app php artisan test   # تشغيل الاختبارات
docker compose down                    # إيقاف كل شيء
docker compose down -v                 # إيقاف وحذف قواعد البيانات
```

---

## رابعاً: التشغيل بدون Docker

إذا كنت تفضّل استخدام PHP و MySQL المثبتين على جهازك مباشرة.

### 1. ثبّت المكتبات

المشروع يحتوي على `composer.phar` جاهز، فلا حاجة لتنصيب Composer عالمياً:

```bash
C:\xampp\php\php.exe composer.phar install
```

> إذا ظهرت رسالة **"The zip extension and unzip/7z commands are both missing"**
> فهذا يعني أن امتداد zip غير مُفعّل. الحل بدون تعديل ملف `php.ini`:
>
> ```bash
> C:\xampp\php\php.exe -d extension_dir="C:\xampp\php\ext" -d extension=php_zip.dll composer.phar install
> ```

### 2. أنشئ ملف الإعدادات

```bash
cp .env.example .env
C:\xampp\php\php.exe artisan key:generate
```

### 3. اضبط الاتصال بقاعدة البيانات

افتح ملف `.env` وعدّل هذه القيم:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=libyamarket
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
```

> **مهم جداً:** القيم الافتراضية في `.env.example` هي `mysql` و `redis`،
> وهذه أسماء خدمات Docker ولا تعمل خارجها. عند التشغيل المحلي يجب تغييرها
> إلى `127.0.0.1` كما في الأعلى.

إذا لم يكن Redis مثبتاً لديك، يمكنك تعطيله مؤقتاً:

```env
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=file
```

### 4. أنشئ قاعدة البيانات

من phpMyAdmin أو سطر الأوامر:

```sql
CREATE DATABASE libyamarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. نفّذ الجداول والبيانات التجريبية

```bash
C:\xampp\php\php.exe artisan migrate --seed
```

### 6. شغّل الخادم

```bash
C:\xampp\php\php.exe artisan serve
```

ثم افتح: <http://localhost:8000/api/v1/products>

### 7. شغّل معالج الطوابير (في نافذة أخرى)

```bash
C:\xampp\php\php.exe artisan queue:work
```

هذا مسؤول عن إرسال رسائل تأكيد الطلبات وتسجيل حركة المخزون.

---

## خامساً: تشغيل الاختبارات

المشروع يحتوي على **141 اختباراً** وجميعها ناجحة.

```bash
C:\xampp\php\php.exe artisan test
```

النتيجة المتوقعة:

```
Tests:    141 passed (429 assertions)
Duration: ~7s
```

لتشغيل مجموعة محددة:

```bash
C:\xampp\php\php.exe artisan test --testsuite=Unit       # 26 اختبار
C:\xampp\php\php.exe artisan test --testsuite=Feature    # 115 اختبار
C:\xampp\php\php.exe artisan test --filter=OrderPlacementTest
```

> الاختبارات تستخدم قاعدة بيانات SQLite في الذاكرة، فلا تحتاج إلى MySQL
> ولا تؤثر على بياناتك إطلاقاً.

---

## سادساً: الحسابات التجريبية

بعد تنفيذ `migrate --seed` تُنشأ بيانات تجريبية كاملة.
كلمة المرور لجميع الحسابات هي: `password`

| البريد الإلكتروني | الدور |
| --- | --- |
| `admin@libyamarket.test` | مدير المنصة |
| `customer@libyamarket.test` | عميل |

البيانات التجريبية تشمل: 5 بائعين، 21 عميلاً، 6 تصنيفات، و75 منتجاً
(بينها منتجات نفد مخزونها وأخرى غير مفعّلة، لاختبار الحالات غير المثالية).

---

## سابعاً: تجربة الـ API

### 1. تسجيل الدخول والحصول على التوكن

```bash
curl -X POST http://localhost:8000/api/v1/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"customer@libyamarket.test\",\"password\":\"password\"}"
```

ستحصل على رد يحتوي على `token`. احتفظ به.

### 2. استخدام التوكن

```bash
curl http://localhost:8000/api/v1/cart ^
  -H "Authorization: Bearer ضع_التوكن_هنا"
```

### 3. تصفح المنتجات (بدون تسجيل دخول)

```bash
curl "http://localhost:8000/api/v1/products?search=keyboard&sort=price_asc&per_page=10"
```

> **نصيحة:** استخدم **Postman** أو **Insomnia** بدلاً من `curl` — أسهل بكثير
> للتعامل مع التوكن والـ JSON.

---

## ثامناً: حل المشاكل الشائعة

| المشكلة | السبب | الحل |
| --- | --- | --- |
| `Class "PDO" not found` | امتداد `pdo_mysql` غير مفعّل | فعّله في `php.ini` |
| `SQLSTATE[HY000] [2002]` | لا يمكن الاتصال بـ MySQL | تأكد أن MySQL يعمل وأن `DB_HOST=127.0.0.1` |
| `Connection refused` على Redis | Redis غير مثبت | غيّر `CACHE_STORE=file` و `QUEUE_CONNECTION=database` |
| `No application encryption key` | مفتاح التشفير مفقود | نفّذ `php artisan key:generate` |
| `zip extension missing` | امتداد zip معطّل | استخدم الأمر مع `-d extension=php_zip.dll` |
| رسائل التأكيد لا تُرسل | معالج الطوابير متوقف | شغّل `php artisan queue:work` |
| تعديلات لا تظهر | كاش الإعدادات | نفّذ `php artisan config:clear` |

---

## تاسعاً: أوامر يومية مفيدة

```bash
php artisan route:list --path=api    # عرض كل المسارات
php artisan migrate:fresh --seed     # إعادة بناء قاعدة البيانات من الصفر
php artisan cache:clear              # مسح الكاش
php artisan config:clear             # مسح كاش الإعدادات
php artisan queue:failed             # عرض المهام الفاشلة
php artisan queue:retry all          # إعادة محاولة المهام الفاشلة
php artisan tinker                   # سطر أوامر تفاعلي
```

---

## عاشراً: هيكل المشروع باختصار

```
libyamarket/
├── app/
│   ├── Enums/          الأدوار وحالات الطلب
│   ├── Exceptions/     أخطاء منطق العمل
│   ├── Http/           المتحكمات، التحقق، التنسيق
│   ├── Models/         نماذج قاعدة البيانات
│   ├── Observers/      إبطال الكاش تلقائياً
│   ├── Policies/       الصلاحيات وعزل البائعين
│   ├── Services/       منطق العمل والمعاملات
│   ├── Jobs/           المهام الخلفية
│   ├── Events/         الأحداث
│   └── Support/        Money، ApiResponse، CacheKeys
├── config/marketplace.php   إعدادات الشحن والإلغاء
├── database/           الهجرات والبيانات التجريبية
├── docker/             ملفات Docker
├── docs/               التوثيق (17 ملفاً)
├── routes/api.php      27 مساراً
└── tests/              141 اختباراً
```

---

## ملاحظة أخيرة

بنية Docker مكتوبة بالكامل لكنها **لم تُختبر عملياً**، لأن Docker Desktop لم
يكن مثبتاً على الجهاز الذي بُني عليه المشروع. أما التطبيق نفسه فمُختبر بالكامل:
141 اختباراً ناجحاً، والهجرات والبيانات التجريبية تعمل بشكل سليم.

---

## ملحق: مشكلة `php artisan serve` على ويندوز

### العَرَض

```
Failed to listen on 127.0.0.1:8000 (reason: ?)
Failed to listen on 127.0.0.1:8001 (reason: ?)
...
```

### السبب — وليس Herd

هذا **خطأ في Laravel نفسه على ويندوز**، ولا علاقة له بـ Herd.

الأمر `artisan serve` يشغّل خادم PHP في عملية فرعية، وقبل ذلك **يمسح كل متغيرات
البيئة** غير الموجودة في قائمة بيضاء اسمها `ServeCommand::$passthroughVariables`.

القائمة تحتوي على `SYSTEMROOT` بحروف كبيرة، لكن ويندوز يسمّي المتغيّر
`SystemRoot`. ولأن `in_array()` **حسّاسة لحالة الأحرف**، لا يتطابق الاسمان
فيُمسح المتغيّر الحقيقي.

بدون `SystemRoot` لا تستطيع مكتبة الشبكات في ويندوز (Winsock) أن تبدأ عملها،
فيفشل كل `bind()` برمز خطأ بلا رسالة — وهذا بالضبط معنى `(reason: ?)`.

ولهذا يفشل على 11 منفذاً متتالياً رغم أن جميعها فارغة تماماً.

### الحل المطبَّق في المشروع

أُضيفت الدالة `configureServeCommand()` في
[`app/Providers/AppServiceProvider.php`](../app/Providers/AppServiceProvider.php):

```php
private function configureServeCommand(): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }

    foreach (['SystemRoot', 'windir', 'TEMP', 'TMP'] as $variable) {
        if (! in_array($variable, ServeCommand::$passthroughVariables, true)) {
            ServeCommand::$passthroughVariables[] = $variable;
        }
    }
}
```

`$passthroughVariables` خاصية `public static`، لذلك يمكن تعديلها من داخل
المشروع **دون تعديل مجلد `vendor`** — وهذا مهم لأن `composer install` يمسح أي
تعديل هناك.

الأمر `php artisan serve` يعمل الآن بشكل طبيعي.

### حل بديل سريع (بدون تعديل كود)

```bash
php artisan serve --no-reload
```

الخيار `--no-reload` يوقف مراقبة ملف `.env`، وعندها يمرّر Laravel البيئة كاملة
دون مسح. العيب أن الخادم لن يعيد التشغيل تلقائياً عند تعديل `.env`.

### الأفضل مع Herd: لا تستخدم `artisan serve` أصلاً

Herd يشغّل الموقع تلقائياً. اربط المشروع مرة واحدة:

```bash
cd C:\Users\AM\Desktop\libyamarket
herd link libyamarket
```

ثم افتح: <http://libyamarket.test/api/v1/products>

هذا أسرع وأقرب للإنتاج، لأنه يستخدم nginx + php-fpm بدل خادم PHP التطويري
أحادي العملية.

---

## ملحق: تنبيه مهم عند إيقاف الخادم

إذا أوقفت `artisan serve` بطريقة غير نظيفة، تبقى عملية `php.exe` **يتيمة**
ممسكة بالمنفذ 8000 ومحمّلة الإعدادات القديمة. النتيجة أنك تعدّل `.env` ولا ترى
أي تغيير، أو تظهر أخطاء عن خدمات غيّرتها بالفعل.

للتحقق والتنظيف:

```powershell
Get-NetTCPConnection -State Listen | Where-Object { $_.LocalPort -eq 8000 }
Get-Process php | Stop-Process -Force
```

---

## ملحق: الإعدادات المستخدمة حالياً على جهازك

جرى ضبط `.env` على ما هو متاح فعلياً على جهازك:

```env
DB_CONNECTION=sqlite
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=file
```

**لماذا SQLite؟** المنفذ 3306 مفتوح على جهازك لكن خادم MySQL رفض كل بيانات
الدخول المعتادة (`root` بكلمة فارغة، و`root/root`، و`root/secret`). كلمة المرور
عندك أنت، لذلك اخترت SQLite ليعمل المشروع فوراً بدون أي إعداد.

**لماذا لا Redis؟** المنفذ 6379 مغلق — Redis غير مشغّل على جهازك.

### للتحويل إلى MySQL لاحقاً

عدّل `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=libyamarket
DB_USERNAME=root
DB_PASSWORD=كلمة_المرور_عندك
```

ثم:

```bash
php artisan config:clear
php artisan migrate:fresh --seed
```

> ملاحظة: تشغيل المشروع على MySQL هو الطريقة الوحيدة لاختبار القفل التشاؤمي
> (`SELECT ... FOR UPDATE`) فعلياً، لأن SQLite تتجاهله. التفاصيل في
> [07-concurrency-inventory.md](07-concurrency-inventory.md).
