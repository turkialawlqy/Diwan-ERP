import i18n from '@/i18n';
import dictEn from '@/locales/dict.en.json';
import { canRoute } from '@/permissions';
import { pageSearchFor as cachedPageSearch } from '@/pageIndex';

// Loaded on demand by GlobalSearch.vue (fetched when the browser is idle), so the page index costs nothing on
// page load. Every Inertia response brings fresh routePermissions / auth / features: after a change of company,
// user, branch, role, permission, module or language the next search uses an index rebuilt for it (pageIndex.js).

const textIn = (key, locale) => (key && i18n.global.te(key, locale) ? i18n.global.t(key, {}, { locale }) : '');

/**
 * The page search of the user here, from Inertia's shared props (usePage()). Call it inside a computed:
 * it reads the reactive props and the language, so the computed follows them.
 */
export function pageSearchFor(page) {
    const props = page.props;
    const locale = i18n.global.locale.value === 'en' ? 'en' : 'ar';
    const other = locale === 'ar' ? 'en' : 'ar';
    const user = props.auth?.user || null;
    const features = props.features || [];
    const routes = props.routePermissions || {};
    const ziggy = typeof route === 'function' ? route() : null;

    const env = {
        t: (key) => textIn(key, locale),
        tOther: (key) => textIn(key, other),
        plain: (text) => (locale === 'en' ? dictEn[text] || text : text),
        plainOther: (text) => (locale === 'en' ? text : dictEn[text] || ''),
        canRoute,
        hasFeature: (key) => features.includes(key),
        routeExists: (name) => !!ziggy?.has(name),
        urlFor: (name) => route(name),
        permissionOf: (name) => routes[name] ?? null,
        signedIn: !!user,
        superAdmin: !!user?.is_super_admin,
        separator: locale === 'ar' ? '، ' : ', ',
    };

    return cachedPageSearch(env, [locale, props.tenant?.id ?? '', user?.id ?? ''].join('|'));
}
