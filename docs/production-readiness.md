# چک‌لیست آمادگی استقرار

این سند برای فاز استقرار آینده است و هیچ storage خارجی را فعال نمی‌کند.

## میزبان و PHP

- PHP 8.2 یا جدیدتر و extensionهای موردنیاز Laravel، MySQL، fileinfo و mbstring فراهم باشد.
- DocumentRoot دامنه مستقیماً روی پوشه `public` قرار گیرد.
- `storage` و `bootstrap/cache` برای کاربر PHP قابل‌نوشتن باشند.
- HTTPS اجباری و redirect از HTTP فعال باشد.
- `APP_ENV=production`، `APP_DEBUG=false` و یک `APP_KEY` اختصاصی production تنظیم شود.
- credentialهای دیتابیس، ایمیل و storage فقط در `.env` میزبان باشند و وارد Git نشوند.
- `SESSION_SECURE_COOKIE=true` روی HTTPS استفاده شود. SameSite پیش‌فرض `lax` برای جریان فعلی مناسب است.

## عملیات

- migration با backup و در پنجره نگهداری اجرا شود.
- worker دیتابیس دائمی یا cron کوتاه‌عمر `queue:work --stop-when-empty` تعریف شود.
- خروجی‌های `queue:failed` بررسی و در صورت رفع علت با `queue:retry ID` تکرار شوند؛ `queue:forget ID` فقط پس از تصمیم عملیاتی اجرا شود.
- cron برای `video:cleanup-uploads`، پاک‌سازی sessionهای منقضی و scheduler لاراول تعریف شود.
- `video:verify-assets` به‌صورت دوره‌ای اجرا شود؛ این فرمان read-only است.
- وضعیت `video:dispatch-pending` و `video:process ID` برای بازیابی پردازش مستند بماند.
- log rotation، پایش فضای دیسک و backup دوره‌ای فعال باشد.
- `storage:link` فقط برای coverهای عمومی لازم است؛ ضمیمه‌ها و ویدیوها نباید به مسیر عمومی link شوند.

## ویدیو و Phase 9

- موجود بودن FFmpeg/FFprobe روی production قطعی فرض نشود؛ processor می‌تواند غیرفعال یا بعداً جایگزین شود.
- credentialهای storage خارجی، سیاست URL موقت و مهاجرت assetها در Phase 9 تعیین شوند.
- پیش از انتقال، ظرفیت upload، timeout، queue retry-after و فضای موقت پردازش بررسی شود.

## سیاست پشتیبان‌گیری

1. سورس برنامه از Git و یک mirror/آرشیو مستقل بازیابی‌پذیر باشد.
2. دیتابیس با backup زمان‌بندی‌شده، retention و آزمون restore محافظت شود.
3. coverها و سایر assetهای عمومی جداگانه backup شوند.
4. ضمیمه‌های خصوصی درس در backup رمزگذاری‌شده قرار گیرند.
5. ویدیوهای اصلی تا زمان پذیرش صریح ریسک کسب‌وکار نگهداری شوند.
6. ویدیوهای پردازش‌شده نیز backup یا قابلیت بازتولید آزموده‌شده داشته باشند.

یک download host بدون backup نباید تنها نسخه ویدیوهای منبع غیرقابل‌جایگزین باشد، مگر اینکه این ریسک رسماً پذیرفته شده باشد.
