# وضعیت اتصال آروان

بررسی مستندات عمومی: 2026-11-01. هیچ درخواست واقعی inference ارسال نشده است.

## نشانی اعلام‌شده توسط کاربر

`ARVAN_BASE_URI=https://api.arvancloudai.ir/v1`

این نشانی به عنوان base URL مشترک ثبت شده است؛ adapter مسیر `/chat/completions` را به آن اضافه می‌کند. `base_uri` در تنظیمات هر مدل، در صورت وجود، بر این مقدار اولویت دارد. آزمون این اتصال با MockHttpClient انجام می‌شود.

در بررسی بدون credential، GET خود `/v1` پاسخ 404 داد؛ این نتیجه به‌تنهایی درباره وجود مسیرهای زیرمجموعه یا سازگاری تصویری چیزی اثبات نمی‌کند. GET مسیر `/v1/models` نیز بدون credential پاسخ 401 داد، بنابراین فهرست مدل‌ها قابل دریافت نبود. ارائه base URL، تأیید مدل، روش احراز هویت این gateway یا قالب تصویر و structured output نیست؛ موارد تأییدنشده زیر همچنان باقی‌اند.

## موارد مستند

- [ساخت Endpoint و REST](https://docs.arvancloud.ir/fa/aiaas/api-usage/): endpoint اختصاصی از پنل دریافت می‌شود؛ مسیر فراخوانی `chat/completions` است و نمونه REST هدر `Authorization: apikey ...` را نشان می‌دهد. این پروژه همین روش مستقیم را استفاده می‌کند.
- [نمونه SDK](https://docs.arvancloud.ir/fa/aiaas/examples/openai/): نمونه‌های SDK سازگار با Chat Completions و خواندن `choices[0].message.content` ارائه شده‌اند؛ endpointها ممکن است مسیر مدل و `/v1` داشته باشند. `base_uri` باید دقیقاً از پنل کپی شود؛ برنامه فقط `/chat/completions` را اضافه می‌کند.
- [مدیریت مدل‌ها](https://docs.arvancloud.ir/fa/aiaas/): هر مدل می‌تواند endpoint مجزا داشته باشد.

## موارد تأییدنشده و رفتار فعلی

در صفحات عمومی بررسی‌شده، نمونه ارسال تصویر، فهرست قابل اتکای مدل‌های vision، پشتیبانی JSON Schema بومی هر endpoint و قالب کامل usage ارائه نشده است. صفحه [API](https://www.arvancloud.ir/api/aiaas/1.0) نیز مشخصات قابل‌خواندن این موارد را ارائه نکرد.

`ArvanRequestMapper` و `ArvanResponseMapper` یک **پروفایل پیشنهادی سازگار با OpenAI Chat Completions** هستند؛ تطبیق تصویری آن‌ها با آروان هنوز تأیید نشده است. به‌صورت مشخص، `image_url` با data URI، `response_format.json_schema`، finish_reason و فیلدهای تفصیلی usage فرض‌های این پروفایل‌اند، نه ادعای تأیید آن‌ها در مستندات آروان. آزمون‌ها داده ساختگی این پروفایل را بررسی می‌کنند.

هیچ مدل واقعی به طور پیش‌فرض فعال نیست. adapter پیش از ارسال شبکه، `image_profile_verified=true` را برای همان endpoint الزام می‌کند؛ قابلیت structured output علاوه بر `structured_output=true` به `structured_output_verified=true` نیاز دارد. این فلگ‌ها فقط پس از بررسی مستندات یا نمونه درخواست/پاسخ معتبر همان endpoint باید تغییر کنند. تغییر فلگ بدون بررسی، سازگاری ایجاد نمی‌کند.

نام Gemini-2.5-Pro در مثال رسمی SDK دیده می‌شود، اما مثال متنی، تأیید قابلیت تصویر یا JSON Schema در gateway آروان نیست؛ بنابراین آن را خودکار وارد allowlist نکرده‌ایم.

برای نهایی‌کردن اتصال، لینک مستندات یا نمونه پاک‌سازی‌شده درخواست و پاسخ تصویری همان مدل لازم است. کلید API، URL خصوصی کامل endpoint، تصویر شخصی یا اطلاعات محرمانه نباید در مستندات یا گفتگو قرار گیرد.

## نگاشت پروفایل پیشنهادی

| ورودی provider | داده مستقل برنامه |
| --- | --- |
| choices[0].message.content | متن تولیدشده؛ سپس اعتبارسنجی با schema در Application |
| model | مدل گزارش‌شده؛ در نبود آن شناسه درخواست |
| usage.prompt_tokens | input_tokens |
| usage.completion_tokens | output_tokens |
| usage.total_tokens | total_tokens |
| usage.prompt_tokens_details.image_tokens | image_tokens |
| usage.completion_tokens_details.reasoning_tokens | reasoning_tokens |

شمارنده غایب null می‌ماند، در API حذف و در SQLite به صورت NULL ذخیره می‌شود. خطای provider، refusal و پاسخ ناقص موفق تلقی نمی‌شوند. پیام خطا و exception اصلی upstream به پاسخ یا زنجیره exception منتقل نمی‌شود.
