# Sewing LMS

## Curriculum access (Phase 9)

Courses may contain ordered sections; existing lessons remain valid without a section and appear before sectioned lessons. A course uses `FREE` or `SEQUENTIAL` navigation. Published lessons may have a Gregorian `available_at` timestamp and same-course prerequisites; self/cross-course links and dependency cycles are rejected.

`StudentLessonAccess` is the authoritative access boundary used by lesson pages, videos, progress, attachments, and manual completion. Decision priority is unavailable parent, schedule, prerequisites, then sequential order. Archived prerequisites remain unsatisfied until restored or explicitly removed. Current progress excludes future scheduled lessons, so its denominator expands when they unlock. `CurriculumDemoSeeder` is local-only and does not overwrite matching records.

## Internal notifications (Phase 10)

Laravel database notifications provide a private, paginated read/unread center for every role. Event categories are `COURSE_ACCESS`, `NEW_LESSON`, `NEW_VIDEO`, and `TEACHER_ASSIGNMENT`; access and assignment notices are mandatory, while lesson/video notices can be disabled. Delivery uses the database queue and deterministic event/user IDs for retry-safe deduplication.

Run `php artisan notifications:dispatch-due` from cPanel cron every minute to enqueue releases for due scheduled lessons, and run a short-lived database queue worker separately. The scheduler also registers this command every minute. Future mail/SMS/push channels can consume the same domain notification events without changing course or lesson business rules.

## Private Lesson Q&A (Phase 11)

Each accessible lesson supports private student threads with linear, immutable replies. States are `OPEN`, `ANSWERED`, `RESOLVED`, and `CLOSED`. Assigned teachers can support every lesson in their course regardless of lesson authorship; admins oversee all threads. A staff reply changes `OPEN` to `ANSWERED`; a student follow-up changes `RESOLVED` back to `ANSWERED`; `CLOSED` rejects replies.

Q&A reuses internal `LESSON_QA` notifications. Creation and replies are limited to 10/minute and 20/minute. Up to three private JPG/PNG/WebP images of 5 MB each may accompany a question or reply; downloads always pass Q&A authorization. Q&A is not a public forum or realtime chat.

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

## پردازش ویدئو (فاز ۵)

وجود ابزارها را بدون نصب خودکار بررسی کنید:

```powershell
ffmpeg -version
ffprobe -version
```

پردازش پس از پایان آپلود به صف دیتابیس فرستاده می‌شود و نباید داخل درخواست HTTP اجرا شود. برای توسعه worker را جداگانه اجرا کنید:

```powershell
php artisan queue:work --tries=1 --timeout=7200
php artisan queue:failed
```

ابزارهای بازیابی مدیر سیستم:

```powershell
php artisan video:process 123
php artisan video:process 123 --sync
php artisan video:dispatch-pending --limit=100
```

گزینه‌های اصلی `.env` عبارت‌اند از `VIDEO_PROCESSOR_DRIVER`، مسیرهای `FFMPEG_BINARY` و `FFPROBE_BINARY`، حداکثر ابعاد و fps، codecها، `VIDEO_CRF=22`، `VIDEO_PRESET=medium`، سیاست `VIDEO_KEEP_ORIGINAL`، سقف تلاش و timeout. با `VIDEO_PROCESSOR_DRIVER=null` آپلود فعال می‌ماند و ویدئو در وضعیت `STORED` منتظر پردازش آینده می‌ماند.

خروجی موقت به‌طور پیش‌فرض در `storage/app/private/video-processing` ساخته و پس از موفقیت یا شکست پاک می‌شود. هنگام پردازش باید علاوه بر فایل منبع، فضای کافی برای خروجی MP4 و سربار وجود داشته باشد. مقدار `DB_QUEUE_RETRY_AFTER` باید از `VIDEO_PROCESSING_TIMEOUT` بیشتر باشد؛ پیش‌فرض‌ها به‌ترتیب ۷۵۰۰ و ۷۲۰۰ ثانیه‌اند.

در cPanel، اگر FFmpeg موجود باشد Cron می‌تواند worker کوتاه‌عمر اجرا کند، برای نمونه `php artisan queue:work --stop-when-empty --tries=1 --timeout=7200`. اگر FFmpeg وجود ندارد، پردازشگر پشت قرارداد `VideoProcessor` باید در آینده با worker/VPS جداگانه یا سرویس خارجی جایگزین شود. اجرای FFmpeg طولانی در درخواست وب توصیه نمی‌شود.

