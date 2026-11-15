<script setup>
import ImportButton from '@/Components/UI/ImportButton.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    ArrowPathIcon,
    HomeIcon,
    ChevronRightIcon,
    BuildingStorefrontIcon,
    XMarkIcon,
    TrashIcon,
    PencilSquareIcon,
    MapPinIcon,
    PhoneIcon,
    StarIcon,
    CubeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    warehouses: Array,
    branches: Array,
    stats: Object,
});

</script>

<template>
    <AppLayout>
        <Head :title="$t('i_warehouses_index.warehouses')" />

        <div class="space-y-5">
            <!-- Header Ribbon -->
            <PageHeader :title="$t('i_warehouses_index.warehouses')" :crumbs="[{ label: 'المخزون' }, { label: 'المستودعات' }]">
<template #actions>
<Link 
                        :href="route('inventory.warehouses.index')"
                        class="p-2.5 rounded-xl bg-white border border-slate-200/80 text-slate-500 hover:text-indigo-600 hover:bg-slate-50 transition-colors shadow-xs"
                        :title="$t('i_warehouses_index.refresh_data')"
                    >
                        <ArrowPathIcon class="w-4 h-4" />
                    </Link>
<Link v-if="$can('inventory.warehouses.create')" :href="route('inventory.warehouses.create')" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs hover:shadow-indigo-500/20 transition-all flex items-center gap-1.5">
                        <PlusIcon class="w-4 h-4 stroke-2" />
                        <span>{{ $t('i_warehouses_index.add_a_new_warehouse') }}</span>
                    </Link>
                <ImportButton v-if="$can('inventory.warehouses.create')" type="warehouses" :title="$t('i_warehouses_index.import_warehouses_from_excel')" />
</template>
</PageHeader>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">{{ $t('i_warehouses_index.total_warehouses') }}</p>
                        <h3 class="text-xl font-black text-slate-900">{{ stats?.total_warehouses ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">{{ $t('i_warehouses_index.active_warehouses') }}</p>
                        <h3 class="text-xl font-black text-emerald-600">{{ stats?.active_warehouses ?? 0 }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <BuildingStorefrontIcon class="w-5 h-5" />
                    </div>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                    <div>
                        <p class="text-xs text-slate-500 font-bold mb-1">{{ $t('i_warehouses_index.current_main_warehouse') }}</p>
                        <h3 class="text-base font-bold text-slate-800 truncate max-w-[200px]">{{ stats?.main_warehouse ?? '—' }}</h3>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <StarIcon class="w-5 h-5" />
                    </div>
                </div>
            </div>

            <!-- Warehouses Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <div 
                    v-for="wh in warehouses" 
                    :key="wh.id"
                    class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs hover:shadow-md transition-all p-5 flex flex-col justify-between relative group"
                >
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-600 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors">
                                    <BuildingStorefrontIcon class="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
                                        <span>{{ wh.name_ar }}</span>
                                        <span v-if="wh.is_main" class="bg-amber-100 text-amber-700 text-[10px] font-black px-2 py-0.5 rounded-full flex items-center gap-1">
                                            <StarIcon class="w-3 h-3 fill-amber-500 text-amber-500" />
                                            <span>{{ $t('i_warehouses_index.main') }}</span>
                                        </span>
                                    </h3>
                                    <p class="text-[11px] text-slate-400 font-mono">{{ wh.code }}</p>
                                </div>
                            </div>

                            <span 
                                :class="wh.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200'"
                                class="text-[11px] font-bold px-2 py-0.5 rounded-lg border"
                            >
                                {{ wh.is_active ? $t('i_common.active') : $t('i_common.disabled') }}
                            </span>
                        </div>

                        <div class="space-y-2 mt-4 pt-3 border-t border-slate-100 text-xs text-slate-600">
                            <div v-if="wh.branch" class="flex items-center justify-between text-slate-500">
                                <span>{{ $t('i_warehouses_index.branch') }}</span>
                                <span class="font-bold text-slate-800">{{ wh.branch?.name_ar }}</span>
                            </div>
                            <div class="flex items-center justify-between text-slate-500">
                                <span>{{ $t('i_warehouses_index.number_of_stored_items') }}</span>
                                <span class="font-bold text-indigo-600 flex items-center gap-1">
                                    <CubeIcon class="w-3.5 h-3.5" />
                                    <span>{{ $t('i_warehouses_index.items_n', { n: wh.stocks_count ?? 0 }) }}</span>
                                </span>
                            </div>
                            <div v-if="wh.phone" class="flex items-center gap-1.5 text-slate-500 pt-1">
                                <PhoneIcon class="w-3.5 h-3.5 text-slate-400" />
                                <span dir="ltr">{{ wh.phone }}</span>
                            </div>
                            <div v-if="wh.address" class="flex items-center gap-1.5 text-slate-500">
                                <MapPinIcon class="w-3.5 h-3.5 text-slate-400" />
                                <span class="truncate">{{ wh.address }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                        <Link v-if="$can('inventory.warehouses.edit')" :href="route('inventory.warehouses.edit', wh.id)" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" :title="$t('i_warehouses_index.edit')">
                            <PencilSquareIcon class="w-4 h-4" />
                        </Link>
                        <button 
                            v-if="!wh.is_main"
                            @click="deleteWarehouse(wh.id, wh.name_ar)"
                            class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                            :title="$t('i_warehouses_index.delete')"
                        >
                            <TrashIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <div 
                    v-if="warehouses.length === 0" 
                    class="col-span-full bg-white rounded-2xl border border-dashed border-slate-300 p-12 text-center"
                >
                    <BuildingStorefrontIcon class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                    <h3 class="text-sm font-bold text-slate-800">{{ $t('i_warehouses_index.no_warehouses_recorded_yet') }}</h3>
                    <p class="text-xs text-slate-400 mt-1 mb-4">{{ $t('i_warehouses_index.start_by_adding_the_first_wareho') }}</p>
                    <button 
                        @click="openAddModal"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition"
                    >
                        {{ $t('i_warehouses_index.add_warehouse') }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
