<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);

const navItems = [
    { name: 'Dashboard', route: 'dashboard', active: 'dashboard', soon: false },
    { name: 'Scraper', route: null, soon: true },
    { name: 'IA', route: null, soon: true },
    { name: 'Videos', route: 'videos.index', active: 'videos.*', soon: false },
    { name: 'Historial', route: 'videos.index', active: null, soon: false },
    { name: 'Configuración', route: 'settings.index', active: 'settings.*', soon: false },
];
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <div class="flex min-h-screen">
            <aside class="w-64 shrink-0 border-r border-slate-800 bg-slate-900/80 p-6">
                <div class="mb-8">
                    <p class="text-xs uppercase tracking-[0.2em] text-violet-400">Esquina AI</p>
                    <h1 class="mt-1 text-xl font-semibold">Publicador</h1>
                </div>

                <nav class="space-y-1">
                    <template v-for="item in navItems" :key="item.name">
                        <Link
                            v-if="item.route && !item.soon"
                            :href="route(item.route)"
                            class="block rounded-lg px-3 py-2 text-sm transition"
                            :class="route().current(item.active || item.route)
                                ? 'bg-violet-600/20 text-violet-200'
                                : 'text-slate-400 hover:bg-slate-800 hover:text-white'"
                        >
                            {{ item.name }}
                        </Link>
                        <span
                            v-else
                            class="block rounded-lg px-3 py-2 text-sm text-slate-600"
                        >
                            {{ item.name }}
                            <span v-if="item.soon" class="ml-1 text-xs text-slate-500">(pronto)</span>
                        </span>
                    </template>
                </nav>

                <div class="mt-auto pt-10 text-sm text-slate-500">
                    <p v-if="user">{{ user.name }}</p>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="mt-2 text-slate-400 hover:text-white"
                    >
                        Cerrar sesión
                    </Link>
                </div>
            </aside>

            <main class="flex-1 p-8">
                <FlashMessage />
                <slot />
            </main>
        </div>
    </div>
</template>
