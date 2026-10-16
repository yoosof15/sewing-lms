import { test, expect } from '@playwright/test'
import { login, watchRuntime } from './helpers.js'

test('@smoke health, login and protected dashboard are operational', async ({ page, request }) => {
  const clean = watchRuntime(page)
  const health = await request.get('/up')
  expect(health.ok()).toBeTruthy()
  await login(page, 'admin@example.test')
  await expect(page).toHaveURL(/\/admin$/)
  await expect(page.getByRole('heading', { name: /داشبورد/ })).toBeVisible()
  clean()
})
