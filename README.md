# Personal AI Extraction API

سرویس شخصی استخراج داده ساختاریافته از تصویر با PHP 8.4+ و Symfony 7.4 LTS؛ بدون حساب کاربری یا امکانات SaaS.

مسیر HTTP، اعتبارسنجی، هماهنگی استخراج، ثبت مصرف و adapter پیشنهادی آروان پیاده‌سازی شده‌اند. **اتصال تصویری واقعی آروان هنوز تأیید نشده و هیچ مدل واقعی به صورت پیش‌فرض فعال نیست.** جزئیات و مرز شواهد در [گزارش اتصال آروان](docs/arvan-api.md) و [معماری](docs/architecture.md) آمده است.

## نصب و بررسی

نیازمندی: PHP 8.4+، Composer 2، GD با پشتیبانی JPEG/PNG/WebP، Fileinfo، PDO SQLite و افزونه‌های مشخص‌شده در composer.json. آزمون‌ها به DOM/XML نیز نیاز دارند.

```bash
composer install
cp .env.example .env.local
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
# APP_SECRET و EXTRACTION_API_KEY را با مقادیر جدا و تصادفی در .env.local تنظیم کنید.
php bin/console about
composer check
php -S 127.0.0.1:8000 -t public public/index.php
```

سرور داخلی PHP فقط برای توسعه محلی است. برای production از PHP-FPM و وب‌سرور، `APP_ENV=prod` و `APP_DEBUG=0` استفاده کنید. همه کلیدها در `.env.local`، محیط اجرا یا Symfony Secrets نگه‌داری شوند.

## تنظیمات

endpoint و کلید آروان هر دو از متغیر محیطی خوانده می‌شوند و در کد ثابت نیستند. برای تغییرشان این مقادیر را در `.env.local` تنظیم کنید:

```dotenv
ARVAN_BASE_URI=https://api.arvancloudai.ir/v1
ARVAN_API_KEY=YOUR_API_KEY
```

مقدار endpoint بالا فقط نمونه است و می‌توانید آن را جایگزین کنید. کلید را بدون پیشوند `apikey` وارد کنید. `.env.local` در Git ذخیره نمی‌شود؛ در استقرار نیز می‌توانید همین متغیرها را مستقیماً در محیط اجرا تنظیم کنید. اگر برای مدل در `AI_MODELS_JSON` مقدار `base_uri` تعیین کرده‌اید، آن مقدار بر endpoint مشترک اولویت دارد؛ برای استفاده از endpoint مشترک، override آن مدل را حذف کنید. پس از تغییر محیط، workerهای طولانی‌مدت را restart کنید؛ اگر از `composer dump-env` استفاده می‌کنید، فایل محیط کامپایل‌شده را نیز دوباره بسازید.

| متغیر | کاربرد |
| --- | --- |
| APP_ENV / APP_DEBUG / APP_SECRET | محیط و تنظیمات Symfony |
| EXTRACTION_API_KEY | کلید Bearer سرویس؛ خالی باشد API با 503 بسته می‌ماند |
| ARVAN_BASE_URI | نشانی مشترک اعلام‌شده: `https://api.arvancloudai.ir/v1`؛ قابل override با base_uri هر مدل |
| ARVAN_API_KEY | فقط مقدار خام کلید آروان؛ adapter پیشوند apikey را اضافه می‌کند |
| AI_DEFAULT_MODEL | مدل پیش‌فرض؛ باید در allowlist باشد |
| AI_MODELS_JSON | مدل‌ها، endpoint و قابلیت‌های تأییدشده؛ پیش‌فرض `{}` |
| AI_PRICING_JSON | قیمت‌های اختیاری بر حسب یک میلیون توکن؛ پیش‌فرض `{}` |
| AI_REQUEST_TIMEOUT_SECONDS | deadline کل درخواست provider؛ پیش‌فرض ۶۰ ثانیه |
| AI_MAX_RETRIES | پیش‌فرض صفر؛ از صفر تا سه retry برای 429/503 |
| IMAGE_MAX_BYTES | پیش‌فرض 10485760 بایت |
| IMAGE_MAX_WIDTH / IMAGE_MAX_HEIGHT | پیش‌فرض 8192 برای هر بعد |
| IMAGE_MAX_PIXELS | پیش‌فرض 40000000؛ محدودیت حافظه نیز جداگانه کنترل می‌شود |
| API_MAX_REQUEST_BYTES | پیش‌فرض 11534336، شامل multipart و فیلدها |
| USAGE_DATABASE_PATH | پیش‌فرض `%kernel.project_dir%/var/usage.sqlite` |

نمونه پیکربندی **غیرفعال و با مقادیر جایگزین**:

```dotenv
AI_DEFAULT_MODEL=YOUR_VERIFIED_MODEL
AI_MODELS_JSON='{"YOUR_VERIFIED_MODEL":{"vision":false,"structured_output":false,"image_profile_verified":false,"structured_output_verified":false}}'
```

نشانی مشترک در `ARVAN_BASE_URI` ثبت شده است. اگر مدل endpoint جداگانه دارد، `base_uri` آن را کامل و دقیق از پنل کپی کنید؛ `/v1` یا نام مدل را حدس نزنید. فعال‌سازی `vision` و `image_profile_verified` فقط بعد از تأیید سازگاری تصویری پروفایل mapper با endpoint مجاز است. برای structured output، هر دو فلگ مربوطه باید تأیید شوند. ارسال تصویر نباید برای مدل متنی فعال شود.

نمونه قیمت **فرضی، نه قیمت آروان**:

