# سیاست‌های معماری

## تاریخ و زمان

تمام timestampهای پایگاه داده به‌شکل استاندارد میلادی ذخیره می‌شوند. منطقه زمانی کسب‌وکار و نمایش `Asia/Tehran` است. نمایش جلالی در صورت نیاز در فاز بعدی و فقط در لایه نمایش افزوده می‌شود؛ تاریخ جلالی در پایگاه داده ذخیره نخواهد شد.

## مرز ذخیره‌سازی ویدئو

میزبان برنامه فقط مسئول برنامه، احراز هویت، پایگاه داده و فراداده است. ویدئوها در محیط تولید باید روی میزبان دانلود/ذخیره‌سازی جداگانه باشند. طراحی آینده باید از Laravel Filesystem و درایورهای قابل جایگزینی برای دیسک محلی توسعه، میزبان دانلود و S3/object storage استفاده کند. هیچ کد آینده‌ای نباید ماندگاری فایل ویدئو روی میزبان برنامه یا وجود FFmpeg در تولید را فرض کند.

## محدودیت میزبانی

هدف تولید cPanel اشتراکی/مدیریت‌شده است. Redis، Docker، Supervisor، دسترسی root و queue daemon دائمی پیش‌نیاز نیستند. cache، session و queue پایگاه‌داده‌ای حفظ شده‌اند و اجرای زمان‌بندی/صف در تولید باید با امکانات cron میزبان سازگار شود.

## کاتالوگ آموزشی (فاز ۲)

- `CourseCategory` دسته‌بندی اختیاری دوره را نگه می‌دارد. دسته‌بندی و دوره هر دو Soft Delete دارند؛ رابط این فاز حذف ارائه نمی‌کند و غیرفعال‌سازی روش عملیاتی معمول است.
- `Course` وضعیت رشته‌ای و indexشده دارد که در PHP با `CourseStatus` به `DRAFT`، `PUBLISHED` و `INACTIVE` محدود می‌شود. انتشار، `published_at` میلادی را ثبت می‌کند و خروج از وضعیت انتشار آن را پاک می‌کند.
- دوره و مدرس رابطه چندبه‌چند از طریق `course_teacher` دارند. کلید اصلی مرکب مانع انتساب تکراری می‌شود و اعتبارسنجی فقط کاربر فعال دارای نقش `TEACHER` را می‌پذیرد.
- slug فقط هنگام ایجاد، در سرور تولید می‌شود و با تغییر عنوان عوض نمی‌شود. `Str::slug` برای متن قابل تبدیل استفاده می‌شود؛ اگر خروجی خالی باشد، fallback غیرخالی مبتنی بر ULID ساخته می‌شود. برخوردها با پسوند عددی حل و unique constraint در پایگاه داده نیز اعمال می‌شود.
- تصویر جلد یک asset کوچک برنامه است و روی دیسک `public` در `course-covers` ذخیره می‌شود. MIME واقعی، ابعاد و سقف ۲ مگابایت بررسی می‌شود؛ نام فایل را Laravel تولید می‌کند و پس از جایگزینی موفق فایل قبلی پاک می‌شود. این سیاست مستقل از مرز ذخیره‌سازی ویدئو است.
- مدیر مدیریت کامل کاتالوگ و انتساب مدرس را دارد. مدرس فقط فهرست و جزئیات دوره‌های منتسب به خود را می‌بیند. هنرجو در فاز ۲ هیچ دسترسی دوره‌ای ندارد. همه این قواعد با policy و middleware در سرور اعمال می‌شوند.

## ثبت‌نام و درس‌ها (فاز ۳)

