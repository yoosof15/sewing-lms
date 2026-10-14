<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ src: { type: String, required: true }, title: { type: String, default: 'ویدئوی آموزشی' }, progressUrl: String, resumePosition: { type: Number, default: 0 }, watermark: Object, heartbeatSeconds: { type: Number, default: 12 } });
const emit = defineEmits(['progress']);
const failed = ref(false);
const video = ref(null);
const positionIndex = ref(0);
const positions = ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right'];
let heartbeat = null;
let watermarkTimer = null;
const positionClass = computed(() => ({ 'top-left': 'top-4 left-4', 'top-right': 'top-4 right-4', center: 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2', 'bottom-left': 'bottom-16 left-4', 'bottom-right': 'bottom-16 right-4' }[positions[positionIndex.value]]));
const save = async () => {
    if (!props.progressUrl || !video.value) return;
    try {
        const { data } = await axios.post(props.progressUrl, { position_seconds: Math.max(0, Math.floor(video.value.currentTime || 0)) });
        emit('progress', data);
    } catch (error) {
        if ([401, 403, 404].includes(error.response?.status)) failed.value = true;
    }
};
const start = () => { if (!heartbeat) { save(); heartbeat = setInterval(save, Math.max(10, props.heartbeatSeconds) * 1000); } };
const stop = () => { if (heartbeat) clearInterval(heartbeat); heartbeat = null; };
const pause = () => { stop(); save(); };
const loaded = () => { if (props.resumePosition > 0 && props.resumePosition < video.value.duration - 3) video.value.currentTime = props.resumePosition; };
const visibility = () => { if (document.hidden) save(); };
onMounted(() => {
    document.addEventListener('visibilitychange', visibility);
    if (props.watermark?.enabled) watermarkTimer = setInterval(() => { positionIndex.value = (positionIndex.value + 1) % positions.length; }, Math.max(20, props.watermark.move_seconds || 30) * 1000);
});
onBeforeUnmount(() => { stop(); save(); document.removeEventListener('visibilitychange', visibility); if (watermarkTimer) clearInterval(watermarkTimer); });
</script>

<template>
    <div class="relative overflow-hidden rounded-2xl bg-slate-950">
        <video ref="video" v-if="!failed" class="aspect-video h-auto w-full max-h-[75vh] bg-black object-contain" :src="src" :aria-label="title" controls controlsList="nodownload" playsinline preload="metadata" @loadedmetadata="loaded" @play="start" @pause="pause" @ended="pause" @seeked="save" @contextmenu.prevent @error="failed = true">مرورگر شما پخش ویدئو را پشتیبانی نمی‌کند.</video>
        <div v-if="watermark?.enabled && !failed" class="pointer-events-none absolute z-10 max-w-[45%] rounded bg-black/35 px-3 py-2 text-center text-xs text-white/70 transition-all duration-700 sm:text-sm" :class="positionClass"><div>{{ watermark.text }}</div><div dir="ltr">{{ watermark.mobile }}</div></div>
        <p v-if="failed" class="p-6 text-center text-sm text-white">ویدئو در دسترس نیست یا دسترسی شما تغییر کرده است.</p>
    </div>
</template>
