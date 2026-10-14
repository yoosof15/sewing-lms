<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import VideoUploader from '../../Components/VideoUploader.vue';

const props = defineProps({ course: Object, lesson: Object, statuses: Array, videoConfig: Object });
const role = usePage().props.auth.user.role.toLowerCase();
const editing = !!props.lesson;
const form = useForm({ title: props.lesson?.title || '', content: props.lesson?.content || '', status: props.lesson?.status || 'DRAFT' });
const submit = () => editing ? form.put(`/${role}/courses/${props.course.id}/lessons/${props.lesson.id}`) : form.post(`/${role}/courses/${props.course.id}/lessons`);
const upload = useForm({ attachment: null });
const format = (bytes) => bytes == null ? '—' : `${(bytes / 1024 / 1024).toFixed(1)} مگابایت`;
const statusLabel = (status) => ({ STORED: props.videoConfig?.processingEnabled ? 'در انتظار پردازش' : 'پردازش غیرفعال است', PROCESSING: 'در حال پردازش', READY: 'آماده', FAILED: 'خطا در پردازش', UPLOADING: 'در حال بارگذاری' }[status] || status);
const publicationLabel = (status) => ({ DRAFT: 'پیش‌نویس', PUBLISHED: 'منتشرشده', INACTIVE: 'غیرفعال' }[status] || status);
const reduction = (video) => video.processed_size_bytes && video.size_bytes ? Math.max(0, Math.round((1 - video.processed_size_bytes / video.size_bytes) * 100)) : null;
const duration = (seconds) => seconds == null ? '—' : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
</script>

