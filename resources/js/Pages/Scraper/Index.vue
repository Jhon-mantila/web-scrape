<script setup>
import axios from 'axios';
import { Link, router, useForm } from '@inertiajs/vue3';
import { syncBackgroundRunAfterInertiaStart } from '@/support/backgroundRun.js';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({
            q: '',
            pending_wordpress: false,
            pending_ai: false,
            scheduled_wp: false,
            failed: false,
            per_page: 15,
        }),
    },
    list: {
        type: Object,
        default: () => ({
            per_page_options: [10, 15, 25, 50],
        }),
    },
    stats: {
        type: Object,
        default: () => ({
            total_news: 0,
            with_details: 0,
            with_ai: 0,
            sent_wordpress: 0,
            pending_wordpress: 0,
            failed_details: 0,
            failed_ai: 0,
            wp_scheduled: 0,
            wp_published: 0,
            wp_drafts: 0,
            wp_legacy: 0,
            next_scheduled_at: null,
            last_scheduled_at: null,
            schedule_buffer_days: null,
        }),
    },
    pipeline: {
        type: Object,
        default: () => ({
            limit: 5,
            limits: { limit: { min: 1, max: 50 } },
            wordpress_url: null,
            wordpress_configured: false,
            schedule: {},
        }),
    },
    news: {
        type: Object,
        default: () => ({ data: [] }),
    },
});

const searchQuery = ref(props.filters.q || '');
let searchTimer = null;

const pipelineForm = useForm({
    limit: props.pipeline.limit ?? 5,
    send_wordpress: false,
    wordpress_only: false,
    mode: 'schedule',
    skip_scrape: false,
    skip_research: false,
    skip_generate: false,
    force: false,
    include_raw_html: true,
});

const syncWpForm = useForm({});
const attachFeaturedForm = useForm({
    limit: 50,
    force: false,
});

const statCards = computed(() => [
    { key: 'total_news', label: 'Noticias', value: props.stats.total_news, class: 'text-white' },
    { key: 'with_details', label: 'Con detalle', value: props.stats.with_details, class: 'text-sky-300' },
    { key: 'with_ai', label: 'Con IA', value: props.stats.with_ai, class: 'text-violet-300' },
    { key: 'pending_wordpress', label: 'Pend. WP', value: props.stats.pending_wordpress, class: 'text-amber-300' },
    { key: 'wp_scheduled', label: 'Programados WP', value: props.stats.wp_scheduled, class: 'text-sky-300' },
    { key: 'sent_wordpress', label: 'Enviados WP', value: props.stats.sent_wordpress, class: 'text-emerald-300' },
    { key: 'failed_details', label: 'Detalle error', value: props.stats.failed_details, class: 'text-red-400' },
    { key: 'failed_ai', label: 'IA error', value: props.stats.failed_ai, class: 'text-red-400' },
]);

const hasActiveListFilters = computed(() => {
    const f = props.filters;

    return Boolean(
        (f.q && String(f.q).trim() !== '')
            || f.pending_wordpress
            || f.pending_ai
            || f.scheduled_wp
            || f.failed,
    );
});

const listPaginationSummary = computed(() => {
    const paginator = props.news;
    const total = paginator.total ?? 0;
    const perPage = paginator.per_page ?? props.filters.per_page ?? 15;
    const currentPage = paginator.current_page ?? 1;
    const lastPage = paginator.last_page ?? 1;
    const from = paginator.from ?? 0;
    const to = paginator.to ?? 0;
    const totalAll = props.stats.total_news ?? total;

    if (total === 0) {
        if (hasActiveListFilters.value) {
            return {
                headline: '0 resultados con los filtros actuales',
                detail: `${totalAll} noticias en total en la base de datos`,
            };
        }

        return {
            headline: '0 noticias',
            detail: null,
        };
    }

    const range = from && to ? `${from}–${to}` : `${paginator.data?.length ?? 0}`;

    if (hasActiveListFilters.value) {
        return {
            headline: `${total} ${total === 1 ? 'resultado filtrado' : 'resultados filtrados'} · mostrando ${range}`,
            detail: `${totalAll} noticias en total · ${perPage} por página · página ${currentPage} de ${lastPage}`,
        };
    }

    return {
        headline: `${totalAll} noticias en total · mostrando ${range}`,
        detail: `${perPage} por página · página ${currentPage} de ${lastPage}`,
    };
});

const scheduleSummary = computed(() => {
    const buffer = props.stats.schedule_buffer_days;

    return {
        next: props.stats.next_scheduled_at,
        last: props.stats.last_scheduled_at,
        bufferText: buffer === null ? null : buffer <= 2 ? `~${buffer} días (convendría programar más)` : `~${buffer} días`,
        bufferClass: buffer !== null && buffer <= 2 ? 'text-amber-300' : 'text-slate-300',
    };
});

