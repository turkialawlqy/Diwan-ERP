<script setup>
import { ref } from 'vue';
import { PaperClipIcon } from '@heroicons/vue/24/outline';
import Dialog from '@/Components/UI/Dialog.vue';
import AttachmentsPanel from '@/Components/Documents/AttachmentsPanel.vue';

/** A row action for lists whose documents have no page of their own (vouchers, debit notes): opens the attachments in a dialog. */
defineProps({ kind: { type: String, required: true }, id: { type: [Number, String], required: true } });
const open = ref(false);
</script>

<template>
    <span v-if="$can('admin.attachments.view')" class="inline-flex">
        <button type="button" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" :title="$t('shared.attachments')" data-attachments-button @click="open = true"><PaperClipIcon class="w-4 h-4" /></button>
        <Dialog :show="open" :title="$t('shared.attachments')" @close="open = false"><AttachmentsPanel v-if="open" :kind="kind" :id="id" /></Dialog>
    </span>
</template>
