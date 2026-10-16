import { expect } from '@playwright/test'
export const password = 'Phase17-Test-Only!'
export async function login(page, email) {
  await page.goto('/login')
  await page.getByLabel('ایمیل یا شماره موبایل').fill(email)
  await page.getByLabel('رمز عبور').fill(password)
  await page.getByRole('button', { name: 'ورود' }).click()
  await expect(page).toHaveURL(/\/(admin|teacher|student)$/)
}
export function watchRuntime(page) {
  const errors=[]
  page.on('console', message => { if(message.type()==='error' && !message.text().includes('favicon')) errors.push(`console: ${message.text()}`) })
  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`))
  page.on('response', response => { if(response.status()>=500) errors.push(`http ${response.status()}: ${response.url()}`) })
  return () => expect(errors, errors.join('\n')).toEqual([])
}