- `CourseEnrollment` یک entity مستقل با وضعیت رشته‌ای `ACTIVE` یا `INACTIVE` است. کلید یکتای دوره/هنرجو تاریخچه را حفظ و ثبت‌نام تکراری را منع می‌کند. `starts_at` و `ends_at` اختیاری و شامل لحظه مرزی هستند.
- `StudentCourseAccess` تنها مرجع قانون دسترسی هنرجو است: نقش STUDENT، enrollment فعال و شروع‌شده/منقضی‌نشده، دوره `PUBLISHED` و حذف‌نشده همگی باید برقرار باشند. هر درخواست دوباره این قانون را بررسی می‌کند.
- `Lesson` مستقیماً زیر دوره قرار دارد، Soft Delete می‌شود و وضعیت `DRAFT`، `PUBLISHED` یا `INACTIVE` دارد. ورود به انتشار `published_at` را تنظیم و خروج از آن timestamp را پاک می‌کند. ترتیب در هر دوره یکتا و عملیات جابه‌جایی transaction-based است.
- هر درس `created_by` دارد. مدیر همه درس‌ها را مدیریت می‌کند؛ مدرس منتسب می‌تواند درس بسازد ولی فقط درس ساخته‌شده توسط خودش را ویرایش، جابه‌جا یا بایگانی می‌کند.
- ضمیمه‌های کوچک در دیسک خصوصی `local` ذخیره می‌شوند و فقط از route دانلود احرازشده و policy عبور می‌کنند. نام اصلی فقط metadata است و Laravel مسیر تصادفی می‌سازد. PDF، تصویر، اسناد رایج، متن و ZIP تا ۱۰ مگابایت پذیرفته می‌شوند؛ ویدئو و فایل اجرایی رد می‌شوند.
- فایل ضمیمه با ویدئوی آینده متفاوت است. ویدئو در فاز بعدی روی storage جداگانه قرار می‌گیرد و نباید از PHP/Laravel proxy شود.
- هنرجو فقط دوره‌های واقعاً قابل‌دسترسی و درس‌های `PUBLISHED` را می‌بیند. غیرفعال‌شدن enrollment یا دوره، انقضا و Soft Delete بلافاصله درخواست بعدی را مسدود می‌کند.

## زیرساخت بارگذاری ویدئو (فاز ۴)

- `VideoUploadSession` وضعیت نشست قطعه‌ای، offset قطعی سمت سرور، کلید موقت تصادفی و تاریخ انقضا را نگه می‌دارد. مرورگر فقط شناسهٔ عمومی نشست را نگه می‌دارد و پس از refresh، offset را دوباره از سرور می‌گیرد؛ انتخاب مجدد همان فایل لازم است.
- جریان آپلود `initiate → chunk → finalize` است. هر chunk با `Upload-Offset` ارسال و با قفل فایل و قفل ردیف پایگاه‌داده کنترل می‌شود؛ offset نادرست پاسخ conflict می‌گیرد و داده تکراری نوشته نمی‌شود. لغو و پاک‌سازی نشست منقضی نیز idempotent است.
- `LessonVideo` فقط metadata، کلید نسبی ذخیره‌سازی، ترتیب و وضعیت را ثبت می‌کند. وضعیت‌های ویدئو `UPLOADING`، `STORED`، `PROCESSING`، `READY` و `FAILED` هستند؛ این فاز پس از تأیید MIME واقعی و اندازه در وضعیت `STORED` متوقف می‌شود.
- قرارداد `VideoStorage` مرز ذخیره‌سازی است و پیاده‌سازی فعلی `LocalVideoStorage` از دیسک خصوصی `video_local` استفاده می‌کند. مسیر عمومی یا URL خام برای فایل ساخته نمی‌شود. جایگزینی آینده با download host یا object storage باید پشت همین قرارداد انجام شود و فایل دائمی روی application host باقی نماند.
- FFmpeg، تبدیل، thumbnail، HLS، player و دسترسی هنرجو بخشی از این فاز نیستند. پردازش آینده باید یک سرویس/کار مستقل و قابل جایگزینی باشد و وجود FFmpeg یا daemon دائمی روی cPanel را فرض نکند.
- ایجاد نشست و مدیریت ویدئو با policy درس کنترل می‌شود. نشست فقط برای سازنده‌اش قابل استفاده است و مجوز درس در هر درخواست دوباره بررسی می‌شود. مدیر می‌تواند همه را مدیریت کند؛ مدرس فقط ویدئوی درس ساخته‌شده توسط خودش در دورهٔ منتسب را.
- فایل‌های موقت منقضی با `php artisan video:cleanup-uploads` حذف و نشست‌ها `EXPIRED` می‌شوند. در تولید cPanel اجرای دوره‌ای این فرمان از طریق Cron لازم است.