## پخش محافظت‌شده ویدئو (فاز ۶)

وضعیت پردازش و انتشار مستقل‌اند. ویدئوی جدید حتی پس از `READY` شدن، به‌صورت `DRAFT` باقی می‌ماند تا مدیر یا مدرس مجاز آن را منتشر کند. هنرجو فقط ویدئوی `READY + PUBLISHED` را در درس منتشرشدهٔ یک دورهٔ منتشرشده و با ثبت‌نام فعال می‌بیند.

فایل source هرگز از مسیر پخش ارائه نمی‌شود. مرورگر فقط endpoint برنامه را می‌بیند و هر درخواست کامل یا Range دوباره در سرور authorize می‌شود. مسیر فیزیکی، storage key و URL مستقیم asset به Vue داده نمی‌شود. `controlsList="nodownload"` و جلوگیری از context menu فقط بازدارندهٔ UX هستند و امکان دانلود یا ضبط مرورگر را به‌طور مطلق حذف نمی‌کنند.

آزمایش دستی امنیت:

1. با هنرجوی ثبت‌نام‌شده وارد دوره و درس منتشرشده شوید و ویدئوی منتشرشده را پخش کنید.
2. URL درخواست stream را از Network کپی و در Incognito باز کنید؛ باید ورود درخواست شود.
3. همان URL را با هنرجوی فاقد ثبت‌نام باز کنید؛ باید رد شود.
4. ثبت‌نام هنرجوی اول را غیرفعال و دوباره seek کنید؛ درخواست Range بعدی باید رد شود.
5. در URL و source صفحه نباید `storage/app`، `video_local` یا storage key دیده شود.

برای آزمایش Range با یک session احراز‌شده، در DevTools درخواست stream را به‌صورت PowerShell/curl کپی و header زیر را اضافه کنید:

```powershell
curl.exe -i -H "Range: bytes=0-1023" -b "laravel_session=SESSION_VALUE" "http://127.0.0.1:8000/student/courses/COURSE/lessons/LESSON/videos/VIDEO/stream"
```

پاسخ باید `206 Partial Content`، مقدار `Accept-Ranges: bytes`، طول `1024` و `Content-Range: bytes 0-1023/TOTAL` داشته باشد. پخش محلی از PHP برای توسعه و آزمون کنترل‌شده مناسب است، نه انتقال چندگیگابایتی production. در تولید، برنامه باید authorization را انجام دهد و delivery از storage/CDN با دسترسی موقت صورت گیرد.

در تست موبایل روی LAN، پخش، pause، seek جلو/عقب، fullscreen، چرخش portrait/landscape و audio sync را بررسی کنید. HLS در این فاز عمداً اضافه نشده است؛ MP4 progressive و HTTP Range برای تثبیت authorization، UX و مرز delivery کافی است.

## پیشرفت یادگیری، واترمارک و نشست‌ها (فاز ۷)

`VideoProgress` برای هر هنرجو/ویدئو یک ردیف یکتا نگه می‌دارد. Player هنگام شروع و تقریباً هر ۱۲ ثانیه در زمان پخش، همچنین در pause، ended، seek و خروج از صفحه position را گزارش می‌کند. سرور به‌جای اعتماد به زمان ادعایی مرورگر، اعتبار مشاهده را از کمینهٔ حرکت رو به جلو و زمان سپری‌شده با سقف ۲× و سه ثانیه تلورانس محاسبه می‌کند. seek جلو position را تغییر می‌دهد ولی فاصلهٔ پرش را مشاهده‌شده حساب نمی‌کند؛ seek عقب نیز watched time را کم نمی‌کند.

حد تکمیل در هر Course با `completion_threshold_percent` و پیش‌فرض ۸۰٪ تنظیم می‌شود. تکمیل ویدئو فقط از `watched_seconds / duration_seconds` محاسبه می‌شود. درس دارای ویدئوی لازم زمانی کامل است که همه ویدئوهای `READY + PUBLISHED` آن کامل باشند. درس بدون ویدئوی لازم را هنرجو دستی کامل می‌کند. درصد دوره به‌شکل پویا از درس‌های PUBLISHED محاسبه می‌شود، بنابراین تغییر curriculum نتیجه را خودکار اصلاح می‌کند.

متغیرهای مرتبط:

