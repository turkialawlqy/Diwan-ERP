<script setup>
defineProps({ approvals: { type: Array, default: () => [] } });
const label = { submitted: 'أُرسل للاعتماد', approved: 'اعتُمد', rejected: 'رُفض' };
</script>

<template>
    <div v-if="approvals.length" class="bg-white border border-slate-200 rounded-2xl p-4">
        <h3 class="text-xs font-black text-slate-700 mb-3">سجل الاعتماد</h3>
        <ol class="space-y-2 text-xs">
            <li v-for="a in approvals" :key="a.id" class="flex gap-2">
                <span class="w-2 h-2 rounded-full mt-1.5" :class="a.action === 'rejected' ? 'bg-rose-500' : a.action === 'approved' ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <div>
                    <div><b>{{ label[a.action] || a.action }}</b> — {{ a.user?.name || 'النظام' }} <span class="text-slate-400 font-mono">{{ String(a.created_at).substring(0, 16).replace('T', ' ') }}</span></div>
                    <div v-if="a.comment" class="text-slate-500">{{ a.comment }}</div>
                </div>
            </li>
        </ol>
    </div>
</template>