## خط پردازش ویدئو (فاز ۵)

جریان آپلود:

`Phone → VideoUploadSession → VideoStorage source → LessonVideo STORED`

جریان پردازش:

`LessonVideo STORED → ProcessLessonVideo job → VideoProcessor → FFprobe → FFmpeg → processed VideoStorage asset → LessonVideo READY`

- `VideoProcessor` مرز کاربردی پردازش است. پیاده‌سازی فعلی `LocalFFmpegVideoProcessor` با آرگومان‌های جداگانهٔ Symfony Process اجرا می‌شود و ورودی کاربر را وارد shell command نمی‌کند. جایگزینی آن با remote worker یا سرویس خارجی نباید منطق Course/Lesson را تغییر دهد.
- FFprobe مرجع metadata است. مدت، ابعاد، fps، codec و container از فایل واقعی خوانده می‌شوند. خروجی H.264/AAC MP4 با faststart، CRF 22 و preset medium است؛ نسبت تصویر حفظ می‌شود، تصویر بزرگ نمی‌شود و fps فقط در صورت بیشتر بودن از سقف کاهش می‌یابد. autorotation پیش‌فرض FFmpeg حفظ می‌شود و ابعاد portrait با جهت مناسب محدود می‌شوند.
- source و processed دو asset مجزا هستند. پردازش روی workspace خصوصی انجام و خروجی فقط پس از بررسی وجود، اندازه، stream، codec، مدت و ابعاد ذخیره می‌شود. شکست، خروجی ناقص را پاک و source را حفظ می‌کند.
- job با unique job، overlap lock و claim تراکنشی از پردازش هم‌زمان جلوگیری می‌کند. گذار مجاز شروع فقط `STORED/FAILED → PROCESSING` است؛ موفقیت به `READY` و شکست به `FAILED` می‌رود. retry وب فقط برای FAILED، تا سقف پیکربندی‌شده و با policy موجود مجاز است.
- با `VIDEO_KEEP_ORIGINAL=false` حذف source فقط پس از ذخیره، validation و ثبت موفق processed asset انجام می‌شود. خطای حذف source وضعیت READY یا processed asset را خراب نمی‌کند و برای بازیابی عملیاتی log می‌شود.
- حالت processor غیرفعال، آپلود را متوقف نمی‌کند و ویدئو را صادقانه در `STORED` نگه می‌دارد. فرمان `video:dispatch-pending` این موارد را پس از فعال‌شدن processor بازیابی می‌کند.
- در cPanel سه مسیر تولید معتبر می‌ماند: FFmpeg روی همان host، worker/VPS جداگانه، یا سرویس پردازش خارجی. هیچ daemon دائمی، Redis یا دسترسی root پیش‌فرض نیست.

خروجی فاز ۵ در مرز `READY` پایان می‌یابد؛ delivery و دسترسی هنرجو در لایهٔ مستقل فاز ۶ قرار دارند.

## پخش محافظت‌شده و انتشار ویدئو (فاز ۶)

