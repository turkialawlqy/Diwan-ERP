<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { qty, money, date } from '@/utils/format';

const props = defineProps({ transfer: Object });
const received = reactive(Object.fromEntries(props.transfer.items.map((i) => [i.id, Number(i.quantity)])));
const send = () => confirm('إرسال البضاعة؟ سيتم خصمها من المستودع المصدر.') && router.post(route('inventory.stock-transfers.send', props.transfer.id));
const receive = () => confirm('تأكيد الاستلام بالكميات المدخلة؟') && router.post(route('inventory.stock-transfers.receive', props.transfer.id), { received });
const cancel = () => confirm('إلغاء التحويل؟') && router.post(route('inventory.stock-transfers.cancel', props.transfer.id));
const statusLabel = { draft: 'مسودة', in_transit: 'في الطريق', received: 'مستلم', cancelled: 'ملغى' };
</script>

<template>
    <AppLayout>
        <Head :title="transfer.transfer_number" />
        <PageHeader :title="`تحويل ${transfer.transfer_number}`" :subtitle="`${transfer.from_warehouse.name_ar} ← ${transfer.to_warehouse.name_ar} · ${date(transfer.transfer_date)} · ${statusLabel[transfer.status]}`" :crumbs="[{ label: 'التحويلات', href: route('inventory.stock-transfers.index') }, { label: transfer.transfer_number }]">
            <template #actions>
                <template v-if="transfer.status === 'draft' && $can('inventory.transfers.create')">
                    <button @click="send" class="px-4 py-2 rounded-xl bg-amber-600 text-white text-xs font-bold">إرسال</button>
                    <button @click="cancel" class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-xs font-bold">إلغاء</button>
                </template>
                <button v-if="transfer.status === 'in_transit' && $can('inventory.transfers.approve')" @click="receive" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">تأكيد الاستلام</button>
            </template>
        </PageHeader>

        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="text-start px-4 py-2">الصنف</th><th class="text-end px-4 py-2">الكمية المرسلة</th><th class="text-end px-4 py-2">تكلفة الوحدة</th><th class="text-center px-4 py-2">الكمية المستلمة</th></tr></thead>
                <tbody>
                    <tr v-for="item in transfer.items" :key="item.id" class="border-t border-slate-100">
                        <td class="px-4 py-2 font-semibold">{{ item.product?.name_ar }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ qty(item.quantity) }}</td>
                        <td class="px-4 py-2 text-end font-mono">{{ money(item.unit_cost) }}</td>
                        <td class="px-4 py-2 text-center">
                            <input v-if="transfer.status === 'in_transit'" v-model="received[item.id]" type="number" step="0.0001" min="0" :max="item.quantity" class="w-32 rounded-lg border-slate-200 text-xs font-mono text-center" />
                            <span v-else class="font-mono">{{ item.received_quantity === null ? '—' : qty(item.received_quantity) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
