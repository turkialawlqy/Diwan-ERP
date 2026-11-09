<script setup>
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue';
import { ArrowPathIcon, BuildingStorefrontIcon, CheckIcon, ChevronDownIcon } from '@heroicons/vue/24/outline';
import { confirmLeaving } from '@/composables/useUnsavedGuard';

/**
 * «سياق العمل» at the top of the sidebar: the company, the branch being worked in
 * and the fiscal year. The choice lives in the session (App\Services\ActiveBranch,
 * ActiveFiscalYear) and reaches every page as tenant / branchSwitcher / fiscalSwitcher;
 * this card only shows those props and posts a new choice (context.update).
 * A user belongs to one company, so the company is shown, not chosen.
 */
const props = defineProps({
    // Collapsed icon rail (desktop): an icon with a tooltip instead of the card.
    slim: { type: Boolean, default: false },
});

const page = usePage();
const company = computed(() => page.props.tenant);
const branches = computed(() => page.props.branchSwitcher);
const years = computed(() => page.props.fiscalSwitcher);

const manyCountries = computed(() => new Set((branches.value?.items || []).map((b) => b.country)).size > 1);
const { t } = useI18n();
const branchLabel = (b) => (manyCountries.value ? `${b.name} — ${b.country_name}` : b.name);

const activeBranch = computed(() => {
    const s = branches.value;
    if (!s) return null;
    return s.active === 'all' ? { id: 'all', name: t('ctx.all_branches') } : s.items.find((b) => b.id === s.active) || null;
});
const activeYear = computed(() => years.value?.items.find((y) => y.id === years.value.active) || null);

const summary = computed(() => [activeBranch.value?.name, activeYear.value?.name].filter(Boolean).join(' • '));
const details = computed(() => [
    company.value?.name,
    activeBranch.value && t('ctx.branch_is', { name: activeBranch.value.name }),
    activeYear.value && t('ctx.year_is', { name: activeYear.value.name }),
].filter(Boolean));

const canPickBranch = computed(() => (branches.value?.items.length || 0) > 1);
const canPickYear = computed(() => (years.value?.items.length || 0) > 1);
const supportMode = computed(() => !!page.props.impersonation?.is_impersonating);

// The popover's choices: nothing changes until «تطبيق التغيير».
const branchChoice = ref(null);
const yearChoice = ref(null);
const error = ref('');
const applying = ref(false);

const reset = () => {
    branchChoice.value = branches.value?.active ?? null;
    yearChoice.value = years.value?.active ?? null;
    error.value = '';
};
reset();

const changes = computed(() => {
    const data = {};
    if (canPickBranch.value && branchChoice.value !== branches.value.active) data.branch_id = branchChoice.value;
    if (canPickYear.value && yearChoice.value !== years.value.active) data.fiscal_year_id = yearChoice.value;
    return data;
});
const changed = computed(() => Object.keys(changes.value).length > 0);

const apply = async () => {
    if (!changed.value || applying.value || !(await confirmLeaving())) return;

    router.post(route('context.update'), changes.value, {
        preserveScroll: true,
        // Accepted: the page is built again from the new context's data (nothing kept from
        // the old one). Refused: the page stays as it was, with the reason.
        preserveState: 'errors',
        onStart: () => { applying.value = true; error.value = ''; },
        onError: (errors) => { error.value = errors.context || Object.values(errors)[0] || ''; },
        onFinish: () => { applying.value = false; },
    });
};
</script>

