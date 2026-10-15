<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
const props = defineProps({ course:Object, categories:Array, teachers:Array, statuses:Array, navigationModes:Array, courseDefaults:Object });
const editing = !!props.course; const search = ref('');
const form = useForm({ title:props.course?.title||'', category_id:props.course?.category_id||'', short_description:props.course?.short_description||'', description:props.course?.description||'', status:props.course?.status||'DRAFT', navigation_mode:props.course?.navigation_mode||props.courseDefaults?.navigation_mode||'FREE', completion_threshold_percent:props.course?.completion_threshold_percent||props.courseDefaults?.completion_threshold_percent||80, teacher_ids:props.course?.teachers?.map(t=>t.id)||[], cover_image:null });
const filtered = computed(() => { const q=search.value.trim().toLowerCase(); return q ? props.teachers.filter(t=>`${t.first_name} ${t.last_name}`.toLowerCase().includes(q)) : props.teachers; });
const submit = () => editing ? form.transform(data=>({...data,_method:'put'})).post(`/admin/courses/${props.course.id}`) : form.post('/admin/courses');
</script>
<template><AppLayout><Head :title="editing?'ویرایش دوره':'ایجاد دوره'"/><section class="rounded-2xl border bg-white p-6"><h1 class="mb-6 text-xl font-bold">{{ editing?'ویرایش دوره':'ایجاد دوره' }}</h1><form class="grid gap-5 lg:grid-cols-2" @submit.prevent="submit">
<div class="lg:col-span-2"><label>عنوان دوره</label><input v-model="form.title"><p class="text-sm text-red-600">{{ form.errors.title }}</p></div>
<div><label>دسته‌بندی</label><select v-model="form.category_id"><option value="">بدون دسته‌بندی</option><option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
<div><label>وضعیت</label><select v-model="form.status"><option v-for="item in statuses" :key="item.value" :value="item.value">{{ item.label }}</option></select></div>
<div><label>نوع دسترسی درس‌ها</label><select v-model="form.navigation_mode"><option v-for="item in navigationModes" :key="item.value" :value="item.value">{{ item.label }}</option></select><p class="mt-1 text-xs text-slate-500">آزاد: هر درس در دسترس قابل مشاهده است. ترتیبی: درس بعدی پس از تکمیل درس قبلی باز می‌شود.</p></div>
<div><label>حد تکمیل ویدئو (درصد)</label><input v-model="form.completion_threshold_percent" type="number" min="1" max="100"></div>
<div class="lg:col-span-2"><label>توضیح کوتاه</label><textarea v-model="form.short_description" rows="2" class="w-full rounded-xl border p-3"></textarea></div><div class="lg:col-span-2"><label>توضیحات</label><textarea v-model="form.description" rows="7" class="w-full rounded-xl border p-3"></textarea></div>
<div><label>تصویر جلد (حداکثر ۲ مگابایت)</label><input type="file" accept="image/jpeg,image/png,image/webp" class="p-2" @change="form.cover_image=$event.target.files[0]"><p class="text-sm text-red-600">{{ form.errors.cover_image }}</p></div>
<div><label>مدرسان</label><input v-model="search" placeholder="جست‌وجوی مدرس" class="mb-2"><div class="max-h-48 space-y-1 overflow-y-auto rounded-xl border p-2"><label v-for="teacher in filtered" :key="teacher.id" class="flex items-center gap-2 p-2"><input v-model="form.teacher_ids" type="checkbox" :value="teacher.id" class="h-4 w-4">{{ teacher.first_name }} {{ teacher.last_name }}</label></div></div>
<div class="flex gap-3 lg:col-span-2"><button :disabled="form.processing" class="rounded-xl bg-teal-700 px-5 py-2.5 font-bold text-white">ذخیره</button><Link href="/admin/courses" class="rounded-xl border px-5 py-2.5">انصراف</Link></div>
</form></section></AppLayout></template>
