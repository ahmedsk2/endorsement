<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useCan } from '../Composables/useCan.js';

defineProps({
    // Rendered in the header <h1> and the document <title>.
    title: { type: String, default: '' },
});

const { can, user } = useCan();
const page = usePage();

/*
 * The phone menu.
 *
 * `isDesktop` is tracked rather than left to CSS because the nav is COLLAPSED by default:
 * a plain `lg:block` would still leave it hidden on desktop until something toggled it, and
 * `v-show` has to answer to one source of truth. 64rem is the `lg` breakpoint — the two must
 * stay in step, which is why the number is written down here and nowhere else.
 */
const DESKTOP = '(min-width: 64rem)';

// Guarded: matchMedia is absent in the component test environment, and would be absent in
// any non-browser render. Defaulting to "desktop" there means the nav is SHOWN — failing
// towards a visible menu rather than an invisible one, since a hidden nav with no working
// toggle would be a page with no way out.
const desktopQuery = () =>
    (typeof window !== 'undefined' && typeof window.matchMedia === 'function')
        ? window.matchMedia(DESKTOP)
        : null;

const isDesktop = ref(desktopQuery()?.matches ?? true);
const navOpen = ref(false);

let mq = null;
const syncDesktop = (e) => { isDesktop.value = e.matches; };

onMounted(() => {
    mq = desktopQuery();
    mq?.addEventListener('change', syncDesktop);
});

onUnmounted(() => mq?.removeEventListener('change', syncDesktop));

// Following a link on a phone must close the menu, or every navigation lands with the nav
// covering the page the user just asked for.
watch(() => page.url, () => { navOpen.value = false; });

// Cosmetic active-link highlight. The real gate is the server-side `cap:` middleware.
const isActive = (href) => {
    const url = page.props ? page.url : '';

    return url === href || (typeof url === 'string' && url.startsWith(href + '/'));
};

// The chooser is "active" only on the exact URL — unit pages highlight their own entry.
const isExactly = (href) => (page.props ? page.url : '') === href;

// The unit list comes from the server (`nav.units`, built by Unit::navList()) rather than a
// literal array here, so creating, renaming, recolouring, reordering or retiring a unit on
// Admin -> Structure -> Units is reflected without a frontend change. Codes are the routing
// identity; the bar class carries each unit's channel hue so the sidebar can be scanned by
// edge colour alone.
const units = computed(() => page.props.nav?.units ?? []);

// Admin section is visible with ANY admin-surface capability — including the Chief
// Resident's scoped users.manage_residents. Nav visibility never keys off role
// numbers — only capability keys.
const canAdmin = computed(() => can('access.manage') || can('users.manage')
    || can('users.manage_residents') || can('settings.manage') || can('structure.manage'));

const initials = computed(() => (user.value?.full_name || user.value?.member_name || '')
    .split(' ')
    .map((p) => p.charAt(0))
    .join('')
    .slice(0, 2)
    .toUpperCase());

const logout = () => router.post('/logout');

// Nav item styling. The active item carries the channel-bar signature — the 3px left edge
// encodes "you are here" the way a monitor's channel strip encodes a trace.
const navClass = (active) => [
    'block rounded-md px-3 py-2 text-sm font-medium transition',
    active
        ? 'channel-bar bg-channel-soft text-channel-ink'
        : 'text-body hover:bg-ground',
];
</script>

