<script setup>
import { useI18n } from 'vue-i18n';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Field from '@/Components/UI/Field.vue';
const { t } = useI18n();

const props = defineProps({ repo: String });
const page = usePage();
const kinds = computed(() => ({ feature: t('x_featurerequests_index.new_feature'), improvement: t('x_featurerequests_index.improvement_to_an_existing_featu') }));
const form = reactive({ kind: 'feature', area: '', title: '', description: '' });
const input = 'w-full rounded-xl border-slate-200 text-xs';
const issues = `https://github.com/${props.repo}/issues`;

// Opens GitHub's "new issue" page pre-filled; the user reviews and submits it there.
const send = () => {
    const body = [
        t('x_featurerequests_index.type_n', { n0: kinds.value[form.kind] }),
        form.area ? t('x_featurerequests_index.screen_or_section_n', { n0: form.area }) : '',
        t('x_featurerequests_index.diwan_version_n', { n0: page.props.brand?.version }),
        '',
        form.description,
    ].filter((l, i) => l !== '' || i > 0).join('\n');
    const query = new URLSearchParams({ title: form.title, body, labels: form.kind === 'feature' ? 'enhancement' : 'improvement' });
    window.open(`${issues}/new?${query}`, '_blank', 'noopener');
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('x_featurerequests_index.feature_requests')" />
        <PageHeader :title="$t('x_featurerequests_index.request_a_feature_or_improvement')" :subtitle="$t('x_featurerequests_index.your_request_reaches_the_system')" :crumbs="[{ label: $t('x_featurerequests_index.updates') }, { label: $t('x_featurerequests_index.feature_requests') }]">
            <template #actions>
                <Link :href="route('whats-new.index')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700">{{ $t('x_featurerequests_index.highlights') }}</Link>
                <a :href="issues" target="_blank" rel="noopener" class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700">{{ $t('x_featurerequests_index.open_requests_and_voting') }}</a>
            </template>
        </PageHeader>
        <form @submit.prevent="send" class="bg-white border border-slate-200 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <Field :label="$t('x_featurerequests_index.request_type')">
                <select v-model="form.kind" :class="input"><option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option></select>
            </Field>
            <Field :label="$t('x_featurerequests_index.screen_or_section')" :hint="$t('x_featurerequests_index.e_g_sales_invoices_payroll')"><input v-model="form.area" :class="input" /></Field>
            <Field :label="$t('x_featurerequests_index.request_title')" class="md:col-span-2"><input v-model="form.title" required maxlength="200" :class="input" /></Field>
            <Field :label="$t('x_featurerequests_index.description')" :hint="$t('x_featurerequests_index.what_do_you_want_why_and_how_do')" class="md:col-span-2"><textarea v-model="form.description" required rows="6" :class="input"></textarea></Field>
            <p class="md:col-span-2 text-slate-500">{{ $t('x_featurerequests_index.on_sending_a_github_page_opens_w') }}</p>
            <div class="md:col-span-2 pt-2 border-t border-slate-100">
                <button type="submit" class="px-5 py-2 rounded-xl bg-indigo-600 text-white font-bold">{{ $t('x_featurerequests_index.send_to_the_developer_via_github') }}</button>
            </div>
        </form>
    </AppLayout>
</template>
