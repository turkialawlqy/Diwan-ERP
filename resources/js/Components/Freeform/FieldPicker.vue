<script setup>
import { computed, ref } from 'vue';

/** Searchable list of the document's fields; emits the chosen key. */
const props = defineProps({ catalogue: { type: Object, required: true }, compact: Boolean });
const emit = defineEmits(['pick']);
const q = ref('');
const groups = computed(() => props.catalogue.groups.map((g) => ({ ...g, fields: g.fields.filter((f) => !q.value || f.label.includes(q.value) || f.key.includes(q.value)) })).filter((g) => g.fields.length));
</script>

<template>
    <div class="text-xs">
        <input v-model="q" type="search" placeholder="ابحث عن حقل..." aria-label="بحث في الحقول" class="w-full rounded-lg border-slate-200 text-xs mb-2" />
        <div :class="['overflow-y-auto space-y-2', compact ? 'max-h-56' : 'max-h-[60vh]']">
            <div v-for="g in groups" :key="g.key">
                <div class="font-black text-slate-500 mb-1">{{ g.label }}</div>
                <div class="flex flex-wrap gap-1">
                    <button v-for="f in g.fields" :key="f.key" type="button" @click="emit('pick', f.key, f.label)" class="px-2 py-1 rounded-lg border border-slate-200 bg-white hover:border-indigo-400 hover:bg-indigo-50">{{ f.label }}</button>
                </div>
            </div>
            <p v-if="!groups.length" class="text-slate-400">لا توجد حقول مطابقة.</p>
        </div>
    </div>
</template>
