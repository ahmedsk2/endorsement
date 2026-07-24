<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useCan } from '../Composables/useCan.js';

defineProps({
    // Rendered in the header <h1> and the document <title>.
    title: { type: String, default: '' },
});

const { can, user } = useCan();
const page = usePage();

// Cosmetic active-link highlight. The real gate is the server-side `cap:` middleware.
const isActive = (href) => {
    const url = page.props ? page.url : '';

    return url === href || (typeof url === 'string' && url.startsWith(href + '/'));
};

// The chooser is "active" only on the exact URL — unit pages highlight their own entry.
const isExactly = (href) => (page.props ? page.url : '') === href;

// The four first-class units. Codes are the routing identity; the bar class carries
// each unit's channel hue so the sidebar can be scanned by edge colour alone.
const units = [
    { code: 'picu', label: 'PICU', bar: 'channel-bar-picu' },
    { code: 'nicu', label: 'NICU', bar: 'channel-bar-nicu' },
    { code: 'scbu', label: 'SCBU', bar: 'channel-bar-scbu' },
    { code: 'ward', label: 'Ward', bar: 'channel-bar-ward' },
];

// Admin section is visible with ANY admin-surface capability. Nav visibility never keys off
// role numbers — only capability keys.
const canAdmin = computed(() => can('access.manage') || can('users.manage'));

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

        <!-- Sidebar / role-gated navigation -->
        <aside class="w-full shrink-0 border-b border-line bg-ground-deep lg:min-h-screen lg:w-64 lg:border-b-0 lg:border-r">
            <div class="flex h-16 items-center gap-2 px-5 border-b border-line">
                <span class="grid h-8 w-8 place-items-center rounded-md bg-channel text-sm font-bold text-white">PE</span>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-ink">Paediatric Endorsement</div>
                    <div class="channel-tag">Qatif Central</div>
                </div>
            </div>

            <div v-if="user" class="flex items-center gap-3 px-5 py-4 border-b border-line">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-channel-ink text-xs font-semibold text-white">{{ initials }}</span>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-sm font-semibold capitalize text-ink">{{ user.full_name || user.member_name }}</div>
                    <div class="flex items-center gap-2">
                        <Link v-if="can('profile.manage')" href="/profile" class="text-xs font-medium text-channel-ink hover:underline">Profile</Link>
                        <Link href="/user/two-factor" class="text-xs font-medium text-channel-ink hover:underline">Security &amp; 2FA</Link>
                    </div>
                </div>
            </div>

            <nav aria-label="Primary" class="space-y-0.5 p-3">
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
                        {{ unit.label }} Endorsement
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
                    <Link v-if="can('users.manage')" href="/admin/users"
                          :class="navClass(isActive('/admin/users'))">
                        Users
                    </Link>
                    <Link v-if="can('access.manage')" href="/admin/access-control"
                          :class="navClass(isActive('/admin/access-control'))">
                        Access Control
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
