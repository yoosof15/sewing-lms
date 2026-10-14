<script setup>
import { ref } from 'vue';

defineProps({ src: { type: String, required: true }, title: { type: String, default: 'ویدئوی آموزشی' } });
const failed = ref(false);
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-slate-950">
        <video
            v-if="!failed"
            class="aspect-video h-auto w-full max-h-[75vh] bg-black object-contain"
            :src="src"
            :aria-label="title"
            controls
            controlsList="nodownload"
            playsinline
            preload="metadata"
            @contextmenu.prevent
            @error="failed = true"
        >
            مرورگر شما پخش ویدئو را پشتیبانی نمی‌کند.
        </video>
        <p v-else class="p-6 text-center text-sm text-white">ویدئو در دسترس نیست یا دسترسی شما تغییر کرده است.</p>
    </div>
</template>
