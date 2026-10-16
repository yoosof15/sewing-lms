# Production deployment runbook

This runbook targets cPanel/shared hosting. It does not assume root access, Redis, a permanent worker, FFmpeg, or permanent video storage on the application host.

## Infrastructure information required

- Application host: cPanel account/home path, PHP 8.2+ CLI path/extensions, MySQL/MariaDB version and limits, cron minimum interval, process/runtime limits, disk quota/alerts, TLS termination and backup/restore controls.
- Domain/DNS: production hostname, DNS owner, required A/CNAME records, TLS certificate owner, redirect/canonical-host policy, and maintenance cutover window.
- Mail: SMTP/API provider, host/port/encryption, sender domain/address, SPF/DKIM/DMARC owner, credentials and sending limits. **TBD after provider selection.**
- Video: storage/download provider, region/endpoint, capacity/egress limits, credential scope, private delivery/signing model, versioning/backup, lifecycle rules and migration plan. **TBD after provider selection.**
- Processing: confirm cPanel FFmpeg/FFprobe paths and runtime limits, or select a worker/VPS/external processor. **TBD after provider selection.**

## Required environment

Copy `.env.example` to the server outside Git and set unique production values. At minimum set `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`, a server-generated `APP_KEY`, MySQL credentials, mail credentials, `SESSION_SECURE_COOKIE=true`, database cache/session/queue drivers, and provider-specific storage credentials. Never paste secrets into System Settings, logs, source control, tickets, or command output.

`VIDEO_STORAGE_DRIVER=local` is suitable only for local development or a bounded transition. Production videos must move behind the `VideoStorage` abstraction to separate storage/download hosting. `VIDEO_PROCESSOR_DRIVER=ffmpeg` is valid only when the production worker has both FFmpeg and FFprobe; otherwise disable dispatch until a remote processor implementation exists. Keep `VIDEO_PROCESSING_QUEUE=video` isolated from short notification jobs.

## Release and first deployment

1. Back up the production database and private/public application files; verify the newest backup can be read.
2. Build a clean staging directory from a trusted checkout: `powershell -File scripts/build-release.ps1 -Destination C:\release\sewing-lms-YYYYMMDD`.
3. Upload/switch the release, preserving server `.env`, `storage`, and external video assets. DocumentRoot must point to `public`.
4. Run `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` if dependencies were not staged.
5. Run `php artisan app:preflight --production`. Do not proceed on FAIL; review every WARN.
6. Enter maintenance mode only for the final database transition: `php artisan down --retry=60`.
7. Run `php artisan migrate --force`, then `php artisan optimize` and `php artisan storage:link` (public branding/covers only).
8. Run `php artisan app:smoke --write-storage`, `php artisan video:verify-assets`, and `php artisan schedule:list`.
9. Leave maintenance mode: `php artisan up`. Verify HTTPS, login, Admin dashboard, one Student Course, private attachment denial, and a byte-range video request where a READY/PUBLISHED asset exists.

Create the first administrator interactively with `php artisan admin:create`. The command validates unique email/mobile, hides password input, and never writes plaintext credentials.

## Cron and queue on cPanel

Run Laravel's scheduler every minute:

