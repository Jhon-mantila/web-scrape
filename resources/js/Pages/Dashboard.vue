<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            total_videos: 0,
            pending: 0,
            scheduled: 0,
            published: 0,
            failed: 0,
            publishing: 0,
            unavailable: 0,
        }),
    },
    article_stats: {
        type: Object,
        default: () => ({
            total_articles: 0,
            published: 0,
            scheduled: 0,
            failed: 0,
            publishing: 0,
            pending_slots: 0,
        }),
    },
    videos: {
        type: Array,
        default: () => [],
    },
});

const videoStatCards = computed(() => [
    { key: 'total_videos', label: 'Videos subidos', value: props.stats.total_videos, class: 'text-white' },
    { key: 'pending', label: 'Pendientes', value: props.stats.pending, class: 'text-amber-300' },
    { key: 'scheduled', label: 'Programados', value: props.stats.scheduled, class: 'text-sky-300' },
    { key: 'published', label: 'Publicados', value: props.stats.published, class: 'text-emerald-300' },
    { key: 'failed', label: 'Con error', value: props.stats.failed, class: 'text-red-400' },
    { key: 'publishing', label: 'Publicando…', value: props.stats.publishing, class: 'text-violet-300' },
]);

const articleStatCards = computed(() => [
    { key: 'total_articles', label: 'Artículos en BD', value: props.article_stats.total_articles, class: 'text-white' },
    { key: 'pending_slots', label: 'Pend. en redes', value: props.article_stats.pending_slots, class: 'text-amber-300' },
    { key: 'scheduled', label: 'Programados', value: props.article_stats.scheduled, class: 'text-sky-300' },
    { key: 'published', label: 'Publicados', value: props.article_stats.published, class: 'text-emerald-300' },
    { key: 'failed', label: 'Con error', value: props.article_stats.failed, class: 'text-red-400' },
    { key: 'publishing', label: 'Publicando…', value: props.article_stats.publishing, class: 'text-violet-300' },
]);

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString('es-CO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function statusBadgeClass(status) {
    return {
        published: 'border-emerald-900/50 bg-emerald-950/40 text-emerald-300',
        scheduled: 'border-sky-900/50 bg-sky-950/40 text-sky-300',
        failed: 'border-red-900/50 bg-red-950/40 text-red-300',
        publishing: 'border-violet-900/50 bg-violet-950/40 text-violet-300',
        unavailable: 'border-slate-700 bg-slate-900/60 text-slate-500',
    }[status] ?? 'border-amber-900/50 bg-amber-950/40 text-amber-300';
}

function isYoutubeOrFacebook(platform) {
    return platform === 'youtube' || platform.startsWith('facebook_');
}

function publicationDateLine(pub) {
    if (pub.status === 'scheduled' && pub.scheduled_at) {
        return `Programado: ${formatDate(pub.scheduled_at)}`;
    }

    if (pub.status === 'published' && pub.published_at) {
        return `Publicado: ${formatDate(pub.published_at)}`;
    }

    if (pub.scheduled_at) {
        return `Programado: ${formatDate(pub.scheduled_at)}`;
    }

    if (pub.published_at) {
        return `Publicado: ${formatDate(pub.published_at)}`;
    }

    return null;
}

function schedulePublications(video) {
    return video.publications.filter(
        (pub) => isYoutubeOrFacebook(pub.platform) && publicationDateLine(pub),
    );
}
</script>

<template>
    <AppLayout>
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold">Dashboard</h2>
                <p class="mt-1 text-slate-400">Resumen de videos y artículos WordPress.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('articles.index')"
                    class="rounded-lg border border-slate-600 px-4 py-2 text-sm hover:bg-slate-800"
                >
                    Artículos
                </Link>
                <Link
                    :href="route('videos.create')"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500"
                >
                    + Subir video
                </Link>
            </div>
        </div>

        <h3 class="mb-3 text-sm font-medium text-slate-400">Videos</h3>
        <div class="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="card in videoStatCards"
                :key="card.key"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 px-4 py-4"
            >
                <p class="text-xs text-slate-400">{{ card.label }}</p>
                <p class="mt-1 text-2xl font-semibold" :class="card.class">{{ card.value }}</p>
            </div>
        </div>

        <h3 class="mb-3 text-sm font-medium text-slate-400">Artículos WordPress</h3>
        <div class="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="card in articleStatCards"
                :key="`article-${card.key}`"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 px-4 py-4"
            >
                <p class="text-xs text-slate-400">{{ card.label }}</p>
                <p class="mt-1 text-2xl font-semibold" :class="card.class">{{ card.value }}</p>
            </div>
        </div>

        <div v-if="videos.length === 0" class="rounded-2xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
            No hay videos todavía.
            <Link :href="route('videos.create')" class="ml-1 text-violet-400 hover:underline">Sube el primero</Link>
        </div>

        <div v-else class="space-y-3">
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-medium">Videos recientes</h3>
                <Link :href="route('videos.index')" class="text-sm text-slate-400 hover:text-white">Ver todos →</Link>
            </div>

            <article
                v-for="video in videos"
                :key="video.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
                :class="video.has_action_needed ? 'ring-1 ring-amber-500/30' : ''"
            >
                <div class="flex flex-wrap gap-4">
                    <img
                        v-if="video.thumbnail_url"
                        :src="video.thumbnail_url"
                        :alt="video.title"
                        class="h-20 w-32 rounded-xl object-cover"
                    />
                    <div
                        v-else
                        class="flex h-20 w-32 items-center justify-center rounded-xl border border-dashed border-slate-700 bg-slate-900/80 text-xs text-slate-500"
                    >
                        Sin miniatura
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="font-semibold">{{ video.title }}</h4>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Subido: {{ formatDate(video.created_at) }}
                                </p>
                            </div>
                            <Link
                                :href="route('videos.show', video.id)"
                                class="shrink-0 rounded-lg border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800"
                            >
                                Revisar
                            </Link>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="pub in video.publications"
                                :key="pub.id"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs"
                                :class="statusBadgeClass(pub.status)"
                            >
                                <span>{{ pub.status_icon }}</span>
                                <span class="font-medium">{{ pub.platform_label }}</span>
                                <span class="opacity-80">{{ pub.status_label }}</span>
                            </span>
                        </div>
                        <div
                            v-if="schedulePublications(video).length > 0"
                            class="mt-3 space-y-1 border-t border-slate-800 pt-3"
                        >
                            <p
                                v-for="pub in schedulePublications(video)"
                                :key="`date-${pub.id}`"
                                class="text-xs"
                                :class="pub.status === 'scheduled' ? 'text-sky-300' : 'text-slate-400'"
                            >
                                <span class="font-medium text-slate-300">{{ pub.platform_label }}</span>
                                · {{ publicationDateLine(pub) }}
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
