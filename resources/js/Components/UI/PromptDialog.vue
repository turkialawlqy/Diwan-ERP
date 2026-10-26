<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import Dialog from '@/Components/UI/Dialog.vue';
import { dialogState as s, settle } from '@/composables/useDialog';

const field = ref(null);
const valid = computed(() => {
    if (!s.input) return true;
    const value = String(s.value ?? '').trim();
    if (s.required && !value) return false;
    return value.length >= (s.minLength || 0) || (!s.required && !value);
});

watch(() => s.open, async (open) => {
    if (open) {
        await nextTick();
        field.value?.focus?.();
    }
});

const submit = () => {
    if (!valid.value) return;
    settle(s.alertOnly ? true : s.input ? String(s.value ?? '').trim() : true);
};
const cancel = () => settle(s.alertOnly ? true : s.input ? null : false);
</script>

<template>
    <Dialog :show="s.open" :title="s.title || (s.input ? 'إدخال مطلوب' : 'تأكيد')" size="sm" @close="cancel">
        <form @submit.prevent="submit" class="space-y-3 text-sm" data-prompt-dialog>
            <p v-if="s.message" class="text-slate-700 whitespace-pre-line">{{ s.message }}</p>
            <template v-if="s.input">
                <textarea v-if="s.input === 'textarea'" ref="field" v-model="s.value" rows="3" :placeholder="s.placeholder" class="w-full rounded-xl border-slate-200 text-sm" />
                <input v-else ref="field" v-model="s.value" :type="s.input" :placeholder="s.placeholder" :step="s.input === 'number' ? 'any' : undefined" :autocomplete="s.input === 'password' ? 'one-time-code' : 'off'" class="w-full rounded-xl border-slate-200 text-sm" :dir="['date', 'number', 'password'].includes(s.input) ? 'ltr' : undefined" />
                <p v-if="s.minLength && String(s.value ?? '').trim().length < s.minLength" class="text-xs text-slate-400">{{ s.minLength }} أحرف على الأقل.</p>
            </template>
            <div class="flex justify-end gap-2 pt-2">
                <button v-if="!s.alertOnly" type="button" @click="cancel" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-bold">{{ s.cancelText }}</button>
                <button type="submit" :disabled="!valid" :class="s.danger ? 'bg-rose-600' : 'bg-indigo-600'" class="px-4 py-2 rounded-xl text-white text-xs font-bold disabled:opacity-40">{{ s.confirmText }}</button>
            </div>
        </form>
    </Dialog>
</template>
