<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
const props = defineProps({ course: Object, summary: Object });
const progressLabel = state => ({ completed: 'تکمیل‌شده', in_progress: 'در حال یادگیری', not_started: 'مشاهده‌نشده' }[state]);
const groups = computed(() => {
    const result = [];
    for (const lesson of props.course.lessons) {
        const key = lesson.section_id || 'none';
        let group = result.find(item => item.key === key);
        if (!group) { group = { key, title: lesson.section?.title || 'بدون فصل', lessons: [] }; result.push(group); }
        group.lessons.push(lesson);
    }
    return result;
});
</script>
<template>
<AppLayout><Head :title="course.title"/><div class="space-y-5">
  <section class="rounded-2xl border bg-white p-6"><h1 class="text-2xl font-bold">{{ course.title }}</h1><p class="mt-2 text-sm text-slate-500">{{ course.teachers.map(t=>`${t.first_name} ${t.last_name}`).join('، ') }}</p><div class="mt-4"><div class="flex justify-between text-sm"><span>پیشرفت فعلی دوره</span><strong>{{ summary.percent }}٪</strong></div><div class="mt-1 h-2 rounded bg-slate-200"><div class="h-full rounded bg-teal-600" :style="{width:`${summary.percent}%`}"></div></div><p class="mt-1 text-xs text-slate-500">درس‌های آینده تا زمان بازشدن در این درصد محاسبه نمی‌شوند.</p></div><p class="mt-5 whitespace-pre-line leading-8">{{ course.description }}</p></section>
  <section class="rounded-2xl border bg-white p-5"><h2 class="mb-4 text-lg font-bold">برنامه درسی</h2><div v-for="group in groups" :key="group.key" class="mb-6"><h3 class="mb-2 font-bold text-slate-700">{{ group.title }}</h3><div v-for="lesson in group.lessons" :key="lesson.id" class="mb-2 rounded-xl border p-3"><Link v-if="lesson.access.can_view" :href="`/student/courses/${course.id}/lessons/${lesson.id}`" class="flex justify-between hover:text-teal-700"><span>{{ lesson.title }}</span><span class="text-sm text-teal-700">{{ progressLabel(lesson.progress_state) }}</span></Link><div v-else><div class="flex justify-between text-slate-500"><span>{{ lesson.title }}</span><span class="text-xs">قفل‌شده</span></div><p class="mt-1 text-xs text-amber-700">{{ lesson.access.reason }}</p><time v-if="lesson.access.available_at" class="text-xs text-slate-500">{{ new Date(lesson.access.available_at).toLocaleString('fa-IR') }}</time></div></div></div><p v-if="!course.lessons.length" class="text-slate-500">هنوز درس منتشرشده‌ای وجود ندارد.</p></section>
</div></AppLayout>
</template>
