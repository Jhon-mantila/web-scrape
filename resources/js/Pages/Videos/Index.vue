<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    videos: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout>
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-semibold">Videos</h2>
                <p class="mt-1 text-slate-400">Sube y publica en redes sociales.</p>
            </div>
            <Link
                :href="route('videos.create')"
                class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500"
            >
                + Subir video
            </Link>
        </div>

        <div v-if="videos.length === 0" class="rounded-2xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
            No hay videos todavía. Sube el primero.
        </div>

        <div v-else class="grid gap-4">
            <article
                v-for="video in videos"
                :key="video.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="flex gap-5">
                    <img
                        :src="video.thumbnail_url"
                        :alt="video.title"
                        class="h-28 w-44 rounded-xl object-cover"
                    />
                    <div class="flex-1">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold">{{ video.title }}</h3>
                                <p class="mt-1 text-sm text-emerald-400">Video subido ✅</p>
                            </div>
                            <div class="flex gap-2">
                                <Link
                                    :href="route('videos.show', video.id)"
                                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800"
                                >
                                    Revisar
                                </Link>
                            </div>
                        </div>

                        <ul class="mt-4 space-y-1 text-sm">
                            <li
                                v-for="pub in video.publications"
                                :key="pub.id"
                                class="flex items-center gap-2 text-slate-300"
                            >
                                <span class="w-40">{{ pub.platform_label }}</span>
                                <span>{{ pub.status_icon }}</span>
                                <span class="text-slate-500">{{ pub.status_label }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