<template>
    <Popover v-if="company" v-slot="{ open }" :key="String(props.slim)" class="relative" data-workspace-context>
        <PopoverButton
            @click="open || reset()"
            aria-haspopup="dialog"
            :aria-label="$t('ctx.aria', { details: details.join('، ') })"
            :class="[
                open ? 'bg-slate-800 border-slate-600' : 'bg-slate-800/40 border-slate-700/60 hover:bg-slate-800 hover:border-slate-600',
                props.slim ? 'lg:w-12 lg:h-12 lg:mx-auto lg:px-0 lg:justify-center' : '',
                'peer group w-full flex items-center gap-2.5 rounded-xl border px-3 py-2 text-start transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400'
            ]"
            data-context-card
        >
            <BuildingStorefrontIcon :class="[props.slim ? 'lg:w-6 lg:h-6 lg:self-center lg:mt-0' : '', applying ? 'animate-pulse' : '']" class="w-5 h-5 text-brand-400 flex-shrink-0 self-start mt-px" />
            <span :class="props.slim ? 'lg:hidden' : ''" class="flex-1 min-w-0 leading-tight">
                <span class="block text-sm font-bold text-white truncate" :title="company.name" data-context-company-name>{{ company.name }}</span>
                <span v-if="summary" class="block text-[11px] text-slate-400 truncate mt-1" :title="summary" data-context-summary>{{ summary }}</span>
            </span>
            <ArrowPathIcon v-if="applying" :class="props.slim ? 'lg:hidden' : ''" class="w-4 h-4 text-slate-400 animate-spin flex-shrink-0" />
            <ChevronDownIcon v-else :class="[open ? 'rotate-180' : '', props.slim ? 'lg:hidden' : '', 'w-4 h-4 text-slate-500 group-hover:text-slate-300 transition-transform flex-shrink-0']" />
        </PopoverButton>

        <!-- Icon rail: what the card would say, on hover or keyboard focus -->
        <span
            v-if="props.slim && !open"
            role="tooltip"
            class="hidden lg:block pointer-events-none absolute top-1/2 -translate-y-1/2 start-full ms-2 z-20 w-max max-w-[16rem] rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-xs leading-relaxed text-slate-200 shadow-lg opacity-0 transition-opacity peer-hover:opacity-100 peer-focus-visible:opacity-100"
            data-context-tooltip
        >
            <span v-for="(line, i) in details" :key="i" :class="i ? '' : 'font-bold text-white'" class="block truncate">{{ line }}</span>
        </span>

        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <PopoverPanel
                focus
                role="dialog"
                aria-labelledby="context-panel-title"
                :class="[
                    props.slim ? 'lg:inset-x-auto lg:top-0 lg:mt-0 lg:start-full lg:ms-2 lg:w-72' : '',
                    'absolute inset-x-0 top-full mt-2 z-30 max-h-[calc(100vh_-_9rem)] overflow-y-auto rounded-2xl bg-white border border-slate-200 shadow-xl text-slate-700'
                ]"
                data-context-panel
            >
                <form @submit.prevent="apply" class="p-4 space-y-3 text-sm">
                    <p id="context-panel-title" class="font-bold text-slate-800">{{ $t('ctx.title') }}</p>

                    <div>
                        <p class="block text-[11px] font-bold text-slate-500 mb-1">{{ $t('ctx.company') }}</p>
                        <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2" data-context-company>
                            <BuildingStorefrontIcon class="w-4 h-4 text-indigo-600 flex-shrink-0" />
                            <span class="truncate font-semibold" :title="company.name">{{ company.name }}</span>
                        </div>
                    </div>

                    <div v-if="branches?.items.length">
                        <label :for="canPickBranch ? 'context-branch' : undefined" class="block text-[11px] font-bold text-slate-500 mb-1">{{ $t('ctx.branch') }}</label>
                        <select v-if="canPickBranch" id="context-branch" v-model="branchChoice" :disabled="applying"
                            class="w-full rounded-xl border-slate-200 text-sm py-2 ps-3 pe-10 focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60" data-context-branch>
                            <option v-if="branches.can_all" value="all">{{ $t('ctx.all_branches_unified') }}</option>
                            <option v-for="b in branches.items" :key="b.id" :value="b.id">{{ branchLabel(b) }}</option>
                        </select>
                        <p v-else class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 truncate" data-context-branch-fixed>{{ activeBranch?.name }}</p>
                        <p v-if="supportMode && canPickBranch && branchChoice === 'all'" class="text-[11px] text-amber-700 mt-1" data-context-support-hint>{{ $t('ctx.support_saves_to_main') }}</p>
                    </div>

                    <div v-if="years?.items.length">
                        <label :for="canPickYear ? 'context-year' : undefined" class="block text-[11px] font-bold text-slate-500 mb-1">{{ $t('ctx.year') }}</label>
                        <select v-if="canPickYear" id="context-year" v-model="yearChoice" :disabled="applying"
                            class="w-full rounded-xl border-slate-200 text-sm py-2 ps-3 pe-10 focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-60" data-context-year>
                            <option v-for="y in years.items" :key="y.id" :value="y.id">{{ y.name }}</option>
                        </select>
                        <p v-else class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2" data-context-year-fixed>{{ activeYear?.name }}</p>
                    </div>

                    <p v-if="error" role="alert" class="text-xs font-semibold text-rose-600" data-context-error>{{ error }}</p>

                    <button v-if="canPickBranch || canPickYear" type="submit" :disabled="!changed || applying"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2.5 transition-colors disabled:opacity-40 disabled:cursor-not-allowed" data-context-apply>
                        <ArrowPathIcon v-if="applying" class="w-4 h-4 animate-spin" />
                        <CheckIcon v-else class="w-4 h-4" />
                        {{ applying ? $t('ctx.applying') : $t('ctx.apply') }}
                    </button>
                    <p v-else class="text-[11px] text-slate-400" data-context-fixed>{{ $t('ctx.none_available') }}</p>
                </form>
            </PopoverPanel>
        </transition>
    </Popover>
</template>
