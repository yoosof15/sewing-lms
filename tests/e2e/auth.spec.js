import { test, expect } from '@playwright/test'
import { login, watchRuntime } from './helpers.js'

test('invalid login and role dashboards', async ({ page }) => {
  const clean=watchRuntime(page)
  await page.goto('/login'); await page.getByLabel('ایمیل یا شماره موبایل').fill('nobody@example.test'); await page.getByLabel('رمز عبور').fill('wrong-value'); await page.getByRole('button',{name:'ورود'}).click()
  await expect(page.getByText(/اطلاعات ورود/)).toBeVisible()
  for (const [email,path] of [['admin@example.test','/admin'],['teacher@example.test','/teacher'],['student@example.test','/student']]) {
    await login(page,email); await expect(page).toHaveURL(new RegExp(`${path}$`)); await page.getByRole('button',{name:'خروج'}).click(); await expect(page).toHaveURL(/login/)
  }
  clean()
})

test('role protected URLs render a safe Persian 403', async ({ page }) => {
  await login(page,'student@example.test'); let response=await page.goto('/admin/users'); expect(response.status()).toBe(403); await expect(page.getByRole('heading',{name:'دسترسی غیرمجاز'})).toBeVisible()
  await page.goto('/logout').catch(()=>{}); await page.context().clearCookies(); await login(page,'teacher@example.test'); response=await page.goto('/admin/settings'); expect(response.status()).toBe(403)
})
