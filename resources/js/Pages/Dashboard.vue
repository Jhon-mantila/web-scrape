<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    publications: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold">Dashboard</h2>
            <p class="mt-1 text-slate-400">Resumen de publicaciones de video.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
                <p class="text-sm text-slate-400">Videos subidos</p>
                <p class="mt-2 text-3xl font-semibold">{{ publications.length ? '—' : '0' }}</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
                <p class="text-sm text-slate-400">Pendientes</p>
                <p class="mt-2 text-3xl font-semibold text-amber-300">Ver Videos</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
                <p class="text-sm text-slate-400">Acción rápida</p>
                <Link
                    :href="route('videos.create')"
                    class="mt-3 inline-flex rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500"
                >
                    + Subir video
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
