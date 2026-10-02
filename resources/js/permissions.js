import { usePage } from '@inertiajs/vue3';

/**
 * Permission helpers mirroring the backend (config/erp_permissions.php).
 * The backend is always the authority; these only hide UI the user can't use.
 */
function auth() {
    return usePage().props.auth?.user ?? null;
}

export function can(permission) {
    const user = auth();
    if (!user) return false;
    if (user.is_owner) return true;
    return (user.permissions ?? []).includes(permission);
}

export function canAny(permissions) {
    return permissions.some((p) => can(p));
}

/**
 * Whether the user may open the route with this name (uses the same
 * route => permission map the server enforces).
 */
export function canRoute(name) {
    const user = auth();
    if (!user) return false;
    const required = usePage().props.routePermissions?.[name];
    if (required === undefined) return false;
    if (required === '@authenticated' || user.is_owner) return true;
    return canAny(Array.isArray(required) ? required : [required]);
}

export const PermissionsPlugin = {
    install(app) {
        app.config.globalProperties.$can = can;
        app.config.globalProperties.$canAny = canAny;
        app.config.globalProperties.$canRoute = canRoute;
    },
};
