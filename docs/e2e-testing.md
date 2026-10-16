# Browser E2E testing

## Prerequisites

- PHP and Composer dependencies installed.
- Node dependencies installed with `npm.cmd install`.
- Chromium installed once with `npx.cmd playwright install chromium`.
- The SQLite PHP extension enabled.

## Commands

On Windows, run `npm.cmd run build` and then `npm.cmd run test:e2e`. For an interactive browser use `npm.cmd run test:e2e:headed`. A focused file can be run with `npm.cmd run test:e2e -- tests/e2e/student.spec.js`.

## Isolation and reset

`tests/e2e/run.mjs` recreates only `storage/framework/testing/e2e.sqlite`, supplies an E2E-only application key and clearly fake credentials, runs all migrations, and invokes `Database\\Seeders\\E2eDemoSeeder`. The seeder refuses to run outside `e2e` or `testing`. Local MySQL, `.env`, uploads, and production data are not read or modified.

The deterministic accounts use the `@example.test` domain. Their password comes from the runner's `E2E_PASSWORD` environment value and is never used outside this isolated database.

## Failures and debugging

Playwright records a screenshot on failure and a trace on the first retry. Generated output lives in ignored `test-results/` and `playwright-report/`. Inspect a trace with `npx.cmd playwright show-trace PATH`. Tests fail on unexpected `console.error`, page exceptions, and application HTTP 5xx responses. Expected authorization failures are asserted separately.

The video domain remains covered by authoritative Laravel range/progress tests. The E2E seeder intentionally creates no fake READY video or binary; a future browser playback fixture must be generated locally with FFmpeg and preserve the existing READY/PUBLISHED rules.
