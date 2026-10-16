import { test, expect } from '@playwright/test'
import { login, watchRuntime } from './helpers.js'
test.beforeEach(async({page})=>login(page,'student@example.test'))
test('student curriculum enforces lock then unlocks after completion',async({page})=>{
  await page.goto('/student/courses'); await expect(page.getByText('دوره آزمایشی مرورگر')).toBeVisible(); await expect(page.getByText('دوره غیرمرتبط')).toHaveCount(0)
  await page.getByRole('link',{name:'باز کردن دوره'}).click(); await expect(page.getByText('درس قفل‌شده بعدی')).toBeVisible()
  const response=await page.goto('/student/courses/1/lessons/2'); expect(response.status()).toBe(403)
  const clean=watchRuntime(page); await page.goto('/student/courses/1/lessons/1'); await page.getByRole('button',{name:/تکمیل‌شده/}).click(); await page.goto('/student/courses/1')
  await page.getByText('درس قفل‌شده بعدی').click(); await expect(page.getByRole('heading',{name:'درس قفل‌شده بعدی'})).toBeVisible(); clean()
})
test('student submits assignment, asks question, reads notification and edits profile',async({page})=>{
  const clean=watchRuntime(page); await page.goto('/student/courses/1/lessons/1'); await page.getByText('تکلیف آزمایشی مرورگر').click()
  await page.getByPlaceholder('توضیحات').fill('تلاش دوم از مرورگر'); await page.getByRole('button',{name:'ثبت تکلیف'}).click(); await expect(page.getByText('تلاش دوم از مرورگر')).toBeVisible()
  await page.goto('/student/courses/1/lessons/1'); await page.getByPlaceholder('موضوع (اختیاری)').fill('پرسش تازه E2E'); await page.getByPlaceholder('متن پرسش').fill('متن پرسش تازه مرورگر'); await page.getByRole('button',{name:'ثبت پرسش'}).click(); await expect(page.getByText('پرسش تازه E2E')).toBeVisible()
  await page.goto('/notifications'); await expect(page.getByText('اعلان آزمایشی')).toBeVisible(); await page.getByRole('button',{name:'خوانده شد'}).click()
  await page.goto('/account/profile'); await page.locator('textarea').first().fill('پروفایل ویرایش‌شده در E2E'); await page.getByRole('button',{name:'ذخیره پروفایل'}).click(); await expect(page.locator('textarea').first()).toHaveValue('پروفایل ویرایش‌شده در E2E'); clean()
})
test('another Student cannot access private Q&A or submission',async({page})=>{await page.context().clearCookies();await login(page,'student2@example.test');let response=await page.goto('/questions/1');expect(response.status()).toBe(403);response=await page.goto('/submissions/1');expect(response.status()).toBe(403)})
