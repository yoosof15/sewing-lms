<script setup>
import {Head,Link,usePage} from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
defineProps({title:String,metrics:Object})
const user=usePage().props.auth.user
const cards=[['active_students','هنرجوی فعال'],['active_enrollments','ثبت‌نام فعال'],['published_courses','دوره منتشرشده'],['pending_submissions','تکلیف در انتظار بررسی'],['open_questions','پرسش بدون پاسخ'],['failed_videos','ویدیوی خطادار']]
</script>
<template><AppLayout><Head :title="title"/><section class="rounded-2xl border bg-white p-6"><div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold">{{title}}</h1><p class="mt-2 text-slate-600">{{user.name}}، خوش آمدید.</p></div><Link :href="`/${user.role.toLowerCase()}/reports`" class="rounded-xl bg-teal-700 px-4 py-2 text-white">مشاهده گزارش‌ها</Link></div><div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><div v-for="[key,label] in cards" :key="key" class="rounded-xl bg-slate-50 p-4"><p class="text-sm text-slate-500">{{label}}</p><strong class="mt-2 block text-2xl">{{metrics?.[key]??0}}</strong></div></div></section></AppLayout></template>
