<script setup>
import Dialog from '@/Components/UI/Dialog.vue';
import { unsavedState as s, settleUnsaved } from '@/composables/useDialog';
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

/** Host of the «لديك تغييرات غير محفوظة» dialog (mounted once at the app root, like PromptDialog). */
</script>

<template>
    <Dialog :show="s.open" :title="$t('shared.unsaved_title')" size="md" @close="settleUnsaved('stay')">
        <p class="text-sm text-slate-600" data-unsaved-dialog>{{ $t('shared.unsaved_body') }}</p>
        <template #footer>
            <button type="button" @click="settleUnsaved('save')" class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold" data-unsaved-save>
                <ArrowDownTrayIcon class="w-4 h-4" /> {{ $t('shared.save') }}
            </button>
            <button type="button" @click="settleUnsaved('discard')" class="px-5 py-2 rounded-xl border border-slate-200 text-slate-700 text-sm font-bold" data-unsaved-discard>{{ $t('shared.discard_leave') }}</button>
        </template>
    </Dialog>
</template>
