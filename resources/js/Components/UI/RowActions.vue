<script setup>
import { useI18n } from 'vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import { EyeIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';

/**
 * The standard row actions of a list: view, edit and delete (each shown only when
 * its URL is given; the page decides permissions and state). Delete asks first.
 */
const props = defineProps({
    view: { type: String, default: null },
    edit: { type: String, default: null },
    remove: { type: String, default: null },
    confirm: { type: String, default: null },
});
const { t } = useI18n();
const destroy = () => { if (window.confirm(props.confirm || t('shared.confirm_delete_record'))) router.delete(props.remove, { preserveScroll: true }); };
</script>

<template>
    <span class="inline-flex items-center gap-1" data-row-actions>
        <Link v-if="view" :href="view" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" :title="$t('shared.view')"><EyeIcon class="w-4 h-4" /></Link>
        <Link v-if="edit" :href="edit" class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50" :title="$t('ui.edit')" data-row-edit><PencilSquareIcon class="w-4 h-4" /></Link>
        <button v-if="remove" type="button" @click="destroy" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50" :title="$t('ui.delete')" data-row-delete><TrashIcon class="w-4 h-4" /></button>
    </span>
</template>
