# معماری تأییدشده

معماری توسط کاربر تأیید شده و مرحله پیاده‌سازی آغاز شده است. وضعیت نهایی اتصال واقعی آروان در [گزارش اتصال](arvan-api.md) آمده است؛ پروفایل تصویری فعلی پیشنهادی و تا تأیید هر endpoint غیرفعال است.

| لایه | مسئولیت |
| --- | --- |
| Domain/AI | قرارداد AIProvider، درخواست، پاسخ، مدل، مصرف و خطاهای مستقل از Symfony |
| Domain/Extraction | قرارداد UsageRecorder |
| Application/Extraction | allowlist مدل، اعتبارسنجی schema و خروجی، هماهنگی استخراج و تخمین هزینه |
| Infrastructure/AI/Arvan | HttpClient اختصاصی، نگاشت پروفایل Chat Completions، timeout و retry محدود |
| Infrastructure/Image | Validator تصویر، MIME واقعی، امضا، اندازه، ابعاد، پیکسل و سلامت تصویر |
| Infrastructure/Persistence | ثبت مصرف و جلوگیری از تکرار کلید درخواست در SQLite |
| Presentation/Http | Bearer اجباری، DTO و mapper ورودی multipart، کنترلر و پاسخ/خطای JSON |

جهت وابستگی: Presentation → Application → Domain؛ Infrastructure قراردادهای Domain را پیاده می‌کند. اتصال وابستگی‌ها در `config/services.yaml` است. اعتبارسنجی فایل در مرز HTTP، پیش از تحویل ImageInput مستقل از Symfony به Application انجام می‌شود.

## قرارداد provider

```php
public function generate(AIRequest $request): AIResponse;
```

درخواست شامل مدل انتخاب‌شده از تنظیمات معتبر، بایت‌های تصویر تأییدشده، دستور و رشته JSON Schema است. پاسخ شامل متن هنوز نامعتبر، مدل، شمارنده‌های nullable و مدت فراخوانی بر حسب ثانیه است. adapter جای اعتبارسنجی کسب‌وکار را نمی‌گیرد. خروجی native نیز دوباره اعتبارسنجی می‌شود.

قابلیت vision و structured output از تنظیمات سرور می‌آید؛ کاربر فقط شناسه مدل را انتخاب می‌کند. endpoint هر مدل جداست و دامنه استخراج اطلاعی از مسیرهای آروان ندارد. برای مدل بدون خروجی native، mapper از دستور تولید JSON استفاده می‌کند و خروجی همچنان باید دقیقاً schema را پاس کند.

## مسیر اجرا

1. ساخت request_id مستقل، احراز هویت با hash_equals و کنترل Content-Length.
2. نگاشت و اعتبارسنجی DTO؛ تصویر JPEG/PNG/WebP تا ۱۰ MiB، کنترل ابعاد و پیکسل پیش از decompress و بررسی بودجه حافظه و سلامت تصویر.
3. اعتبارسنجی JSON Schema draft 2020-12 با metaschema محلی؛ resolve مدل مجاز.
4. ثبت درخواست در SQLite؛ در صورت وجود Idempotency-Key تکراری، رد با 409 پیش از inference.
5. فراخوانی provider با timeout کل، بدون redirect و بدون profiler مربوط به HttpClient.
6. parse خروجی و حذف صرفاً یک fence کامل Markdown؛ حفظ object/array و انواع داده، سپس اعتبارسنجی با Opis.
7. ثبت مصرف واقعی و هزینه قابل محاسبه؛ حتی خروجی نامعتبر دارای پاسخ AI، مصرفش ثبت می‌شود. تصویر، دستور، schema و داده استخراج‌شده در دیتابیس ذخیره نمی‌شوند.

## تصمیم‌های اجرایی

- Symfony 7.4 LTS، PHP 8.4+، Opis 2.x، PHPUnit 12 و MockHttpClient.
- Mime و GD برای اعتبارسنجی فایل اضافه شده‌اند. دامنه به UploadedFile وابسته نیست.
- برای یک سرویس شخصی با یک جدول، PDO SQLite کافی است و فعلاً ORM اضافه نشده است. قرارداد UsageRecorder امکان adapter مبتنی بر Doctrine را حفظ می‌کند.
- مصرف نامعلوم تخمین زده نمی‌شود؛ قیمت‌ها اختیاری و از تنظیمات جدا هستند. اجزای image/reasoning دوباره به مجموع توکن افزوده نمی‌شوند.
- retry پیش‌فرض صفر است. در صورت تنظیم، فقط 429 و 503 تا سه retry، با تأخیر محدود و داخل deadline کل تکرار می‌شوند. timeout یا خطای مبهم انتقال تکرار نمی‌شود چون ممکن است پردازش انجام شده باشد.
- Idempotency-Key صرفاً مانع اجرای مجدد است؛ پاسخ قبلی cache نمی‌شود و کلید حتی پس از خطا رزرو می‌ماند. برای پردازش مجدد آگاهانه کلید جدید لازم است. بدون این هدر، deduplication انجام نمی‌شود.
- نمونه‌ها و آزمون‌های provider، سازگاری واقعی تصویر با آروان را اثبات نمی‌کنند. فعال‌سازی واقعی نیازمند بررسی [موارد تأییدنشده](arvan-api.md) است.
