<script setup>
import { Link } from '@inertiajs/vue3';

function linkClass(active) {
    return active
        ? 'border-violet-500 bg-violet-950/40 text-violet-200'
        : 'border-slate-700 text-slate-400 hover:border-slate-600 hover:text-slate-200';
}
</script>

<template>
    <nav class="mb-8 flex flex-wrap gap-2 border-b border-slate-800 pb-4">
        <Link
            :href="route('settings.index')"
            class="rounded-lg border px-3 py-1.5 text-sm transition"
            :class="linkClass(route().current('settings.index'))"
        >
            Cuentas y OAuth
        </Link>
        <Link
            :href="route('settings.scheduler.index')"
            class="rounded-lg border px-3 py-1.5 text-sm transition"
            :class="linkClass(route().current('settings.scheduler.*'))"
        >
            Planificador de tareas
        </Link>
    </nav>
</template>