const pipelinePresets = [
    {
        key: 'full',
        label: 'Completo',
        description: 'Scrape listado → detalles → imágenes → research → IA (sin enviar a WP)',
        apply: () => {
            pipelineForm.wordpress_only = false;
            pipelineForm.send_wordpress = false;
            pipelineForm.skip_scrape = false;
            pipelineForm.skip_research = false;
            pipelineForm.skip_generate = false;
            pipelineForm.force = false;
        },
    },
    {
        key: 'skip_scrape',
        label: 'Sin scrape listado',
        description: 'Usa noticias ya guardadas (--skip-scrape)',
        apply: () => {
            pipelineForm.wordpress_only = false;
            pipelineForm.send_wordpress = false;
            pipelineForm.skip_scrape = true;
            pipelineForm.skip_research = false;
            pipelineForm.skip_generate = false;
            pipelineForm.force = false;
        },
    },
    {
        key: 'light',
        label: 'Sin FLUX ni SearXNG',
        description: 'Scrape + IA sin extras (--skip-generate --skip-research)',
        apply: () => {
            pipelineForm.wordpress_only = false;
            pipelineForm.send_wordpress = false;
            pipelineForm.skip_scrape = false;
            pipelineForm.skip_research = true;
            pipelineForm.skip_generate = true;
            pipelineForm.force = false;
        },
    },
    {
        key: 'force',
        label: 'Reprocesar',
        description: 'Fuerza detalles, research e IA (--force)',
        apply: () => {
            pipelineForm.wordpress_only = false;
            pipelineForm.send_wordpress = false;
            pipelineForm.skip_scrape = true;
            pipelineForm.skip_research = false;
            pipelineForm.skip_generate = false;
            pipelineForm.force = true;
        },
    },
    {
        key: 'wordpress_only',
        label: 'Solo WordPress',
        description: 'Solo envía artículos IA pendientes a Esquina Anime',
        apply: () => {
            pipelineForm.wordpress_only = true;
            pipelineForm.send_wordpress = true;
            pipelineForm.skip_scrape = true;
            pipelineForm.skip_research = true;
            pipelineForm.skip_generate = true;
            pipelineForm.force = false;
        },
    },
];

const activePreset = ref(null);
const previewOpen = ref(false);
const previewLoading = ref(false);
const previewError = ref('');
const previewData = ref(null);
const previewNewsId = ref(null);
const regeneratingAi = ref(false);
const regenerateIncludeRawHtml = ref(true);

function onEscapeKey(event) {
    if (event.key === 'Escape' && previewOpen.value) {
        closePreview();
    }
}

onMounted(() => {
    document.addEventListener('keydown', onEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', onEscapeKey);
});

const sendsToWordpress = computed(() =>
    pipelineForm.wordpress_only || pipelineForm.send_wordpress,
);

const runLabel = computed(() => {
    if (pipelineForm.processing) {
        if (pipelineForm.wordpress_only) {
            return 'Enviando a WordPress…';
        }

        return pipelineForm.send_wordpress ? 'Ejecutando pipeline + WP…' : 'Ejecutando pipeline…';
    }

    if (pipelineForm.wordpress_only) {
        return 'Enviar a Esquina Anime';
    }

    return pipelineForm.send_wordpress ? 'Ejecutar pipeline + WP' : 'Ejecutar pipeline';
});

const modeHelp = computed(() => {
    if (!sendsToWordpress.value) {
        return 'WordPress desactivado: solo scrape, imágenes, research e IA.';
    }

    if (pipelineForm.mode === 'schedule') {
        const s = props.pipeline.schedule ?? {};
        return `Programa en WP: máx. ${s.max_per_day ?? '?'} posts/día, intervalo ${s.interval ?? '?'}, zona ${s.timezone ?? '?'}`;
    }

    if (pipelineForm.mode === 'publish') {
        return 'Publica inmediatamente en Esquina Anime.';
    }

    return 'Guarda como borrador en Esquina Anime.';
});

const pipelineStarting = ref(false);

const runDisabled = computed(() => {
    if (pipelineForm.processing || pipelineStarting.value) {
        return true;
    }

    return sendsToWordpress.value && !props.pipeline.wordpress_configured;
});

const pipelineCliCommand = computed(() => {
    const limit = Math.max(1, Number(pipelineForm.limit) || 5);

    if (pipelineForm.wordpress_only) {
        return buildArtisanCommand('news:send-wordpress', [
            `--limit=${limit}`,
            `--mode=${pipelineForm.mode}`,
        ]);
    }

    const flags = [`--limit=${limit}`];

    if (pipelineForm.send_wordpress) {
        flags.push(`--mode=${pipelineForm.mode}`);
    } else {
        flags.push('--skip-wordpress');
    }

    if (pipelineForm.skip_scrape) {
        flags.push('--skip-scrape');
    }

    if (pipelineForm.skip_research) {
        flags.push('--skip-research');
    }

    if (pipelineForm.skip_generate) {
        flags.push('--skip-generate');
    }

    if (pipelineForm.force) {
        flags.push('--force');
    }

    if (pipelineForm.include_raw_html) {
        flags.push('--include-raw-html');
    }

    return buildArtisanCommand('news:pipeline', flags);
});

const dockerCliCommand = computed(() => `docker exec -it laravel_app ${pipelineCliCommand.value}`);

const copiedCli = ref(null);
let copiedCliTimer = null;

function buildArtisanCommand(name, flags) {
    return `php artisan ${name} ${flags.join(' ')}`;
}

async function copyCliCommand(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copiedCli.value = key;
        clearTimeout(copiedCliTimer);
        copiedCliTimer = setTimeout(() => {
            copiedCli.value = null;
        }, 2000);
    } catch {
        window.prompt('Copia el comando:', text);
    }
}