```cron
* * * * * cd /home/ACCOUNT/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

When a permanent worker is unavailable, add bounded workers. Use a frequent short queue for normal jobs and a separate, less frequent video worker only where processing is supported:

```cron
* * * * * cd /home/ACCOUNT/app && /usr/local/bin/php artisan queue:work --queue=default --stop-when-empty --tries=3 --timeout=120 >> storage/logs/queue-cron.log 2>&1
*/5 * * * * cd /home/ACCOUNT/app && /usr/local/bin/php artisan queue:work --queue=video --stop-when-empty --tries=1 --timeout=7200 >> storage/logs/video-cron.log 2>&1
```

Ensure `DB_QUEUE_RETRY_AFTER` exceeds the video timeout. Inspect `queue:failed`; retry only after fixing the cause. Repeatedly failed processing preserves source metadata and must be reviewed rather than blindly retried.

Queue inventory: `ProcessLessonVideo` performs long transcoding on the configurable `video` queue; `DispatchInAppNotification` handles normal notification fanout; `PurgeLessonVideo` handles deferred storage cleanup. Upload cleanup and asset verification are scheduled commands rather than queued jobs. The Phase 18 isolated test deliberately records a controlled failed job, confirms `queue:failed`, retries it, and then forgets only the test failure.

The scheduler dispatches due notifications each minute, cleans stale video uploads hourly, prunes expired database sessions daily, and verifies video references daily. Running `schedule:run` twice is safe: notification claims and cleanup commands are idempotent, and overlap locks prevent concurrent scheduled copies.

## Backup and restore

For MySQL/MariaDB, use a protected option file or an interactive credential mechanism—never a password in shell history. A representative logical dump is:

```bash
mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 DATABASE > backup.sql
```

Confirm exit code and non-empty output, restore into a newly created quarantine database, compare migrations and key table counts, and run application smoke checks before accepting the backup. Database and private-file restore points must be logically aligned; restoring metadata whose attachments are absent is incomplete.

- Database: daily provider backup plus an encrypted off-host logical MySQL dump. Retain 7 daily, 5 weekly, and 12 monthly copies.
- Private files: daily incremental backup of lesson attachments, Q&A images, assignment submissions, and avatars; encrypt off-host copies.
- Public files: back up branding/course images separately. The public symlink is reproducible and is not itself backup data.
- Videos: source and processed objects require provider versioning or an independent copy. The download host must never be the sole copy of irreplaceable source video.
- Logs/cache/sessions/temp chunks/build artifacts: rotate or regenerate; do not treat them as primary backup data.

Initial objectives (targets, not a provider-backed SLA): database and private-file RPO 24 hours plus a pre-release backup; irreplaceable original-video RPO near zero through provider versioning/secondary copy; application RTO 4 hours; large media RTO 24 hours. GitHub owns source history, the application-host backup owns DB/private files, the video provider owns primary media durability, and an independent secondary backup owns irreplaceable originals.

Practice the repository's isolated backup proof with `php scripts/backup-rehearsal.php`. Where FFmpeg is expected, exercise the configured processor with `php scripts/video-rehearsal.php`; it creates and removes only synthetic temporary media. For production restoration, restore into a new database and quarantine path, validate row/file counts and checksums, run `app:preflight --production` and `app:smoke`, then switch traffic. Never test restore by overwriting live data.

## Rollback

Keep the previous release directory, previous protected `.env` revision, storage-adapter configuration, and the pre-migration backup. For a code-only problem, enable maintenance mode, stop/restart bounded workers, point the release symlink/DocumentRoot back, run `php artisan optimize:clear`, rebuild caches with `php artisan optimize`, and run smoke checks. Code rollback is not database rollback. If a migration changed schema incompatibly, prefer a forward fix or restore the pre-release database into a new database and switch credentials; never blindly run `migrate:rollback`, `migrate:fresh`, or `db:wipe` after production writes. Do not delete prior media during a release or storage migration; retain the old adapter/path until integrity and playback are verified. Re-enable the application only after checks pass.

## Monitoring and incident checks

Use daily logs (`LOG_STACK=daily`, `LOG_LEVEL=info`, `LOG_DAILY_DAYS=14`), hosting disk alerts, failed-job counts, HTTP 5xx alerts, backup-age alerts, and `video:verify-assets`. Logs may contain operational IDs but must not contain passwords, tokens, cookies, session payloads, or storage credentials. Rotate cron logs separately.

For capacity planning run `php artisan storage:estimate --students=1000 --monthly-uploads-gb=50 --months=12`. Budget database, private attachments, backups/provider versions, logs, and at least twice the largest expected source as transient processing space in addition to its video estimate.

Video formula: `source GiB = source hours × average source Mbps × 3600 / 8 / 1024`; calculate processed GiB the same way with processed bitrate, then multiply each tier by its retained copy/version count. Safest initial policy is keep originals indefinitely in versioned external storage plus an independent copy. Later options are retention for N days after verified processing or archival to cheaper storage; automatic original deletion is intentionally not enabled.

Existing expensive-route limits: login 5 attempts per identity/IP key, password reset 5/minute, upload initiation 20/minute, video retry 10/minute, playback progress 120/minute, session revocation 20/minute, password change 5/minute, questions 10/minute, replies 20/minute, and assignment submission/review 10/20 per minute. Chunk upload is intentionally governed by authenticated ownership, offsets, sizes and session expiry rather than an aggressive request limit.
