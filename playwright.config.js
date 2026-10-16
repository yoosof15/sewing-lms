import { defineConfig, devices } from '@playwright/test'
import { resolve } from 'node:path'
Object.assign(process.env,{APP_ENV:'e2e',APP_DEBUG:'false',APP_LOCALE:'fa',APP_KEY:'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',DB_CONNECTION:'sqlite',DB_DATABASE:resolve('storage/framework/testing/e2e.sqlite'),CACHE_STORE:'array',SESSION_DRIVER:'file',QUEUE_CONNECTION:'sync',E2E_PASSWORD:'Phase17-Test-Only!'})
export default defineConfig({
  testDir: './tests/e2e', timeout: 30_000, expect: { timeout: 7_000 }, retries: 1, workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { baseURL: 'http://127.0.0.1:8017', screenshot: 'only-on-failure', trace: 'on-first-retry', video: 'off', locale: 'fa-IR', timezoneId: 'Asia/Tehran' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