```dotenv
VIDEO_PROGRESS_HEARTBEAT_SECONDS=12
VIDEO_PROGRESS_MAX_RATE=2
VIDEO_PROGRESS_CREDIT_TOLERANCE_SECONDS=3
VIDEO_WATERMARK_ENABLED=true
VIDEO_WATERMARK_MOVE_SECONDS=30
AUTH_MAX_ACTIVE_SESSIONS=2
```

واترمارک overlay شامل نام هنرجو و موبایل ماسک‌شده است و میان پنج موقعیت حرکت می‌کند. این قابلیت فقط بازدارندهٔ اشتراک‌گذاری ساده است، DRM نیست. در برخی مرورگرها fullscreen بومی خود عنصر `<video>` می‌تواند overlay بیرونی را پنهان کند؛ جلوگیری قطعی از ضبط یا screenshot ممکن نیست.

محدودیت login از جدول database session موجود و `SESSION_LIFETIME` استفاده می‌کند. مقدار صفر برای `AUTH_MAX_ACTIVE_SESSIONS` یعنی نامحدود؛ در حد ظرفیت، login جدید با پیام فارسی رد می‌شود. صفحه «نشست‌های فعال» browser/OS تقریبی، IP ماسک‌شده و آخرین فعالیت را نشان می‌دهد. کاربر نشست دیگر خودش را لغو می‌کند ولی نشست جاری محافظت شده است. مدیر از فهرست کاربران به نشست‌های هر کاربر دسترسی دارد. شناسه خام و payload نشست هرگز serialize نمی‌شوند.

تست دستی پیشنهادی: ۳۰ تا ۶۰ ثانیه ویدئو را ببینید، pause و refresh کنید و resume را بررسی کنید؛ سپس seek بزرگی انجام دهید و مطمئن شوید درصد ناگهان کامل نمی‌شود. برای نشست‌ها با حد ۲ در مرورگر A و B وارد شوید، ورود C باید رد شود؛ B را از A لغو کنید و ورود C را دوباره امتحان کنید.

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
## عملیات و سخت‌سازی (فاز ۸)

رویدادهای مهم مدیریتی/آموزشی در Audit Log ثبت می‌شوند؛ password، token، session payload، cookie و storage key وارد metadata نمی‌شوند. حذف عادی Course/Lesson/Video/Attachment به‌معنای بایگانی است. حذف دائمی فقط برای Admin، پس از بایگانی و با تأیید صریح مجاز است. دوره دارای سابقه ثبت‌نام یا یادگیری از UI purge نمی‌شود.

فرمان‌های عملیاتی:

```powershell
php artisan video:verify-assets
php artisan video:cleanup-uploads
php artisan video:dispatch-pending --limit=100
php artisan queue:failed
php artisan queue:retry ID
php artisan queue:forget ID
php artisan queue:work --stop-when-empty --tries=1 --timeout=7200
```

پاک‌سازی دائمی ویدیو با job دیتابیسی و از طریق قرارداد `VideoStorage` انجام می‌شود. عملیات idempotent است و شکست provider، metadata ویدیو را برای retry نگه می‌دارد. راهنمای کامل استقرار و backup در `docs/production-readiness.md` است.

endpointهای پرهزینه دارای محدودیت معقول‌اند: شروع upload برابر ۲۰ درخواست در دقیقه، retry پردازش ۱۰، heartbeat برابر ۱۲۰ و لغو نشست برابر ۲۰ درخواست در دقیقه. chunkهای ویدیو عمداً rate-limit تهاجمی ندارند.

هدرهای `nosniff`، `SAMEORIGIN`، Referrer Policy و Permissions Policy اعمال می‌شوند. CSP سخت تا زمان تست کامل Vite/Inertia به تعویق افتاده است.

## تکالیف و ارسال هنرجو (فاز ۱۲)

تکلیف به یک درس متصل است و می‌تواند پیش‌نویس، منتشرشده یا غیرفعال باشد. زمان دسترسی، مهلت، ارسال دیرهنگام، ارسال مجدد و سقف تلاش در سمت سرور کنترل می‌شوند. همه تلاش‌های قبلی حفظ می‌شوند و مدرس می‌تواند نتیجه بررسی و بازخورد را ثبت کند.

فایل‌های JPG، PNG، WebP، PDF و DOCX در فضای خصوصی ذخیره و فقط از endpoint مجاز دانلود می‌شوند. پیش‌فرض حداکثر ۵ فایل و ۱۰ مگابایت برای هر فایل است و در `config/assignments.php` قابل تنظیم است. برای داده نمایشی محلی اجرا کنید:

