# Phase 18 production-readiness report

Status: **conditionally ready for a rehearsed cPanel deployment**. No production deployment or external-provider activation was performed.

| Area | Status | Evidence / dependency |
|---|---|---|
| Application, frontend, auth, authorization, sessions | READY | Automated Laravel/E2E/build checks pass |
| Database and migrations | READY WITH PROVIDER INPUT | Local MySQL valid; host version/backup tooling pending |
| Queue and scheduler | READY WITH PROVIDER INPUT | Database queues, dedicated video queue and bounded cron documented |
| Mail | BLOCKED | Real provider/domain authentication not selected |
| Video storage | BLOCKED | External adapter/provider intentionally not implemented |
| Video processor | READY WITH PROVIDER INPUT | Local FFmpeg real pipeline passes; production worker undecided |
| Backups and monitoring | READY WITH PROVIDER INPUT | Synthetic restore passes; provider automation/alerts pending |
| Security | READY WITH PROVIDER INPUT | Controls/tests pass; production TLS/WAF/host configuration pending |
| E2E and deployment rehearsal | READY | Isolated suites and release allowlist rehearsal pass |
| Rollback | READY WITH PROVIDER INPUT | Procedure exists; final host release-switch mechanism pending |

## Verified locally

- Laravel/PHP tests, Vite production build, Chromium E2E, migration status, Pint, and Git diff checks pass.
- `app:preflight` is read-only, emits PASS/WARN/FAIL, redacts secrets, validates PHP/extensions, key/environment/HTTPS/cookies, writable paths, build manifest, database/migrations/tables, queue/session/log drivers, video adapters/tools, and free disk.
- Release staging is allowlisted and rejects environment files, databases, logs, tests, runtime uploads, and video binaries.
- Synthetic SQLite and private-file backup/restore rehearsals passed with content/checksum validation and cleanup.
- FFmpeg and FFprobe are installed locally; a generated tiny MP4 was processed and inspected through the configured tooling.
- The real model pipeline progressed STORED → PROCESSING (audited) → READY, was published, and returned full protected playback plus byte-accurate `206 Content-Range` output. A real browser media-element test was not added because CI/production FFmpeg availability is not guaranteed; the tagged browser smoke remains deterministic and backend range authorization is exercised with real media.
- Database queue tables exist, no failed jobs were present at review, video jobs use a dedicated configurable queue, and bounded cPanel workers are documented.
- Scheduler inventory, repeated execution, expired-session dry run/pruning, logging rotation, security headers, cookie settings, rate limits, dependency audits, load sanity, storage estimation, smoke checks, and rollback steps were reviewed or exercised.

## Blockers before real production traffic

1. Implement and test the chosen external `VideoStorage` adapter. The current adapter deliberately supports local storage only.
2. Decide whether processing runs on cPanel, a separate worker/VPS, or an external service; FFmpeg availability on production remains unknown.
3. Obtain real cPanel paths, PHP binary, MySQL/mail/storage credentials, TLS/domain configuration, cron capabilities, quota, backup provider, and retention approval.
4. Run `app:preflight --production`, backup/restore rehearsal, release rehearsal, smoke/E2E checks, and a representative video test in a production-like staging account.
5. Review Composer/npm audit findings at release time and explicitly accept, upgrade, or mitigate each finding.

## Known limitations

- The load check is a bounded single-machine HTTP sanity test, not a capacity benchmark or production SLA.
- The measured local `artisan serve` run completed 100/100 `/up` requests at concurrency 20 with zero failures, p50 5.445 s and p95 5.915 s. The single-process development server dominated these times; they are retained only as a regression sanity observation.
- The synthetic backup proof demonstrates procedure and integrity checks; it does not validate the host's MySQL dump tool, encryption, off-host transfer, or provider restore controls.
- Strict CSP remains deferred until every Inertia/Vite/browser flow is nonce/hash-tested. Existing `nosniff`, frame, referrer, and permissions headers remain enabled.
- cPanel daemon availability, outbound networking, malware scanning, WAF, and provider snapshot guarantees cannot be proven locally.

## Exact remaining inputs

**Application host/VPS:** cPanel home/release paths, PHP CLI path and enabled extensions, MySQL/MariaDB version, cron/process/time/memory/upload limits, disk quota/alerts, backup export/restore capability, symlink policy, outbound connectivity and whether a persistent or bounded worker is allowed.

**Video provider:** provider/product, endpoint/region, S3/FTP/API protocol, scoped credential method, private/signed delivery support, max object/multipart limits, egress/capacity limits, versioning/lifecycle/retention, backup/export guarantees, and whether processing runs there or on a separate worker.

**Domain/DNS:** final hostname, DNS account owner, record type/target, TTL/cutover window, TLS certificate/renewal owner, canonical redirect and maintenance-page strategy.

**Mail:** provider and transport, host/port/encryption or API method, sender address/name/domain, SPF/DKIM/DMARC ownership, credential provisioning, sending limits and bounce handling.