<template>
    <AppLayout>
        <Head :title="editing ? 'ویرایش درس' : 'درس جدید'" />
        <div class="space-y-5">
            <section class="rounded-2xl border bg-white p-6">
                <h1 class="mb-5 text-xl font-bold">{{ editing ? 'ویرایش درس' : 'ایجاد درس' }}</h1>
                <form class="space-y-4" @submit.prevent="submit">
                    <div><label>عنوان</label><input v-model="form.title"><p class="text-sm text-red-600">{{ form.errors.title }}</p></div>
                    <div><label>محتوای متنی</label><textarea v-model="form.content" rows="12" class="w-full rounded-xl border p-3"></textarea></div>
                    <div><label>وضعیت</label><select v-model="form.status"><option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option></select></div>
                    <div class="flex gap-3"><button :disabled="form.processing" class="rounded-xl bg-teal-700 px-5 py-2.5 text-white disabled:opacity-50">{{ form.processing ? 'در حال ذخیره…' : 'ذخیره' }}</button><Link :href="`/${role}/courses/${course.id}`" class="rounded-xl border px-5 py-2.5">بازگشت</Link></div>
                </form>
            </section>
            <section v-if="editing" class="rounded-2xl border bg-white p-6">
                <h2 class="mb-4 font-bold">ویدئوها</h2>
                <VideoUploader :lesson-id="lesson.id" :max-bytes="videoConfig.maxBytes" />
                <div class="mt-4 space-y-3">
                    <div v-for="video in lesson.videos" :key="video.id" class="flex flex-wrap justify-between gap-3 rounded-xl bg-slate-50 p-4" :class="video.deleted_at ? 'opacity-70' : ''">
                        <div class="space-y-1">
                            <strong>{{ video.sort_order }}. {{ video.title }}</strong><span v-if="video.deleted_at" class="mr-2 text-xs text-red-700">بایگانی‌شده</span>
                            <p class="text-sm" :class="video.status === 'FAILED' ? 'text-red-700' : 'text-teal-700'">وضعیت پردازش: {{ statusLabel(video.status) }}</p>
                            <p class="text-sm text-slate-700">وضعیت انتشار: {{ publicationLabel(video.publication_status) }}</p>
                            <p class="text-xs text-slate-500">{{ video.original_filename }} — حجم اصلی: {{ format(video.size_bytes) }}</p>
                            <div v-if="video.status === 'READY'" class="text-xs text-slate-600">
                                <span>حجم بهینه: {{ format(video.processed_size_bytes) }}</span>
                                <span v-if="reduction(video) !== null"> — کاهش حجم: {{ reduction(video) }}٪</span>
                                <span> — مدت: {{ duration(video.duration_seconds) }}</span>
                                <span> — وضوح: {{ video.width }}×{{ video.height }}</span>
                            </div>
                            <p v-if="video.status === 'FAILED'" class="text-xs text-red-600">{{ video.failure_reason }}</p>
                            <p class="text-xs">بارگذار: {{ video.uploader.first_name }} {{ video.uploader.last_name }}</p>
                        </div>
                        <div v-if="video.deleted_at && role === 'admin'" class="flex items-center gap-3"><Link :href="`/admin/lessons/${lesson.id}/videos/${video.id}/restore`" method="post" as="button" class="text-teal-700">بازیابی</Link><Link :href="`/admin/purge/videos/${video.id}`" method="delete" :data="{confirmation:'PURGE'}" as="button" class="text-red-700">حذف دائمی تأییدشده</Link></div>
                        <div v-else-if="!video.deleted_at" class="flex items-center gap-3">
                            <a v-if="video.status === 'READY'" :href="`/videos/${video.id}/preview`" target="_blank" class="rounded-lg border px-3 py-1">پیش‌نمایش</a>
                            <Link v-if="video.status === 'READY' && video.publication_status !== 'PUBLISHED'" :href="`/lessons/${lesson.id}/videos/${video.id}/publication`" method="patch" :data="{ publication_status: 'PUBLISHED' }" as="button" class="rounded-lg border border-teal-700 px-3 py-1 text-teal-800">انتشار</Link>
                            <Link v-if="video.publication_status === 'PUBLISHED'" :href="`/lessons/${lesson.id}/videos/${video.id}/publication`" method="patch" :data="{ publication_status: 'INACTIVE' }" as="button" class="rounded-lg border px-3 py-1">غیرفعال‌سازی</Link>
                            <Link v-if="video.publication_status === 'INACTIVE'" :href="`/lessons/${lesson.id}/videos/${video.id}/publication`" method="patch" :data="{ publication_status: 'DRAFT' }" as="button" class="rounded-lg border px-3 py-1">پیش‌نویس</Link>
                            <Link v-if="video.status === 'FAILED' && video.processing_attempts < videoConfig.maxAttempts && videoConfig.processingEnabled" :href="`/lessons/${lesson.id}/videos/${video.id}/retry`" method="post" as="button" class="rounded-lg border border-teal-700 px-3 py-1 text-teal-800">تلاش مجدد</Link>
                            <Link :href="`/lessons/${lesson.id}/videos/${video.id}/move/up`" method="post" as="button">↑</Link>
                            <Link :href="`/lessons/${lesson.id}/videos/${video.id}/move/down`" method="post" as="button">↓</Link>
                            <Link :href="`/lessons/${lesson.id}/videos/${video.id}`" method="delete" as="button" class="text-red-700">بایگانی</Link>
                        </div>
                    </div>
                </div>
            </section>
            <section v-if="editing" class="rounded-2xl border bg-white p-6">
                <h2 class="mb-4 font-bold">فایل‌های ضمیمه</h2>
                <form class="flex flex-wrap gap-3" @submit.prevent="upload.post(`/lessons/${lesson.id}/attachments`, { onSuccess: () => upload.reset() })">
                    <input type="file" class="max-w-md" @change="upload.attachment = $event.target.files[0]">
                    <button :disabled="upload.processing" class="rounded-xl border px-4 py-2 disabled:opacity-50">{{ upload.processing ? 'در حال بارگذاری…' : 'بارگذاری' }}</button>
                </form>
                <p class="mt-2 text-sm text-red-600">{{ upload.errors.attachment }}</p>
                <ul class="mt-4 space-y-2"><li v-for="file in lesson.attachments" :key="file.id" class="flex items-center justify-between rounded-lg bg-slate-50 p-2"><span :class="file.deleted_at ? 'text-slate-500' : 'text-teal-700'"><a v-if="!file.deleted_at" :href="`/attachments/${file.id}/download`">{{ file.original_name }}</a><template v-else>{{ file.original_name }} — بایگانی‌شده</template></span><Link v-if="!file.deleted_at" :href="`/attachments/${file.id}`" method="delete" as="button" class="text-sm text-red-700">بایگانی</Link><Link v-else-if="role==='admin'" :href="`/admin/purge/attachments/${file.id}`" method="delete" :data="{confirmation:'PURGE'}" as="button" class="text-sm text-red-700">حذف دائمی تأییدشده</Link></li><li v-if="!lesson.attachments.length" class="text-slate-500">فایلی پیوست نشده است.</li></ul>
            </section>
        </div>
    </AppLayout>
</template>