```powershell
php artisan db:seed --class=AssignmentDemoSeeder
```

فرمان `php artisan notifications:dispatch-due` اعلان تکالیفی را که زمان دسترسی آن‌ها رسیده نیز به‌صورت idempotent ارسال می‌کند.

چک دستی: ساخت و انتشار تکلیف، مشاهده در صفحه درس هنرجو، ارسال متن/فایل، بررسی در صندوق تکالیف، ثبت بازخورد، ارسال مجدد پس از درخواست اصلاح، کنترل دانلود فایل با کاربر دیگر و اجرای دوباره فرمان اعلان را بررسی کنید.

## User profiles and management UX (Phase 15)

Every authenticated role has a Persian RTL `My Profile` page for safe account fields, avatar, password change, and active-session visibility. Mobile remains the normalized login identifier and is Admin-managed; Phase 15 intentionally does not add a self-service mobile or OTP flow. Teacher profiles add plain-text specialty, bio, and experience fields. Students see only a Teacher's name, avatar, specialty, and short bio in Course context.

Admins have a scoped User detail page with account status, safe session descriptors, enrollment/progress summaries from the Phase 13 reporting services, Teacher Course workload, and Assignment/Q&A counts. The User index supports paginated name/email/normalized-Iranian-mobile search, role/status filters, and whitelisted sorting.

Avatar uploads accept MIME-verified PNG/JPEG/WebP images up to 2 MB and 3000×3000, use generated storage names, and delete the previous avatar only after a successful database update. User props are explicit arrays: password hashes, remember tokens, raw session identifiers/payloads, storage paths, and unnecessary private contact fields are not exposed.

## System settings and branding (Phase 14)

Admins manage product settings at `/admin/settings`. Supported keys are declared in `config/system_settings.php`; the browser cannot create arbitrary keys. `SystemSettings` resolves a validated database override first and otherwise uses the code/config default. Overrides are cached as one map and every update or group reset invalidates that cache immediately.

The page covers institute text and logo, defaults for new Courses, watermark behavior, future-login session policy, video/Assignment/Q&A business limits, Q&A availability, and notification defaults. Existing Course values and explicit User notification preferences remain authoritative. Turning Q&A off preserves readable history while preventing new Student questions and replies.

Upload controls use two layers: server/config values are hard safety maxima and Admin values are lower business limits. The effective value is always the smaller value. Raising a setting does not change PHP `upload_max_filesize`, `post_max_size`, Apache/Nginx limits, storage capacity, or external-host policy.

System Settings is not a secrets store. `APP_KEY`, database/mail/storage credentials, API keys, FFmpeg paths, filesystem paths, Git credentials, and similar infrastructure values remain in environment/server configuration and are never exposed by this UI.

## گزارش‌های پیشرفت و عملکرد (فاز ۱۳)

مدیر از منوی «گزارش‌ها» همه دوره‌ها و مدرس فقط دوره‌های تخصیص‌یافته را می‌بیند. گزارش دوره شامل میانگین پیشرفت ثبت‌نام‌های فعال و جاری، وضعیت هر هنرجو، درس‌های تکمیل‌شده، خلاصه تکلیف و پرسش، آخرین فعالیت آموزشی و CSV دارای UTF-8 BOM است. موبایل در UI و CSV ماسک می‌شود.

وضعیت‌ها مشتق‌شده‌اند: صفر درصد «شروع‌نشده»، بین صفر و صد «در حال پیشرفت» و صد درصد «تکمیل‌شده» است. درس آینده در denominator پیشرفت قرار نمی‌گیرد و در جزئیات با «هنوز در دسترس نیست» نمایش داده می‌شود. آخرین فعالیت فقط بیشینه زمان heartbeat ویدیو، تکمیل دستی درس و ارسال تکلیف است؛ login، اعلان و ویرایش مدیر فعالیت یادگیری محسوب نمی‌شود.

گزارش مالک حقیقت پیشرفت نیست. `LearningProgressService`، `StudentLessonAccess`، داده `video_progress` و دامنه‌های Assignment و Q&A منابع معتبرند. محاسبه چندهنرجویی با API دسته‌ای انجام می‌شود و جزئیات فهرست دوره صفحه‌بندی شده است. درصد ذخیره‌شده یا cache موازی ایجاد نشده است.

برای داده نمایشی سبک و local-only:

```powershell
php artisan db:seed --class=LearningReportDemoSeeder
```
