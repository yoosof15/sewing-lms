const formatter = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Tehran' })
const dateFormatter = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeZone: 'Asia/Tehran' })
export const formatDateTime = value => value ? formatter.format(new Date(value)) : '—'
export const formatDate = value => value ? dateFormatter.format(new Date(value)) : '—'
