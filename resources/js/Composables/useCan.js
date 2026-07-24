import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Capability helper backed by the shared `auth.can` Inertia prop (see HandleInertiaRequests).
 *
 *   const { can, user } = useCan();
 *   can('patients.view')   // -> boolean
 *   user.value             // -> { id, member_name, full_name, position } | null
 *
 * Purely cosmetic: it drives what the nav shows. The server-side `cap:` middleware is the real
 * gate. Defensive by design — a missing `auth` prop resolves to "no capabilities" / null user.
 */
export function useCan() {
    const can = (key) => {
        const caps = usePage()?.props?.auth?.can;

        return Array.isArray(caps) && caps.includes(key);
    };

    const user = computed(() => usePage()?.props?.auth?.user ?? null);

    return { can, user };
}
