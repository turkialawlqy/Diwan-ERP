import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import navigation from '@/navigation';
import { canRoute } from '@/permissions';

/** A country-specific item shows only where the branch being worked in has that feature on. */
export const inCountry = (item, features) => !item.feature || (features || []).includes(item.feature);

/** The navigation groups with only the items this user may open here; groups left empty are dropped. */
export function useVisibleNav() {
    const page = usePage();
    return computed(() => navigation
        .map((item) => item.children
            ? { ...item, children: item.children.filter((child) => canRoute(child.route) && inCountry(child, page.props.features)) }
            : item)
        .filter((item) => item.children ? item.children.length > 0 : canRoute(item.route)));
}
