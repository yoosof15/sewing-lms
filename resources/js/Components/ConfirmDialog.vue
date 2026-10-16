<script setup>
import { nextTick, ref, watch } from 'vue'
const props = defineProps({ open: Boolean, title: String, message: String, confirmLabel: { type: String, default: 'تأیید' }, danger: Boolean, phrase: String, busy: Boolean })
const emit = defineEmits(['confirm', 'cancel'])
const dialog = ref(null)
const typed = ref('')
watch(() => props.open, async value => {
  await nextTick()
  if (value && !dialog.value.open) { typed.value = ''; dialog.value.showModal() }
  if (!value && dialog.value?.open) dialog.value.close()
})
const cancel = () => { if (!props.busy) emit('cancel') }
</script>
<template><dialog ref="dialog" class="w-[min(92vw,28rem)] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50" @cancel.prevent="cancel" @close="emit('cancel')"><div class="p-6" role="document"><h2 class="text-lg font-bold">{{title}}</h2><p class="mt-3 text-sm leading-7 text-slate-600">{{message}}</p><div v-if="phrase" class="mt-4"><label for="confirmation-phrase">عبارت {{phrase}} را وارد کنید</label><input id="confirmation-phrase" v-model="typed" autocomplete="off" dir="ltr"></div><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border px-4 py-2" :disabled="busy" @click="cancel">انصراف</button><button type="button" class="rounded-xl px-4 py-2 text-white disabled:opacity-40" :class="danger?'bg-red-700':'bg-teal-700'" :disabled="busy||(phrase&&typed!==phrase)" @click="emit('confirm')">{{busy?'در حال انجام…':confirmLabel}}</button></div></div></dialog></template>