- `VideoStatus` فقط چرخه پردازش را نشان می‌دهد. `VideoPublicationStatus` با مقادیر `DRAFT`، `PUBLISHED` و `INACTIVE` چرخه انتشار را مستقل نگه می‌دارد. مقدار پیش‌فرض همیشه DRAFT است و فقط asset واقعاً READY و موجود قابل انتشار است.
- `VideoPlaybackAccess` تصمیم واحد authorization است. هنرجو باید نقش STUDENT، ثبت‌نام جاری، Course و Lesson منتشرشده و حذف‌نشده، و Video حذف‌نشده با وضعیت `READY + PUBLISHED` و processed asset موجود داشته باشد. این تصمیم در هر درخواست stream و Range دوباره اجرا می‌شود.
- صفحه هنرجو فقط descriptorهای امن `id/title/stream_url` را با ترتیب `sort_order` دریافت می‌کند. source key، processed key، مسیر موقت و مسیر فیزیکی هرگز serialize نمی‌شوند. مسیر stream nested بودن Course/Lesson/Video را نیز بررسی می‌کند.
- `VideoDelivery` مرز انتقال است. `LocalProtectedVideoDelivery` فعلی stream خصوصی و seekable با پاسخ‌های 200/206/416، `Accept-Ranges`، طول و محدوده صحیح، MIME واقعی، inline disposition و cache خصوصی ارائه می‌دهد. خواندن فایل در chunkهای یک‌مگابایتی انجام می‌شود و کل ویدئو وارد حافظه PHP نمی‌شود.
- Admin و مدرس مالک مجاز می‌توانند processed asset مربوط به READY را بدون نیاز به publication preview کنند. هنرجو از مسیر preview مدیریتی رد می‌شود. نبود asset فقط log و پاسخ کنترل‌شده ایجاد می‌کند و GET وضعیت دامنه را به‌طور پنهانی تغییر نمی‌دهد.
- source حتی با `VIDEO_KEEP_ORIGINAL=true` خارج از مسیر playback باقی می‌ماند. دکمه دانلود نمایش داده نمی‌شود، ولی کنترل‌های مرورگر DRM نیستند و وعدهٔ جلوگیری قطعی از دانلود/ضبط داده نمی‌شود.
- PHP streaming فقط adapter توسعه محلی است. adapter آینده می‌تواند پس از authorization یک URL موقت provider/CDN بسازد. قابلیت provider در فاز ۹ بررسی خواهد شد و تا آن زمان signed URL، secure_link یا S3 فرض نمی‌شود.
- HLS فعلاً به تعویق افتاده چون خروجی موجود یک MP4 بهینه با faststart است و Range برای آزمون پخش محافظت‌شده و seek کافی است. تصمیم HLS پس از شناخت storage تولید و نیاز واقعی adaptive bitrate گرفته می‌شود.

## پیشرفت، واترمارک و نشست فعال (فاز ۷)

- جدول `video_progress` حقیقت پیشرفت شخصی ویدئو را با کلید یکتای user/video نگه می‌دارد. position و watched seconds عدد صحیح‌اند و duration معتبر سرور مرجع است. update با row lock انجام می‌شود، `completed_at` برگشت‌ناپذیر است و heartbeatهای دیررس آن را پاک نمی‌کنند.
- heartbeat مرورگر watched time را تعیین نمی‌کند. اعتبار هر درخواست حداکثر فاصلهٔ position رو به جلو و سقف زمان دیواری × نرخ مجاز به‌علاوه تلورانس است. نخستین heartbeat اعتبار نمی‌دهد؛ پرش به انتها تکمیل ایجاد نمی‌کند. position بزرگ‌تر از duration clamp و مقدار منفی رد می‌شود.
- completion threshold ویژگی Course با بازه ۱ تا ۱۰۰ است. Video completion از watched/duration، Lesson completion از همه ویدئوهای لازم فعلی، و Course progress از درس‌های PUBLISHED فعلی مشتق می‌شود. فقط تکمیل دستی درس بدون ویدئوی لازم در `lesson_manual_completions` ذخیره می‌شود؛ این رکورد اگر بعداً ویدئو منتشر شود قوانین جدید را دور نمی‌زند.
- resume فقط از progress همان هنرجو می‌آید. ویدئوی کامل که در انتها متوقف شده برای rewatch از صفر شروع می‌شود. Dashboard و Course page درصد و state واقعی را نمایش می‌دهند.
- واترمارک server-authored شامل نام و موبایل ماسک‌شده است و با CSS/Vue جابه‌جا می‌شود؛ ورودی هویتی از client پذیرفته نمی‌شود. overlay سبک است، داخل فایل encode نمی‌شود و DRM محسوب نمی‌شود. fullscreen بومی برخی platformها ممکن است overlay را حذف کند.
- حفاظت هم‌زمانی در سطح «نشست فعال Laravel» است، نه fingerprint سخت‌افزار. جدول موجود `sessions` با lifetime خود Laravel مرجع است. login بیش از حد رد می‌شود، ردیف منقضی شمرده نمی‌شود، نشست جاری از حذف تصادفی محافظت می‌شود و حذف ردیف نشست درخواست محافظت‌شده بعدی را unauthenticated می‌کند.
- token مدیریت نشست HMAC یک‌طرفه از session id است. UI نه id خام، نه payload و نه IP کامل را دریافت می‌کند. کاربر فقط نشست خودش و Admin نشست کاربران را مدیریت می‌کند؛ همه endpointها ownership/role را دوباره بررسی می‌کنند.
## مرز عملیاتی، Audit و چرخه بایگانی (فاز ۸)

