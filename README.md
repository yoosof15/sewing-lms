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

سیاست تاریخ/زمان، ذخیره ویدئو و محدودیت‌های cPanel در [docs/architecture.md](docs/architecture.md) ثبت شده است.

## داده نمایشی فاز ۲

برای ایجاد چند دسته‌بندی و دوره نمونه در محیط محلی، بدون ساختن یا بازنویسی کاربران، اجرا کنید:

```powershell
php artisan db:seed --class=PhaseTwoDemoSeeder
```

اگر مدرس فعال موجود باشد، دوره‌های نمونه میان همان حساب‌های مدرس توزیع می‌شوند. این seeder در محیط غیر `local` اجرا نمی‌شود.
