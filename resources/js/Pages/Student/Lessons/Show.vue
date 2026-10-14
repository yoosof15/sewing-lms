<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import VideoPlayer from '../../../Components/VideoPlayer.vue';

defineProps({ course: Object, lesson: Object, videos: Array });
</script>

<template>
    <AppLayout>
        <Head :title="lesson.title" />
        <article class="rounded-2xl border bg-white p-4 sm:p-6">
            <Link :href="`/student/courses/${course.id}`" class="text-sm text-teal-700">بازگشت به دوره</Link>
            <h1 class="mt-5 text-2xl font-bold">{{ lesson.title }}</h1>
            <div class="mt-6 min-h-36 whitespace-pre-line leading-8 text-slate-700">{{ lesson.content }}</div>
            <section v-if="videos.length" class="mt-8 space-y-6 border-t pt-5" aria-labelledby="lesson-videos-heading">
                <h2 id="lesson-videos-heading" class="font-bold">ویدئوهای درس</h2>
                <article v-for="video in videos" :key="video.id" class="space-y-3">
                    <h3 class="font-semibold">{{ video.title }}</h3>
                    <VideoPlayer :src="video.stream_url" :title="video.title" />
                </article>
            </section>
            <section v-if="lesson.attachments.length" class="mt-8 border-t pt-5">
                <h2 class="mb-3 font-bold">فایل‌های درس</h2>
                <a v-for="file in lesson.attachments" :key="file.id" :href="`/attachments/${file.id}/download`" class="mb-2 block rounded-xl bg-slate-50 p-3 text-teal-700">{{ file.original_name }}</a>
            </section>
        </article>
    </AppLayout>
</template>