- `AuditService` مرز واحد ثبت رویداد است و پیش از persistence کلیدهای حساس را حذف می‌کند. شکست audit در log فنی ثبت می‌شود و عملیات اصلی را خراب نمی‌کند؛ بنابراین audit تضمین تراکنش اتمی با mutation دامنه نیست.
- `AuditLog` append-only است و global UI آن فقط زیر middleware نقش Admin قرار دارد. heartbeat، Range، page view و progress audit نمی‌شوند.
- Course، Lesson، LessonVideo و CourseCategory از SoftDeletes استفاده می‌کنند؛ LessonAttachment نیز با migration افزایشی SoftDeletes گرفته است. archive بلافاصله scopeهای عادی و دسترسی Student را می‌بندد و فایل فیزیکی را نگه می‌دارد.
- restore فرزند تنها وقتی مجاز است که والد فعال باشد. بازیابی Course فرزندان را خودکار restore نمی‌کند؛ این رفتار از بازگشت ناخواسته محتوا جلوگیری می‌کند.
- purge ویدیو از مسیر job قابل‌تکرار `PurgeLessonVideo` و فقط پشت قرارداد `VideoStorage` انجام می‌شود. رکورد تا حذف موفق source و processed asset باقی می‌ماند. فایل از قبل مفقود، موفقیت idempotent محسوب می‌شود.
- Course دارای Enrollment یا سابقه یادگیری از UI به‌طور دائمی حذف نمی‌شود و باید بایگانی بماند. Enrollment history با status حفظ می‌شود و User غیرفعال می‌شود، نه حذف.
- `video:verify-assets` تطابق referenceهای DB با storage و READY بدون processed asset را فقط گزارش می‌کند و فایل ناشناخته‌ای را حذف نمی‌کند.
- production بر database queue و cron سازگار با cPanel تکیه دارد و Redis/Supervisor را فرض نمی‌کند. Phase 9 باید adapter خارجی را پشت `VideoStorage`/`VideoDelivery` و processor جایگزین‌پذیر اضافه کند.
- تنظیمات production شامل HTTPS، secure cookie، `APP_DEBUG=false` و عدم نمایش stderr/path/secret است. CSP سخت تا تست سازگاری کامل deferred است.

## Curriculum and centralized access

`Course -> CourseSection (optional) -> Lesson` defines display order. Unsectioned lessons appear first, followed by section order and the existing lesson `sort_order`. Lessons use a many-to-many self-relation through `lesson_prerequisites`; graph traversal rejects cycles before persistence.

Student access flows through `StudentCourseAccess -> StudentLessonAccess -> schedule -> prerequisites -> sequential rule -> Lesson`. `LessonPolicy` and `VideoPlaybackAccess` reuse this boundary, protecting lesson pages, video, progress, attachments, and manual completion. Completion remains owned and batch-loaded by `LearningProgressService`.

## Internal notification flow

Domain mutation -> `NotificationEvents` -> queued recipient resolution -> Laravel `Notification` -> database channel. Course recipients are processed in chunks and every recipient/event pair has a deterministic UUID, making queue and cron retries idempotent. A future channel layer may fan the same event out to database + email + SMS + push without placing provider logic in controllers.