function onWordpressOnlyChange(checked) {
    pipelineForm.wordpress_only = checked;

    if (checked) {
        pipelineForm.send_wordpress = true;
    }
}

function onSendWordpressChange(checked) {
    pipelineForm.send_wordpress = checked;

    if (!checked) {
        pipelineForm.wordpress_only = false;
    }
}

function applyFilters(overrides = {}) {
    router.get(
        route('scraper.index'),
        {
            q: searchQuery.value || undefined,
            per_page: props.filters.per_page || undefined,
            pending_wordpress: props.filters.pending_wordpress || undefined,
            pending_ai: props.filters.pending_ai || undefined,
            scheduled_wp: props.filters.scheduled_wp || undefined,
            failed: props.filters.failed || undefined,
            ...overrides,
        },
        { preserveState: true, replace: true },
    );
}

function changeListPerPage(perPage) {
    applyFilters({ per_page: perPage, page: 1 });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ q: searchQuery.value || undefined, page: 1 }), 350);
}

function clearSearch() {
    searchQuery.value = '';
    applyFilters({ q: undefined });
}

function applyPreset(preset) {
    activePreset.value = preset.key;
    preset.apply();
}

function runPipeline() {
    if (runDisabled.value || pipelineStarting.value) {
        return;
    }

    pipelineStarting.value = true;
    pipelineForm.clearErrors();

    router.post(route('scraper.pipeline'), pipelineForm.data(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            void syncBackgroundRunAfterInertiaStart(page);
        },
        onError: () => {
            window.alert('No se pudo iniciar el pipeline.');
        },
        onFinish: () => {
            pipelineStarting.value = false;
        },
    });
}

function syncWordpressStatus() {
    syncWpForm.post(route('scraper.sync-wordpress'), {
        preserveScroll: true,
    });
}

function attachWordpressFeaturedImages() {
    if (
        !window.confirm(
            '¿Subir imágenes locales y asignarlas como destacada en posts WordPress ya enviados? Solo entradas sin imagen destacada en WP (salvo que marques forzar).',
        )
    ) {
        return;
    }

    attachFeaturedForm.post(route('scraper.attach-wordpress-featured-images'), {
        preserveScroll: true,
    });
}

function wpStatusLabel(status) {
    const map = {
        future: 'Programado',
        publish: 'Publicado',
        draft: 'Borrador',
        pending: 'Pendiente',
    };

    return map[status] ?? status ?? 'Enviado';
}

