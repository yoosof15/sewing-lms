import { test, expect } from '@playwright/test'
import { login, watchRuntime, password } from './helpers.js'
test.beforeEach(async ({page})=>login(page,'admin@example.test'))

test('admin creates and edits a Student through accessible forms', async ({page})=>{
  const clean=watchRuntime(page)
  await page.goto('/admin/users')
  await page.getByRole('link',{name:'کاربر جدید'}).click()
  await page.locator('#first-name').fill('کاربر')
  await page.locator('#last-name').fill('مرورگر')
  await page.locator('#mobile').fill('09100000010')
  await page.locator('#email').fill('created@example.test')
  await page.locator('#new-password').fill(password)
  await page.locator('#password-confirmation').fill(password)
  await page.getByRole('button',{name:'ذخیره'}).click()
  await expect(page.getByText('کاربر مرورگر')).toBeVisible()
  await page.locator('article').filter({hasText:'کاربر مرورگر'}).getByRole('link',{name:'جزئیات'}).click()
  await expect(page.getByRole('heading',{name:'کاربر مرورگر'})).toBeVisible()
  clean()
})

test('bulk import requires preview and explicit accessible confirmation', async ({page})=>{
  const clean=watchRuntime(page)
  await page.goto('/admin/student-imports')
  await page.locator('select').first().selectOption('slug')
  await page.locator('input[type=file]').setInputFiles({name:'students.csv',mimeType:'text/csv',buffer:Buffer.from('first_name,last_name,mobile,email\nE2E,Imported,09100000011,imported@example.test')})
  await page.getByRole('button',{name:/بررسی و پیش‌نمایش/}).click()
  await expect(page.getByRole('heading',{name:/مرحله ۲/})).toBeVisible()
  await expect(page.getByText('جدید معتبر: 1')).toBeVisible()
  await page.getByRole('button',{name:'ورود اطلاعات'}).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.getByRole('dialog').getByRole('button',{name:'تأیید و اجرا'}).click()
  await expect(page.getByText('ساخته‌شده: 1')).toBeVisible()
  clean()
})

test('admin course, settings and grouped navigation render without overflow', async ({page})=>{
  const clean=watchRuntime(page)
  await page.goto('/admin/courses'); await expect(page.getByText('دوره آزمایشی مرورگر')).toBeVisible()
  await page.goto('/admin/settings'); await expect(page.getByRole('heading',{name:'تنظیمات سامانه'})).toBeVisible()
  const navigation=page.getByRole('navigation',{name:'ناوبری اصلی'})
  await expect(navigation.getByText('آموزش',{exact:true})).toBeVisible(); await expect(navigation.getByText('تعامل',{exact:true})).toBeVisible()
  clean()
})