```dotenv
AI_PRICING_JSON='{"YOUR_VERIFIED_MODEL":{"input_per_million":"1.00","output_per_million":"2.00","currency":"USD"}}'
```

قیمت باید با مدل گزارش‌شده در پاسخ تطابق داشته باشد. اگر قیمت یا شمارنده لازم غایب باشد `estimated_cost=null` است.

## فراخوانی API پس از تنظیم مدل تأییدشده

```bash
curl http://127.0.0.1:8000/api/v1/extractions \
  -H "Authorization: Bearer $EXTRACTION_API_KEY" \
  -H 'Idempotency-Key: menu-import-001' \
  -F 'image=@menu.png' \
  -F 'instructions=Extract menu items and prices without inventing missing values.' \
  -F 'schema={"type":"object","properties":{"items":{"type":"array","items":{"type":"object","properties":{"name":{"type":"string"},"price":{"type":"number"}},"required":["name","price"]}}},"required":["items"]}'
```

متغیر شل EXTRACTION_API_KEY باید قبلاً تنظیم شده باشد؛ `.env.local` خودکار به shell وارد نمی‌شود. فیلد `model` اختیاری است. `schema` از draft 2020-12، مرجع محلی و object یا boolean پشتیبانی می‌کند؛ منابع خارجی و extensionهای اجرایی Opis پذیرفته نمی‌شوند. schema حداکثر 64 KiB و عمق ۳۲ دارد؛ دستور حداکثر ۱۶۰۰۰ کاراکتر است.

نمونه پاسخ ساختگی:

```json
{
  "success": true,
  "request_id": "a450e5e1-92be-4f84-b1be-7a56ff54c018",
  "model": "YOUR_VERIFIED_MODEL",
  "data": {"items": [{"name": "Tea", "price": 12.5}]},
  "usage": {"input_tokens": 120, "output_tokens": 30},
  "duration_seconds": 0.8,
  "estimated_cost": null
}
```

فقط مصرف گزارش‌شده برگردانده می‌شود؛ مجموع حدس زده نمی‌شود. `{}` و `[]` و نوع رشته/عدد خروجی حفظ می‌شوند. خروجی نامعتبر یا ناقص هرگز پاسخ موفق نمی‌شود. فایل تصویر فقط در حافظه و فایل موقت upload PHP وجود دارد و در پایان چرخه درخواست پاک می‌شود؛ برنامه نسخه دائمی آن را ذخیره نمی‌کند.

## خطاها و قابلیت اطمینان

همه خطاهای `/api/` قالب `{"success":false,"request_id":"...","error":{"code":"...","message":"..."}}` دارند و پاسخ‌ها دارای `X-Request-ID` و `Cache-Control: no-store` هستند.

| HTTP | وضعیت |
| --- | --- |
| 401 | کلید سرویس نامعتبر یا غایب |
| 409 | Idempotency-Key قبلاً مصرف شده؛ پاسخ قبلی بازپخش نمی‌شود |
| 413 | درخواست از محدودیت حجم عبور کرده |
| 415 | بدنه غیر multipart |
| 422 | ورودی، تصویر، schema یا مدل نامعتبر |
| 502 | پاسخ provider نامعتبر، خطای upstream یا عدم تطابق خروجی با schema |
| 503 | پیکربندی ناقص، مدل تأییدنشده، rate limit یا اشکال ذخیره مصرف |
| 504 | timeout provider |

خطاهای انتقال مبهم retry نمی‌شوند تا احتمال دوباره‌پردازش کاهش یابد. با Idempotency-Key، همان کلید پس از موفقیت یا خطا دوباره قابل استفاده نیست. درخواست بدون این کلید ممکن است تکرار شود. خطای ذخیره مصرف پس از inference ممکن است هزینه‌ای ایجاد کرده باشد؛ ابتدا وضعیت درخواست را بررسی کنید.

وب‌سرور باید حجم بدنه را حتی برای chunked upload محدود کند؛ کنترل Content-Length برنامه به‌تنهایی جایگزین آن نیست. برای مقادیر پیش‌فرض، Nginx را با `client_max_body_size 11m` و PHP را با `upload_max_filesize=10M`، `post_max_size=11M` و حافظه مناسب تنظیم کنید. تغییر اندازه مجاز تصویر باید با این محدودیت‌ها هماهنگ شود. اعتبارسنجی تصویر پیش از decode، ابعاد/پیکسل و بودجه حافظه را کنترل می‌کند.

## ثبت مصرف و تست

جدول SQLite خودکار در اولین درخواست پردازش ایجاد می‌شود. فقط request_id، هش کلید idempotency، مدل، وضعیت، زمان، شمارنده‌های واقعی و هزینه اختیاری ثبت می‌شوند. تصویر، Base64، دستور، schema و داده استخراج‌شده ثبت نمی‌شوند. فایل دیتابیس را خارج از public نگه دارید؛ پوشه var در gitignore است. برای پایگاه داده دیگر، قرارداد UsageRecorder را پیاده‌سازی کنید.

```bash
composer check
composer check-platform-reqs
```

آزمون‌ها از MockHttpClient/MockResponse یا mock قرارداد AIProvider استفاده می‌کنند؛ هیچ تماس AI واقعی ندارند. نصب فعلی با composer.lock هماهنگ است و مشکل دانلود مرحله قبل رفع شده است. تست‌های پروفایل پیشنهادی، تأیید سازگاری واقعی با آروان نیستند.

برای افزودن provider جدید، AIProvider را در Infrastructure پیاده کنید، mapperها و آزمون‌های آن را اضافه کنید و alias قرارداد را در services.yaml تغییر دهید. Application تغییر نمی‌کند.