function wpStatusClass(status) {
    const map = {
        future: 'border-sky-600/50 bg-sky-950/40 text-sky-200',
        publish: 'border-emerald-600/50 bg-emerald-950/40 text-emerald-200',
        draft: 'border-slate-600 bg-slate-900/60 text-slate-300',
    };

    return map[status] ?? 'border-emerald-600/50 bg-emerald-950/40 text-emerald-200';
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('es-CO', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function stageBadge(stage) {
    const map = {
        ok: 'border-emerald-600/50 bg-emerald-950/40 text-emerald-200',
        pending: 'border-slate-600 bg-slate-900/60 text-slate-400',
        failed: 'border-red-600/50 bg-red-950/40 text-red-200',
        skip: 'border-slate-700 bg-slate-950/40 text-slate-500',
    };

    return map[stage] ?? map.pending;
}

function detailStage(item) {
    if (!item.detail) {
        return { label: 'Detalle', state: 'pending' };
    }

    if (item.detail.status === 'failed') {
        return { label: 'Detalle', state: 'failed' };
    }

    if (item.detail.status === 'processed') {
        return { label: 'Detalle', state: 'ok' };
    }

    return { label: 'Detalle', state: 'pending' };
}

function imageStage(item) {
    if (!item.detail) {
        return { label: 'Imagen', state: 'skip' };
    }

    return { label: 'Imagen', state: item.detail.has_image ? 'ok' : 'pending' };
}

function researchStage(item) {
    if (!item.detail) {
        return { label: 'Research', state: 'skip' };
    }

    return { label: 'Research', state: item.detail.researched ? 'ok' : 'pending' };
}

function aiStage(item) {
    if (item.status_ia === 'failed') {
        return { label: 'IA', state: 'failed' };
    }

    if (item.ai?.generated_title || item.status_ia === 'processed') {
        return { label: 'IA', state: 'ok' };
    }

    return { label: 'IA', state: 'pending' };
}

function wordpressStage(item) {
    if (!item.ai) {
        return { label: 'WordPress', state: 'skip' };
    }

    if (item.ai.sent_wordpress) {
        return { label: 'WordPress', state: 'ok' };
    }

    return { label: 'WordPress', state: 'pending' };
}

function itemStages(item) {
    return [
        { label: 'Listado', state: 'ok' },
        detailStage(item),
        imageStage(item),
        researchStage(item),
        aiStage(item),
        wordpressStage(item),
    ];
}

function csrfHeaders() {
    return {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    };
}

async function openPreview(item) {
    previewOpen.value = true;
    previewNewsId.value = item.id;
    previewLoading.value = true;
    previewError.value = '';
    previewData.value = null;
    regenerateIncludeRawHtml.value = pipelineForm.include_raw_html;

    try {
        const { data } = await axios.get(route('scraper.preview', item.id));
        previewData.value = data;
    } catch (error) {
        previewError.value = error.response?.data?.message || 'No se pudo cargar la vista previa.';
    } finally {
        previewLoading.value = false;
    }
}

async function regeneratePreviewAi() {
    if (!previewNewsId.value || !previewData.value?.can_regenerate || regeneratingAi.value) {
        return;
    }

    if (!window.confirm('¿Regenerar este artículo con IA? Se sobrescribe el contenido actual (aún no enviado a WordPress).')) {
        return;
    }

    regeneratingAi.value = true;
    previewError.value = '';

    try {
        const { data } = await axios.post(
            route('scraper.regenerate-ai', previewNewsId.value),
            { include_raw_html: regenerateIncludeRawHtml.value },
            { headers: csrfHeaders() },
        );

        previewData.value = data.preview;
    } catch (error) {
        previewError.value = error.response?.data?.message || 'No se pudo regenerar el artículo con IA.';
    } finally {
        regeneratingAi.value = false;
        router.reload({ only: ['news', 'stats'], preserveScroll: true });
    }
}

function closePreview() {
    previewOpen.value = false;
    previewLoading.value = false;
    previewError.value = '';
    previewData.value = null;
    previewNewsId.value = null;
    regeneratingAi.value = false;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-6xl">
            <div class="mb-8">
                <h2 class="text-2xl font-semibold">Scraper → Esquina Anime</h2>
                <p class="mt-1 text-sm text-slate-400">
                    Pipeline de noticias: scrape, IA y envío a WordPress
                    <template v-if="pipeline.wordpress_url">
                        (<span class="text-slate-300">{{ pipeline.wordpress_url }}</span>)
                    </template>
                </p>
            </div>

            <section class="mb-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium text-slate-200">Programación en Esquina Anime</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Estado guardado en BD al enviar. Usa sincronizar para actualizar si ya publicaron en WP.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-50"
                            :disabled="syncWpForm.processing || !pipeline.wordpress_configured"
                            @click="syncWordpressStatus"
                        >
                            {{ syncWpForm.processing ? 'Sincronizando…' : 'Sincronizar estado WP' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-violet-600/60 px-3 py-1.5 text-sm text-violet-200 hover:bg-violet-950/40 disabled:opacity-50"
                            :disabled="attachFeaturedForm.processing || !pipeline.wordpress_configured"
                            @click="attachWordpressFeaturedImages"
                        >
                            {{
                                attachFeaturedForm.processing
                                    ? 'Subiendo imágenes…'
                                    : 'Adjuntar destacadas WP'
                            }}
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-600">
                    Adjuntar destacadas: hasta {{ attachFeaturedForm.limit }} posts sin imagen en WP.
                    CLI:
                    <code class="text-slate-500">news:attach-wordpress-featured-images --all</code>
                </p>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-2.5">
                        <p class="text-xs text-slate-500">Programados</p>
                        <p class="text-xl font-semibold text-sky-300">{{ stats.wp_scheduled }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-2.5">
                        <p class="text-xs text-slate-500">Publicados</p>
                        <p class="text-xl font-semibold text-emerald-300">{{ stats.wp_published }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-2.5">
                        <p class="text-xs text-slate-500">Borradores</p>
                        <p class="text-xl font-semibold text-slate-300">{{ stats.wp_drafts }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-2.5">
                        <p class="text-xs text-slate-500">Cola hasta</p>
                        <p class="text-sm font-semibold" :class="scheduleSummary.bufferClass">
                            {{ scheduleSummary.last ? formatDate(scheduleSummary.last) : '—' }}
                        </p>
                        <p v-if="scheduleSummary.bufferText" class="mt-0.5 text-xs" :class="scheduleSummary.bufferClass">
                            {{ scheduleSummary.bufferText }}
                        </p>
                    </div>
                </div>

                <p v-if="stats.next_scheduled_at" class="mt-3 text-xs text-slate-400">
                    Próximo a publicar: {{ formatDate(stats.next_scheduled_at) }}
                </p>

                <p v-if="stats.wp_legacy > 0" class="mt-3 rounded-lg border border-amber-600/30 bg-amber-950/20 px-3 py-2 text-xs text-amber-200">
                    {{ stats.wp_legacy }} envío(s) antiguo(s) sin ID de WordPress guardado. Los nuevos envíos sí guardan fecha e ID.
                </p>
            </section>

            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                <div
                    v-for="card in statCards"
                    :key="card.key"
                    class="rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5"
                >
                    <p class="text-xs text-slate-500">{{ card.label }}</p>
                    <p class="text-xl font-semibold" :class="card.class">{{ card.value }}</p>
                </div>
            </div>

            <section class="mb-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
                <h3 class="text-sm font-medium text-slate-200">Ejecutar pipeline</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Equivalente a <code class="text-slate-400">php artisan news:pipeline</code> o
                    <code class="text-slate-400">news:send-wordpress</code>. Puede tardar varios minutos.
                </p>

                <div
                    v-if="!pipeline.wordpress_configured && sendsToWordpress"
                    class="mt-3 rounded-lg border border-amber-600/40 bg-amber-950/30 px-3 py-2 text-xs text-amber-200"
                >
                    Para enviar a WordPress configura <code>WORDPRESS_URL</code>, <code>WORDPRESS_USER</code> y <code>WORDPRESS_PASSWORD</code>.
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="preset in pipelinePresets"
                        :key="preset.key"
                        type="button"
                        class="rounded-lg border px-3 py-1.5 text-left text-xs transition"
                        :class="activePreset === preset.key
                            ? 'border-violet-500 bg-violet-950/50 text-violet-200'
                            : 'border-slate-700 text-slate-300 hover:border-slate-600 hover:bg-slate-800'"
                        :title="preset.description"
                        @click="applyPreset(preset)"
                    >
                        {{ preset.label }}
                    </button>
                </div>

                <div class="mt-4 flex flex-wrap items-end gap-x-4 gap-y-3">
                    <label class="flex flex-col gap-1 text-xs text-slate-400">
                        Límite por paso
                        <input
                            v-model.number="pipelineForm.limit"
                            type="number"
                            :min="pipeline.limits?.limit?.min ?? 1"
                            :max="pipeline.limits?.limit?.max ?? 50"
                            class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-white"
                        />
                    </label>

                    <div class="flex flex-col gap-2 pb-0.5">
                        <label
                            v-if="!pipelineForm.wordpress_only"
                            class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-200"
                        >
                            <input
                                :checked="pipelineForm.send_wordpress"
                                type="checkbox"
                                class="rounded border-slate-600 bg-slate-950"
                                @change="onSendWordpressChange($event.target.checked)"
                            />
                            <span>Enviar a WordPress</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input
                                :checked="pipelineForm.wordpress_only"
                                type="checkbox"
                                class="rounded border-slate-600 bg-slate-950"
                                @change="onWordpressOnlyChange($event.target.checked)"
                            />
                            <span>Solo WordPress (sin scrape ni IA)</span>
                        </label>
                    </div>

                    <label
                        v-if="sendsToWordpress"
                        class="flex flex-col gap-1 text-xs text-slate-400"
                    >
                        Modo WordPress
                        <select
                            v-model="pipelineForm.mode"
                            class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-sm text-white"
                        >
                            <option value="draft">draft (borrador)</option>
                            <option value="publish">publish (publicar)</option>
                            <option value="schedule">schedule (programar)</option>
                        </select>
                    </label>

                    <div v-if="!pipelineForm.wordpress_only" class="flex flex-wrap items-center gap-x-4 gap-y-2 pb-0.5">
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input v-model="pipelineForm.skip_scrape" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                            <span>skip-scrape</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input v-model="pipelineForm.skip_research" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                            <span>skip-research</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input v-model="pipelineForm.skip_generate" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                            <span>skip-generate</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input v-model="pipelineForm.force" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                            <span>force</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                            <input v-model="pipelineForm.include_raw_html" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                            <span>include-raw-html</span>
                        </label>
                    </div>

                    <button
                        type="button"
                        class="ml-auto rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                        :disabled="runDisabled"
                        @click="runPipeline"
                    >
                        {{ pipelineStarting ? 'Iniciando…' : runLabel }}
                    </button>
                </div>

                <p class="mt-2 text-xs text-slate-500">{{ modeHelp }}</p>

                <div class="mt-4 rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-medium text-slate-400">Comando equivalente</p>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded border border-slate-700 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-800"
                                @click="copyCliCommand(pipelineCliCommand, 'local')"
                            >
                                {{ copiedCli === 'local' ? 'Copiado' : 'Copiar' }}
                            </button>
                            <button
                                type="button"
                                class="rounded border border-slate-700 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-800"
                                @click="copyCliCommand(dockerCliCommand, 'docker')"
                            >
                                {{ copiedCli === 'docker' ? 'Copiado' : 'Copiar Docker' }}
                            </button>
                        </div>
                    </div>
                    <code class="block overflow-x-auto whitespace-pre-wrap break-all text-xs leading-relaxed text-violet-200">
                        {{ pipelineCliCommand }}
                    </code>
                    <code class="mt-2 block overflow-x-auto whitespace-pre-wrap break-all text-xs leading-relaxed text-slate-500">
                        {{ dockerCliCommand }}
                    </code>
                </div>
            </section>

            <div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5">
                <div class="flex min-w-0 items-center gap-2">
                    <label for="scraper-search" class="shrink-0 text-sm text-slate-400">Buscar</label>
                    <input
                        id="scraper-search"
                        v-model="searchQuery"
                        type="search"
                        placeholder="Título, URL, fuente…"
                        class="w-44 rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-sm sm:w-52"
                        @input="onSearchInput"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="shrink-0 rounded-lg border border-slate-600 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800"
                        @click="clearSearch"
                    >
                        ×
                    </button>
                </div>

                <div class="hidden h-5 w-px bg-slate-700 sm:block" />

                <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                    <input
                        type="checkbox"
                        :checked="filters.pending_wordpress"
                        class="rounded border-slate-600 bg-slate-950"
                        @change="applyFilters({ pending_wordpress: $event.target.checked || undefined, page: 1 })"
                    />
                    <span class="whitespace-nowrap">Pend. WordPress</span>
                </label>

                <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                    <input
                        type="checkbox"
                        :checked="filters.pending_ai"
                        class="rounded border-slate-600 bg-slate-950"
                        @change="applyFilters({ pending_ai: $event.target.checked || undefined, page: 1 })"
                    />
                    <span class="whitespace-nowrap">Pend. IA</span>
                </label>

                <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                    <input
                        type="checkbox"
                        :checked="filters.scheduled_wp"
                        class="rounded border-slate-600 bg-slate-950"
                        @change="applyFilters({ scheduled_wp: $event.target.checked || undefined, page: 1 })"
                    />
                    <span class="whitespace-nowrap">Programados WP</span>
                </label>

                <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                    <input
                        type="checkbox"
                        :checked="filters.failed"
                        class="rounded border-slate-600 bg-slate-950"
                        @change="applyFilters({ failed: $event.target.checked || undefined, page: 1 })"
                    />
                    <span class="whitespace-nowrap">Con error</span>
                </label>

                <div class="hidden h-5 w-px bg-slate-700 sm:block" />

                <label class="flex items-center gap-1.5 text-sm text-slate-400">
                    <span class="whitespace-nowrap">Por página</span>
                    <select
                        :value="filters.per_page ?? news.per_page ?? 15"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-white"
                        @change="changeListPerPage(Number($event.target.value))"
                    >
                        <option
                            v-for="n in list.per_page_options"
                            :key="n"
                            :value="n"
                        >
                            {{ n }}
                        </option>
                    </select>
                </label>
            </div>

            <div
                class="mb-4 rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm"
                :class="hasActiveListFilters ? 'text-sky-200' : 'text-slate-300'"
            >
                <p class="font-medium">{{ listPaginationSummary.headline }}</p>
                <p v-if="listPaginationSummary.detail" class="mt-0.5 text-xs text-slate-500">
                    {{ listPaginationSummary.detail }}
                </p>
            </div>

            <div v-if="news.data.length === 0" class="rounded-2xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
                <template v-if="hasActiveListFilters">
                    <p>{{ listPaginationSummary.headline }}</p>
                    <p v-if="listPaginationSummary.detail" class="mt-2 text-sm text-slate-500">
                        {{ listPaginationSummary.detail }}
                    </p>
                    <button
                        v-if="filters.q"
                        type="button"
                        class="mt-3 text-violet-400 hover:underline"
                        @click="clearSearch"
                    >
                        Limpiar búsqueda
                    </button>
                </template>
                <template v-else>
                    No hay noticias scrapeadas todavía. Ejecuta el pipeline completo arriba.
                </template>
            </div>

            <div v-else class="space-y-3">
                <article
                    v-for="item in news.data"
                    :key="item.id"
                    class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
                >
                    <div class="flex flex-wrap gap-4">
                        <img
                            v-if="item.image_url"
                            :src="item.image_url"
                            :alt="item.title"
                            class="h-24 w-36 rounded-xl object-cover"
                        />
                        <div
                            v-else
                            class="flex h-24 w-36 items-center justify-center rounded-xl border border-dashed border-slate-700 bg-slate-900/80 text-xs text-slate-500"
                        >
                            Sin imagen
                        </div>

                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold">{{ item.ai?.generated_title || item.title }}</h3>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ item.source || 'Fuente desconocida' }}
                                <span v-if="item.category"> · {{ item.category }}</span>
                                · ID {{ item.id }}
                            </p>
                            <a
                                :href="item.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-1 inline-block text-xs text-violet-400 hover:underline"
                            >
                                Ver fuente original
                            </a>

                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span
                                    v-for="stage in itemStages(item)"
                                    :key="`${item.id}-${stage.label}`"
                                    class="inline-flex rounded-md border px-2 py-0.5 text-xs"
                                    :class="stageBadge(stage.state)"
                                >
                                    {{ stage.label }}
                                </span>
                            </div>

                            <p
                                v-if="item.detail?.last_error"
                                class="mt-2 text-xs text-red-300"
                            >
                                Error detalle: {{ item.detail.last_error }}
                            </p>

                            <div v-if="item.ai?.sent_wordpress" class="mt-2 flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex rounded-md border px-2 py-0.5 text-xs"
                                    :class="wpStatusClass(item.ai.wordpress_status)"
                                >
                                    {{ wpStatusLabel(item.ai.wordpress_status) }}
                                </span>
                                <span
                                    v-if="item.ai.wordpress_status === 'future' && item.ai.wordpress_scheduled_at"
                                    class="text-xs text-sky-300"
                                >
                                    Publicación: {{ formatDate(item.ai.wordpress_scheduled_at) }}
                                </span>
                                <span v-else-if="item.ai.sent_wordpress_at" class="text-xs text-slate-500">
                                    Enviado: {{ formatDate(item.ai.sent_wordpress_at) }}
                                </span>
                                <a
                                    v-if="item.ai.wordpress_url"
                                    :href="item.ai.wordpress_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-xs text-violet-400 hover:underline"
                                >
                                    Ver en WP
                                </a>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-if="item.ai?.can_preview"
                                    type="button"
                                    class="rounded-lg border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800"
                                    @click="openPreview(item)"
                                >
                                    Vista previa WordPress
                                </button>
                            </div>
                        </div>
                    </div>
                </article>

                <p class="pt-2 text-center text-xs text-slate-500">
                    {{ listPaginationSummary.headline }}
                    <span v-if="listPaginationSummary.detail"> · {{ listPaginationSummary.detail }}</span>
                </p>

                <div v-if="news.links?.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                    <Link
                        v-for="link in news.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active
                            ? 'bg-violet-600 text-white'
                            : link.url
                                ? 'text-slate-400 hover:bg-slate-800 hover:text-white'
                                : 'cursor-not-allowed text-slate-600'"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="previewOpen"
            class="fixed inset-0 z-[10050] flex items-center justify-center bg-black/75 p-4"
            @click.self="closePreview"
        >
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-800 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs uppercase tracking-wide text-violet-400">Vista previa · WordPress</p>
                        <h3 class="mt-1 text-lg font-semibold">
                            {{ previewData?.title || (previewLoading ? 'Cargando…' : 'Vista previa') }}
                        </h3>
                        <p v-if="previewData?.source_title && previewData.source_title !== previewData.title" class="mt-1 text-xs text-slate-500">
                            Título original: {{ previewData.source_title }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                        <template v-if="previewData?.can_regenerate">
                            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-slate-400">
                                <input
                                    v-model="regenerateIncludeRawHtml"
                                    type="checkbox"
                                    class="rounded border-slate-600 bg-slate-950"
                                    :disabled="regeneratingAi || previewLoading"
                                />
                                include-raw-html
                            </label>
                            <button
                                type="button"
                                class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                                :disabled="regeneratingAi || previewLoading"
                                @click="regeneratePreviewAi"
                            >
                                {{ regeneratingAi ? 'Regenerando IA…' : 'Regenerar con IA' }}
                            </button>
                        </template>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-600 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
                            :disabled="regeneratingAi"
                            @click="closePreview"
                        >
                            Cerrar
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div v-if="previewLoading || regeneratingAi" class="p-10 text-center text-slate-400">
                        {{ regeneratingAi ? 'Regenerando artículo con IA… puede tardar 1–2 minutos.' : 'Cargando artículo…' }}
                    </div>

                    <div v-else-if="previewError" class="p-10 text-center text-red-300">
                        {{ previewError }}
                    </div>

                    <article v-else-if="previewData" class="article-preview">
                        <img
                            v-if="previewData.image_url"
                            :src="previewData.image_url"
                            :alt="previewData.title"
                            class="w-full max-h-80 object-cover"
                        />

                        <div class="border-b border-slate-800 bg-slate-950/50 px-6 py-4">
                            <div class="flex flex-wrap gap-2 text-xs text-slate-500">
                                <span v-if="previewData.category">Categoría: {{ previewData.category }}</span>
                                <span v-if="previewData.source">· {{ previewData.source }}</span>
                                <span v-if="previewData.model">· Modelo: {{ previewData.model }}</span>
                                <span v-if="previewData.image_source">· Imagen: {{ previewData.image_source }}</span>
                                <span
                                    v-if="previewData.sent_wordpress && previewData.wordpress_status === 'future'"
                                    class="text-sky-400"
                                >
                                    · Programado WP: {{ formatDate(previewData.wordpress_scheduled_at) }}
                                </span>
                                <span
                                    v-else-if="previewData.sent_wordpress"
                                    class="text-emerald-400"
                                >
                                    · {{ wpStatusLabel(previewData.wordpress_status) }} en WP
                                </span>
                                <span v-else class="text-amber-400">· Pendiente de envío</span>
                            </div>

                            <p v-if="previewData.excerpt" class="mt-3 text-sm italic text-slate-300">
                                {{ previewData.excerpt }}
                            </p>
                            <p v-else class="mt-3 text-sm italic text-slate-500">
                                Sin extracto
                            </p>
                        </div>

                        <div
                            class="article-preview-body px-6 py-6"
                            v-html="previewData.body_html"
                        />

                        <div class="border-t border-slate-800 px-6 py-4 text-xs text-slate-500">
                            <a
                                v-if="previewData.source_url"
                                :href="previewData.source_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-violet-400 hover:underline"
                            >
                                Fuente original
                            </a>
                            <span v-if="previewData.sent_wordpress_at" class="ml-3 text-emerald-400">
                                Enviado: {{ formatDate(previewData.sent_wordpress_at) }}
                            </span>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.article-preview-body :deep(h2) {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-size: 1.25rem;
    font-weight: 600;
    color: rgb(241 245 249);
}

.article-preview-body :deep(h3) {
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
    font-weight: 600;
    color: rgb(226 232 240);
}

.article-preview-body :deep(p) {
    margin-bottom: 1rem;
    line-height: 1.7;
    color: rgb(203 213 225);
}

.article-preview-body :deep(ul),
.article-preview-body :deep(ol) {
    margin-bottom: 1rem;
    padding-left: 1.5rem;
    color: rgb(203 213 225);
}

.article-preview-body :deep(li) {
    margin-bottom: 0.35rem;
}

.article-preview-body :deep(a) {
    color: rgb(167 139 250);
    text-decoration: underline;
}

.article-preview-body :deep(strong) {
    color: rgb(241 245 249);
    font-weight: 600;
}

.article-preview-body :deep(blockquote) {
    margin: 1rem 0;
    border-left: 3px solid rgb(124 58 237);
    padding-left: 1rem;
    color: rgb(148 163 184);
    font-style: italic;
}

.article-preview-body :deep(figure) {
    margin: 1.25rem 0;
}

.article-preview-body :deep(iframe) {
    aspect-ratio: 16 / 9;
    width: 100%;
    max-width: 100%;
    border-radius: 0.75rem;
    border: 1px solid rgb(51 65 85);
}

.article-preview-body :deep(img) {
    max-width: 100%;
    border-radius: 0.5rem;
}
</style>
