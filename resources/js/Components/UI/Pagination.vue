<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: { type: Object, required: true },
});
</script>

<template>
    <div v-if="paginator && paginator.last_page > 1" class="flex flex-col sm:flex-row items-center justify-between gap-2 px-4 py-3 border-t border-slate-100 text-xs text-slate-500 print:hidden">
        <span>{{ $t('ui.page_of', { page: paginator.current_page, pages: paginator.last_page, total: paginator.total }) }}</span>
        <div class="flex gap-2">
            <Link v-if="paginator.prev_page_url" :href="paginator.prev_page_url" preserve-scroll preserve-state class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('ui.previous') }}</Link>
            <Link v-if="paginator.next_page_url" :href="paginator.next_page_url" preserve-scroll preserve-state class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">{{ $t('ui.next') }}</Link>
        </div>
    </div>
</template>