## Private lesson Q&A

`Student -> StudentLessonAccess -> LessonQuestion -> linear LessonQuestionReply -> NotificationEvents -> Teacher/Student notification`. Threads belong to one student and remain private. Assigned course teachers may answer regardless of lesson ownership; an unassigned teacher and other students cannot view the thread. Images use private local storage and an authorized download endpoint.

## User and profile separation (Phase 15)

`users` remains authoritative for identity, authentication, role-independent name, normalized mobile login, email, password, and account status. The one-to-one `user_profiles` table stores only optional LMS presentation fields: avatar path, bio, Teacher specialty, and Teacher experience. There are no separate Student/Teacher identity tables and no unnecessary demographic fields.

Visibility is role-specific. A User receives explicit self-profile props; a Student receives only public Teacher presentation fields inside an authorized Course; Admin detail receives explicit management and operational summaries. Raw models, avatar storage paths, password/remember tokens, raw session IDs, and session payloads never cross the Inertia boundary. Mobile remains an Admin-managed login identifier in Phase 15.

## Bulk Student import boundary (Phase 16)

The import pipeline is: temporary spreadsheet → bounded parser → Iranian mobile normalizer → validator/duplicate classifier → persisted preview → explicit Admin confirmation → chunked row transactions → User/Enrollment domain → persisted result. Preview has zero User or Enrollment mutations. Existing mobiles, emails, Courses, and roles are batch-loaded before classification to avoid per-row discovery queries.

CSV/XLSX source files are never copied into public or persistent application storage. Only operational normalized row data and controlled errors are retained. Unknown columns are ignored and cannot be mass-assigned; roles, activation state, creator IDs, and passwords are always decided server-side. Import and result routes are protected by the existing Admin middleware. Plaintext passwords, credentials, secrets, upload binaries, and runtime paths are excluded from result exports and Audit metadata.

Student detail delegates progress to `LearningReportService`/`LearningProgressService`; it does not create another progress truth. Session summaries and revocation delegate to `ActiveSessionService`. Existing Admin User update logic remains the only role/status/mobile mutation path and retains last-active-Admin protection.

## System settings boundary (Phase 14)

The resolution path is `config/.env hard maximum or fallback -> SystemSettings definition -> validated system_settings override -> effective domain value`. Definitions own the type, group, default, validation rules, Persian label, description, and bounds. One cached override map prevents a query per key; writes and resets invalidate it immediately.

Controllers and domain services consume typed accessors instead of querying `system_settings` directly. New Course defaults, watermark props, future-login session checks, upload validation, and notification opt-in fallback all pass through this boundary. Per-Course values and explicit per-User preferences remain separate sources of truth.

For video, Assignment, and Q&A uploads, an Admin business limit can only reduce the configured infrastructure limit: `effective = min(hard, business)`. Settings contain product values only. Secrets, credentials, executable paths, server paths, codecs, processing commands, and encryption material are deliberately outside this model.

## Reporting boundaries (Phase 13)

Learning reports are derived, authorized views and do not own learning-progress truth. `LearningProgressService` defines the Course denominator and completion semantics; its batch API loads curriculum, video progress and manual completions in bounded bulk queries. `StudentLessonAccess` remains authoritative for scheduled, prerequisite and sequential locks. Assignment and Q&A aggregates come from their respective domains.

`LearningReportAccess` owns Admin/assigned-Teacher scope. `LearningReportService` owns composable filters, bulk aggregates, last-learning-activity derivation and presentation rows. Controllers only authorize, validate filters and choose HTML or Course-specific CSV output. No percentage is persisted.

The operational Course average uses active Enrollments whose start/end access window includes the current time. Last learning activity is the latest of video heartbeat, manual Lesson completion and Assignment submission; login, notifications, Q&A and administrative edits are intentionally excluded. Exports use the same service, authorization and filters as the page and always mask mobile numbers.
