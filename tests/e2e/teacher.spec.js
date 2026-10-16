import { test, expect } from '@playwright/test'
import { login, watchRuntime } from './helpers.js'
test.beforeEach(async({page})=>login(page,'teacher@example.test'))
test('teacher sees only assigned course and can edit a section inline',async({page})=>{
  const clean=watchRuntime(page); await page.goto('/teacher/courses')
  await expect(page.getByText('دوره آزمایشی مرورگر')).toBeVisible(); await expect(page.getByText('دوره غیرمرتبط')).toHaveCount(0)
  await page.getByText('دوره آزمایشی مرورگر').click(); await page.getByRole('button',{name:'ویرایش'}).first().click()
  await page.getByLabel('عنوان جدید فصل').fill('فصل ویرایش‌شده'); await page.getByRole('button',{name:'ذخیره'}).click()
  await expect(page.getByText('فصل ویرایش‌شده',{exact:true}).first()).toBeVisible(); clean()
})
test('teacher replies to Q&A and reviews an assignment',async({page})=>{
  const clean=watchRuntime(page); await page.goto('/teacher/questions'); await page.getByText('پرسش آزمایشی مرورگر').click()
  await page.getByPlaceholder('متن پاسخ').fill('پاسخ مدرس در مرورگر'); await page.getByRole('button',{name:'ثبت پاسخ'}).click(); await expect(page.getByText('پاسخ مدرس در مرورگر')).toBeVisible()
  await page.goto('/teacher/submissions'); await page.getByRole('link',{name:/SUBMITTED/}).first().click(); await page.locator('select').last().selectOption('APPROVED')
  await page.getByPlaceholder('بازخورد').fill('تأیید شد'); await page.getByRole('button',{name:'ثبت نتیجه'}).click(); await expect(page.getByRole('status')).toBeVisible(); clean()
})
test('teacher cannot access an unassigned course report',async({page})=>{const response=await page.goto('/teacher/reports/courses/2');expect(response.status()).toBe(403);await expect(page.getByText('دسترسی غیرمجاز')).toBeVisible()})