<template>
    <Head :title="title" />

    <div lang="en" class="min-h-screen bg-ground text-ink lg:flex">
        <!-- Keyboard skip link — visually hidden until focused (styled in resources/css). -->
        <a class="skip-link sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-channel focus:px-3 focus:py-2 focus:text-white"
           href="#main-content">
            Skip to content
        </a>

        <!--
          Sidebar / role-gated navigation.

          STICKY FROM `lg` UP, and only there. On a desktop the census scrolls for pages, and
          a nav that scrolls away with it means reaching for another unit is a scroll back to
          the top first. `h-screen` + `overflow-y-auto` rather than `min-h-screen`: pinned, it
          must be able to scroll its own contents on a short viewport, or the Administration
          links at the bottom become unreachable on a laptop.

          Left alone below `lg`, where it is a stacked block at the top of the document —
          pinning that would eat a phone's screen for the one page a ward actually reads.
        -->
        <aside class="w-full shrink-0 border-b border-line bg-ground-deep lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:overflow-y-auto lg:border-b-0 lg:border-r">
            <div class="flex h-16 items-center gap-2 px-5 border-b border-line">
                <span class="grid h-8 w-8 place-items-center rounded-md bg-channel text-sm font-bold text-white">PE</span>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-ink">Paediatric Endorsement</div>
                    <div class="channel-tag">Qatif Central</div>
                </div>

                <!--
                  Phone only. The nav is a full-width stacked block below lg, and it ate
                  roughly 300px before any clinical content appeared — on a 667px screen
                  that is nearly half the page, on every page, forever. Collapsed by
                  default; the current page's name is on the button so nothing is lost by
                  it being shut.
                -->
                <button type="button" class="ml-auto flex min-h-11 items-center gap-2 rounded-md border border-line px-3 text-sm font-semibold text-body lg:hidden"
                        data-testid="nav-toggle" :aria-expanded="navOpen ? 'true' : 'false'"
                        aria-controls="primary-nav" @click="navOpen = !navOpen">
                    <span aria-hidden="true" class="readout">{{ navOpen ? '✕' : '☰' }}</span>
                    <span class="sr-only">{{ navOpen ? 'Close menu' : 'Open menu' }}</span>
                    <span aria-hidden="true">Menu</span>
                </button>
            </div>

            <div v-if="user" v-show="navOpen || isDesktop" class="flex items-center gap-3 px-5 py-4 border-b border-line">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-channel-ink text-xs font-semibold text-white">{{ initials }}</span>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-sm font-semibold capitalize text-ink">{{ user.full_name || user.member_name }}</div>
                    <div class="flex items-center gap-2">
                        <Link v-if="can('profile.manage')" href="/profile" class="text-xs font-medium text-channel-ink hover:underline">Profile</Link>
                        <Link href="/user/two-factor" class="text-xs font-medium text-channel-ink hover:underline">Security &amp; 2FA</Link>
                    </div>
                </div>
            </div>

            <nav id="primary-nav" v-show="navOpen || isDesktop" aria-label="Primary" class="space-y-0.5 p-3">
                <!-- The chooser: one card per unit with today's status. -->
                <Link v-if="can('endorsement.view')" href="/endorsement"
                      :aria-current="isExactly('/endorsement') ? 'page' : undefined"
                      :class="navClass(isExactly('/endorsement'))">
                    All units
                </Link>

                <!-- One entry per unit; the active entry carries the UNIT's hue, not the default. -->
                <template v-if="can('endorsement.view')">
                    <Link v-for="unit in units" :key="unit.code"
                          :href="`/endorsement/${unit.code}`"
                          :aria-current="isActive(`/endorsement/${unit.code}`) ? 'page' : undefined"
                          :class="[
                              'block rounded-md px-3 py-2 text-sm font-medium transition',
                              isActive(`/endorsement/${unit.code}`)
                                  ? `channel-bar ${unit.bar} bg-channel-soft text-channel-ink`
                                  : 'text-body hover:bg-ground',
                          ]">
                        {{ unit.label }}
                    </Link>
                </template>

                <!-- The missed-days aggregate — its own narrow capability. -->
                <Link v-if="can('endorsement.compliance')" href="/endorsement/compliance"
                      :aria-current="isExactly('/endorsement/compliance') ? 'page' : undefined"
                      :class="navClass(isExactly('/endorsement/compliance'))">
                    Missed days
                </Link>

                <!-- Administration -->
                <template v-if="canAdmin">
                    <p class="channel-tag px-3 pb-1 pt-4">Administration</p>
                    <Link v-if="can('users.manage') || can('users.manage_residents')" href="/admin/users"
                          :class="navClass(isActive('/admin/users'))">
                        {{ can('users.manage') ? 'Users' : 'Residents' }}
                    </Link>
                    <Link v-if="can('access.manage')" href="/admin/access-control"
                          :class="navClass(isActive('/admin/access-control'))">
                        Access Control
                    </Link>
                    <Link v-if="can('structure.manage')" href="/admin/structure/units"
                          :class="navClass(isActive('/admin/structure/units'))">
                        Units
                    </Link>
                    <Link v-if="can('structure.manage')" href="/admin/structure/levels"
                          :class="navClass(isActive('/admin/structure/levels'))">
                        Levels
                    </Link>
                    <Link v-if="can('structure.manage')" href="/admin/structure/calendar"
                          :class="navClass(isActive('/admin/structure/calendar'))">
                        Calendar
                    </Link>
                    <Link v-if="can('settings.manage')" href="/admin/settings"
                          :class="navClass(isActive('/admin/settings'))">
                        Settings
                    </Link>
                </template>
            </nav>
        </aside>

        <!-- Main column -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center gap-4 border-b border-line bg-panel px-5">
                <h1 class="min-w-0 flex-1 truncate text-xl font-semibold text-ink">{{ title }}</h1>

                <div v-if="user" class="hidden text-right leading-tight sm:block">
                    <div class="text-sm font-semibold capitalize text-ink">{{ user.full_name || user.member_name }}</div>
                    <div class="channel-tag">Signed in</div>
                </div>
                <button type="button" data-testid="logout" @click="logout"
                        class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-body transition hover:bg-ground">
                    Sign out
                </button>
            </header>

            <!-- Flash banners (shared `flash` prop). -->
            <div v-if="page.props.flash?.status" role="status" class="channel-bar-ok channel-bar mx-5 mt-4 rounded-md bg-ok-soft px-4 py-2 text-sm text-ok">
                {{ page.props.flash.status }}
            </div>
            <div v-if="page.props.flash?.error" role="alert" class="channel-bar-critical channel-bar mx-5 mt-4 rounded-md bg-critical-soft px-4 py-2 text-sm text-critical">
                {{ page.props.flash.error }}
            </div>

            <main id="main-content" tabindex="-1" class="flex-1 p-5 lg:p-7">
                <slot />
            </main>

            <footer class="border-t border-line px-5 py-3 text-center text-xs text-muted">
                Paediatric Endorsement — Qatif Central Hospital
            </footer>
        </div>
    </div>
</template>
