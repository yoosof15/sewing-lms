# Sewing LMS

سامانه یکپارچه Laravel 12 + Inertia 2 + Vue 3 برای آموزشگاه خیاطی، با رابط فارسی و راست‌به‌چپ.

## نیازمندی‌ها و راه‌اندازی محلی

- PHP 8.2+ با `pdo_mysql`
- Composer 2، MySQL، Node.js 22 و npm 10

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# اتصال MySQL را در .env تنظیم کنید
php artisan migrate
php artisan storage:link
npm.cmd install
npm.cmd run dev
php artisan serve
```

در Windows PowerShell ممکن است `npm.ps1` مسدود باشد؛ بدون تغییر Execution Policy از `npm.cmd` استفاده کنید. برای build تولید: `npm.cmd run build`.

## مدیر محلی اولیه

مقادیر `LOCAL_ADMIN_FIRST_NAME`، `LOCAL_ADMIN_LAST_NAME`، `LOCAL_ADMIN_MOBILE`، `LOCAL_ADMIN_EMAIL` و `LOCAL_ADMIN_PASSWORD` را فقط در `.env` محلی قرار دهید، سپس اجرا کنید:

```powershell
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=LocalAdminSeeder
```

Seeder دوم فقط در محیط `local` اجرا می‌شود و رمز حساب موجود را بازنویسی نمی‌کند. در تولید، `RoleSeeder` را پس از migration اجرا و اولین مدیر را با یک رویه عملیاتی امن و اعتبارنامه منحصربه‌فرد ایجاد کنید؛ LocalAdminSeeder برای تولید نیست.

## نقش‌ها و ورود

فقط نقش‌های `ADMIN`، `TEACHER` و `STUDENT` وجود دارند. ثبت‌نام عمومی وجود ندارد. ورود با ایمیل یا موبایل ممکن است. موبایل با قالب canonical `+989XXXXXXXXX` ذخیره می‌شود؛ در نتیجه `0912...`، `98912...` و `+98912...` معادل‌اند.

## آزمون

```powershell
php artisan test
```

## بارگذاری ویدئو در محیط محلی (فاز ۴)

ویدئو به‌صورت قطعه‌ای و قابل ادامه در دیسک خصوصی `video_local` بارگذاری می‌شود. تنظیمات زیر در `.env` قابل تغییر است؛ اندازهٔ chunk باید از محدودیت کل فایل بسیار کوچک‌تر بماند:

```dotenv
VIDEO_STORAGE_DRIVER=local
VIDEO_MAX_UPLOAD_MB=10240
VIDEO_UPLOAD_CHUNK_MB=5
VIDEO_UPLOAD_SESSION_TTL_HOURS=24
```

در `php.ini` مورد استفادهٔ XAMPP، `upload_max_filesize` و `post_max_size` را کمی بزرگ‌تر از اندازهٔ chunk (برای نمونه `8M` برای chunk پنج مگابایتی) و `max_execution_time` را متناسب (برای نمونه `120`) قرار دهید و Apache را restart کنید. سقف PHP لازم نیست برابر کل ویدئو باشد، چون هر درخواست فقط یک chunk را حمل می‌کند.

برای آزمایش از موبایل روی همان شبکهٔ امن محلی، فایروال را فقط برای شبکهٔ Private مجاز کنید و اجرا کنید:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

سپس با IP محلی رایانه و پورت ۸۰۰۰ وارد حساب مدیر/مدرس شوید، از صفحهٔ ویرایش درس ویدئو انتخاب کنید، حین بارگذاری صفحه را reload کنید، همان فایل را دوباره انتخاب و ادامهٔ آپلود را بررسی کنید. پس از آزمایش سرور را متوقف و دسترسی فایروال را جمع کنید؛ این روش برای انتشار عمومی نیست.

آپلودهای نیمه‌تمام منقضی با فرمان زیر پاک می‌شوند. در cPanel آینده این فرمان باید با Cron دوره‌ای اجرا شود:

```powershell
php artisan video:cleanup-uploads
```

سیاست تاریخ/زمان، ذخیره ویدئو و محدودیت‌های cPanel در [docs/architecture.md](docs/architecture.md) ثبت شده است.

## داده نمایشی فاز ۲

برای ایجاد چند دسته‌بندی و دوره نمونه در محیط محلی، بدون ساختن یا بازنویسی کاربران، اجرا کنید:

```powershell
php artisan db:seed --class=PhaseTwoDemoSeeder
```

اگر مدرس فعال موجود باشد، دوره‌های نمونه میان همان حساب‌های مدرس توزیع می‌شوند. این seeder در محیط غیر `local` اجرا نمی‌شود.

برای افزودن درس‌های متنی و ثبت‌نام نمونه با استفاده از مدیران، مدرسان، هنرجویان و دوره‌های موجود:

```powershell
php artisan db:seed --class=PhaseThreeDemoSeeder
```

این seeder فقط در محیط `local` اجرا می‌شود، کاربر یا فایل باینری نمی‌سازد و رمزها را تغییر نمی‌دهد.
