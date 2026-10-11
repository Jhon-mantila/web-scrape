<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({
            site: 'esquinaweb',
            pending_facebook: false,
            pending_linkedin: false,
            q: '',
        }),
    },
    sites: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({
            total_articles: 0,
            published: 0,
            scheduled: 0,
            failed: 0,
            publishing: 0,
            queued: 0,
            pending_slots: 0,
        }),
    },
    captionAiOptions: {
        type: Array,
        default: () => [],
    },
    sync: {
        type: Object,
        default: () => ({
            per_page: 20,
            backfill_pages: 5,
            new_pages: 3,
            limits: {},
        }),
    },
    posts: {
        type: Object,
        default: () => ({ data: [] }),
    },
});

const syncForm = useForm({
    per_page: props.sync.per_page,
    backfill_pages: props.sync.backfill_pages,
    new_pages: props.sync.new_pages,
});

const LINKEDIN_PLATFORMS = ['linkedin', 'linkedin_jessika'];

const publishModal = ref(null);
const publishTarget = ref(null);
const publishContext = ref('single');
const searchQuery = ref(props.filters.q || '');
const deliveryMode = ref('now');
const captionAiProvider = ref('ollama_local');
const messageSource = ref('excerpt');
const excerptText = ref('');
const aiCaption = ref('');
const generatingAi = ref(false);
const selectedPostIds = ref([]);
const batchModalOpen = ref(false);
const batchDeliveryMode = ref('queue');
const batchQueuedAt = ref('');
const batchFacebookScheduledAt = ref('');
const batchCaptionAiProvider = ref('ollama_local');
const batchTextMode = ref('excerpt');
const batchMessages = ref({});
const batchGenerating = ref(false);
const batchSubmitting = ref(false);
const batchPlatforms = ref({
    facebook: true,
    linkedin: true,
    linkedin_jessika: true,
});
const generatingLinkedinKey = ref(null);
const linkedinDrafts = ref({
    linkedin: { source: 'excerpt', excerpt: '', ai: '', message: '' },
    linkedin_jessika: { source: 'excerpt', excerpt: '', ai: '', message: '' },
});
let searchTimer = null;

const publishForm = useForm({
    platform: '',
    message: '',
    scheduled_at: '',
    queued_publish_at: '',
    delivery_mode: 'now',
});

const batchForm = useForm({
    publications: [],
});

const enabledSites = computed(() => props.sites.filter((site) => site.enabled && !site.coming_soon));

const syncBatchMax = computed(() =>
    syncForm.per_page * (syncForm.backfill_pages + syncForm.new_pages),
);

const statCards = computed(() => [
    { key: 'total_articles', label: 'En lista', value: props.stats.total_articles, class: 'text-white' },
    { key: 'pending_slots', label: 'Pend. redes', value: props.stats.pending_slots, class: 'text-amber-300' },
    { key: 'queued', label: 'En cola (app)', value: props.stats.queued, class: 'text-cyan-300' },
    { key: 'scheduled', label: 'Programados (FB)', value: props.stats.scheduled, class: 'text-sky-300' },
    { key: 'published', label: 'Publicados', value: props.stats.published, class: 'text-emerald-300' },
    { key: 'failed', label: 'Error', value: props.stats.failed, class: 'text-red-400' },
    { key: 'publishing', label: 'Publicando', value: props.stats.publishing, class: 'text-violet-300' },
]);

const isFacebookTarget = computed(() =>
    publishContext.value === 'single' && (publishTarget.value?.platform?.startsWith('facebook_') ?? false),
);

const isLinkedinSingle = computed(() =>
    publishContext.value === 'single' && (publishTarget.value?.platform?.startsWith('linkedin') ?? false),
);

const isLinkedinBoth = computed(() => publishContext.value === 'linkedin_both');

const showAiControls = computed(() => isFacebookTarget.value || isLinkedinSingle.value);

const submitLabel = computed(() => {
    if (publishForm.processing || batchForm.processing) {
        if (isLinkedinBoth.value) {
            return 'Publicando…';
        }

        return deliveryMode.value === 'queue' ? 'Encolando…' : 'Publicando…';
    }

    if (isLinkedinBoth.value) {
        return deliveryMode.value === 'queue' ? 'Encolar ambos' : 'Publicar ambos';
    }

    return deliveryMode.value === 'queue' ? 'Encolar en la app' : 'Publicar ahora';
});

const selectedPosts = computed(() =>
    props.posts.data.filter((post) => selectedPostIds.value.includes(post.id)),
);

const siteFacebookPlatform = computed(() => {
    const post = props.posts.data.find((item) => item.facebook_platform);

    return post?.facebook_platform ?? null;
});

function togglePostSelection(id) {
    if (selectedPostIds.value.includes(id)) {
        selectedPostIds.value = selectedPostIds.value.filter((item) => item !== id);
    } else {
        selectedPostIds.value = [...selectedPostIds.value, id];
    }
}

function selectAllVisiblePosts() {
    selectedPostIds.value = props.posts.data.map((post) => post.id);
}

function clearPostSelection() {
    selectedPostIds.value = [];
}

function openBatchModal() {
    if (selectedPostIds.value.length === 0) {
        return;
    }

    batchModalOpen.value = true;
    batchDeliveryMode.value = 'queue';
    batchQueuedAt.value = '';
    batchFacebookScheduledAt.value = '';
    batchTextMode.value = 'excerpt';
    batchMessages.value = {};
    batchPlatforms.value = {
        facebook: true,
        linkedin: true,
        linkedin_jessika: true,
    };
}

function closeBatchModal() {
    batchModalOpen.value = false;
}

function batchPlatformKeys() {
    const keys = [];

    if (batchPlatforms.value.facebook && siteFacebookPlatform.value) {
        keys.push(siteFacebookPlatform.value);
    }

    if (batchPlatforms.value.linkedin) {
        keys.push('linkedin');
    }

    if (batchPlatforms.value.linkedin_jessika) {
        keys.push('linkedin_jessika');
    }

    return keys;
}

function defaultMessageForPost(post) {
    return post.excerpt || post.title;
}

function batchMessage(postId, platform) {
    if (!batchMessages.value[postId]) {
        batchMessages.value[postId] = {};
    }

    return batchMessages.value[postId][platform] ?? '';
}

function setBatchMessage(postId, platform, value) {
    if (!batchMessages.value[postId]) {
        batchMessages.value[postId] = {};
    }

    batchMessages.value[postId][platform] = value;
}

async function generateBatchCaptions() {
    const platforms = batchPlatformKeys();

    if (platforms.length === 0 || selectedPostIds.value.length === 0) {
        return;
    }

    if (batchCaptionAiProvider.value === 'none') {
        window.alert('Elige Ollama local o DeepSeek para generar textos.');

        return;
    }

    batchGenerating.value = true;

    try {
        const { data } = await axios.post(
            route('articles.generate-captions-batch'),
            {
                post_ids: selectedPostIds.value,
                platforms,
                caption_ai_provider: batchCaptionAiProvider.value,
            },
            { headers: csrfHeaders() },
        );

        batchTextMode.value = 'custom';

        Object.entries(data.captions ?? {}).forEach(([postId, platformMap]) => {
            batchMessages.value[postId] = {
                ...(batchMessages.value[postId] ?? {}),
                ...platformMap,
            };
        });
    } catch (error) {
        window.alert(error.response?.data?.message || 'No se pudieron generar los textos.');
    } finally {
        batchGenerating.value = false;
    }
}

function submitBatch() {
    const platforms = batchPlatformKeys();

    if (platforms.length === 0) {
        return;
    }

    const messages = {};

    selectedPosts.value.forEach((post) => {
        messages[post.id] = {};

        platforms.forEach((platform) => {
            const custom = batchMessages.value[post.id]?.[platform];

            messages[post.id][platform] = batchTextMode.value === 'excerpt'
                ? defaultMessageForPost(post)
                : (custom || defaultMessageForPost(post));
        });
    });

    batchSubmitting.value = true;

    router.post(
        route('articles.batch-plan'),
        {
            post_ids: selectedPostIds.value,
            platforms,
            mode: batchDeliveryMode.value,
            queued_publish_at: batchDeliveryMode.value === 'queue' ? batchQueuedAt.value : null,
            facebook_scheduled_at: batchFacebookScheduledAt.value || null,
            messages,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeBatchModal();
                clearPostSelection();
            },
            onFinish: () => {
                batchSubmitting.value = false;
            },
        },
    );
}

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString('es-CO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function applyFilters(partial = {}) {
    router.get(route('articles.index'), {
        site: partial.site ?? props.filters.site,
        pending_facebook: partial.pending_facebook ?? props.filters.pending_facebook,
        pending_linkedin: partial.pending_linkedin ?? props.filters.pending_linkedin,
        q: partial.q ?? searchQuery.value,
    }, {
        preserveState: true,
        replace: true,
    });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ q: searchQuery.value }), 400);
}

function clearSearch() {
    searchQuery.value = '';
    applyFilters({ q: '' });
}

function syncArticles() {
    syncForm
        .transform((data) => ({
            site: props.filters.site,
            per_page: data.per_page,
            backfill_pages: data.backfill_pages,
            new_pages: data.new_pages,
        }))
        .post(route('articles.sync'));
}

function statusBadgeClass(status) {
    return {
        published: 'border-emerald-900/50 bg-emerald-950/40 text-emerald-300',
        scheduled: 'border-sky-900/50 bg-sky-950/40 text-sky-300',
        failed: 'border-red-900/50 bg-red-950/40 text-red-300',
        publishing: 'border-violet-900/50 bg-violet-950/40 text-violet-300',
        pending: 'border-slate-700 bg-slate-900/60 text-slate-400',
    }[status] ?? 'border-amber-900/50 bg-amber-950/40 text-amber-300';
}

function linkedinDraftTemplate(excerpt) {
    return { source: 'excerpt', excerpt, ai: '', message: excerpt };
}

function openPublish(post, platform) {
    publishModal.value = post;
    publishContext.value = 'single';
    publishTarget.value = platform;
    deliveryMode.value = 'now';
    messageSource.value = 'excerpt';
    excerptText.value = post.excerpt || post.title;
    aiCaption.value = '';
    publishForm.platform = platform.platform;
    publishForm.message = excerptText.value;
    publishForm.scheduled_at = '';
    publishForm.queued_publish_at = '';
    publishForm.delivery_mode = 'now';
}

function openLinkedinBoth(post) {
    const excerpt = post.excerpt || post.title;

    publishModal.value = post;
    publishContext.value = 'linkedin_both';
    publishTarget.value = null;
    deliveryMode.value = 'now';
    publishForm.queued_publish_at = '';
    linkedinDrafts.value = {
        linkedin: linkedinDraftTemplate(excerpt),
        linkedin_jessika: linkedinDraftTemplate(excerpt),
    };
}

function closePublish() {
    publishModal.value = null;
    publishTarget.value = null;
    publishContext.value = 'single';
    deliveryMode.value = 'now';
    messageSource.value = 'excerpt';
    excerptText.value = '';
    aiCaption.value = '';
    generatingLinkedinKey.value = null;
    publishForm.reset();
    batchForm.reset();
}

function syncActiveMessageBuffer() {
    if (messageSource.value === 'excerpt') {
        excerptText.value = publishForm.message;
    } else {
        aiCaption.value = publishForm.message;
    }
}

function selectMessageSource(source) {
    syncActiveMessageBuffer();
    messageSource.value = source;
    publishForm.message = source === 'excerpt' ? excerptText.value : aiCaption.value;
}

function csrfHeaders() {
    return {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    };
}

async function generateAiCaption() {
    if (!publishModal.value || !publishTarget.value) {
        return;
    }

    generatingAi.value = true;
    syncActiveMessageBuffer();

    try {
        const { data } = await axios.post(
            route('articles.generate-caption', publishModal.value.id),
            {
                platform: publishTarget.value.platform,
                caption_ai_provider: captionAiProvider.value,
            },
            { headers: csrfHeaders() },
        );

        aiCaption.value = data.caption ?? '';
        messageSource.value = 'ai';
        publishForm.message = aiCaption.value;
    } catch (error) {
        const message = error.response?.data?.message || 'No se pudo generar el texto con IA.';
        window.alert(message);
    } finally {
        generatingAi.value = false;
    }
}

function syncLinkedinDraft(key) {
    const draft = linkedinDrafts.value[key];

    if (draft.source === 'excerpt') {
        draft.excerpt = draft.message;
    } else {
        draft.ai = draft.message;
    }
}

function selectLinkedinSource(key, source) {
    syncLinkedinDraft(key);
    linkedinDrafts.value[key].source = source;
    linkedinDrafts.value[key].message = source === 'excerpt'
        ? linkedinDrafts.value[key].excerpt
        : linkedinDrafts.value[key].ai;
}

async function generateLinkedinAi(key) {
    if (!publishModal.value) {
        return;
    }

    generatingLinkedinKey.value = key;
    syncLinkedinDraft(key);

    try {
        const { data } = await axios.post(
            route('articles.generate-caption', publishModal.value.id),
            { platform: key, caption_ai_provider: captionAiProvider.value },
            { headers: csrfHeaders() },
        );

        linkedinDrafts.value[key].ai = data.caption ?? '';
        linkedinDrafts.value[key].source = 'ai';
        linkedinDrafts.value[key].message = linkedinDrafts.value[key].ai;
    } catch (error) {
        window.alert(error.response?.data?.message || 'No se pudo generar el texto con IA.');
    } finally {
        generatingLinkedinKey.value = null;
    }
}

async function generateLinkedinBothAi() {
    if (!publishModal.value) {
        return;
    }

    generatingAi.value = true;
    LINKEDIN_PLATFORMS.forEach((key) => syncLinkedinDraft(key));

    try {
        const { data } = await axios.post(
            route('articles.generate-caption', publishModal.value.id),
            { platforms: LINKEDIN_PLATFORMS, caption_ai_provider: captionAiProvider.value },
            { headers: csrfHeaders() },
        );

        LINKEDIN_PLATFORMS.forEach((key) => {
            linkedinDrafts.value[key].ai = data.captions?.[key] ?? '';
            linkedinDrafts.value[key].source = 'ai';
            linkedinDrafts.value[key].message = linkedinDrafts.value[key].ai;
        });
    } catch (error) {
        window.alert(error.response?.data?.message || 'No se pudo generar los textos con IA.');
    } finally {
        generatingAi.value = false;
    }
}

function openSchedulePicker(event) {
    const input = event.currentTarget.parentElement?.querySelector('.schedule-datetime-input');

    if (input && typeof input.showPicker === 'function') {
        input.showPicker();
    }
}

function submitPublish() {
    if (!publishModal.value) {
        return;
    }

    if (isLinkedinBoth.value) {
        LINKEDIN_PLATFORMS.forEach((key) => syncLinkedinDraft(key));

        const platforms = linkedinPlatforms(publishModal.value)
            .filter((platform) => platform.can_publish)
            .map((platform) => platform.platform);

        if (platforms.length === 0) {
            return;
        }

        if (deliveryMode.value === 'queue' && !publishForm.queued_publish_at) {
            return;
        }

        const messages = {
            [publishModal.value.id]: {},
        };

        platforms.forEach((key) => {
            messages[publishModal.value.id][key] = linkedinDrafts.value[key].message;
        });

        router.post(
            route('articles.batch-plan'),
            {
                post_ids: [publishModal.value.id],
                platforms,
                mode: deliveryMode.value,
                queued_publish_at: deliveryMode.value === 'queue' ? publishForm.queued_publish_at : null,
                messages,
            },
            { onSuccess: () => closePublish() },
        );

        return;
    }

    if (deliveryMode.value === 'queue' && !publishForm.queued_publish_at) {
        return;
    }

    syncActiveMessageBuffer();

    publishForm.delivery_mode = deliveryMode.value;

    publishForm.transform((data) => ({
        ...data,
        delivery_mode: deliveryMode.value,
        queued_publish_at: deliveryMode.value === 'queue' ? data.queued_publish_at : null,
    })).post(route('articles.publish', publishModal.value.id), {
        onSuccess: () => closePublish(),
    });
}

function facebookPlatform(post) {
    return post.platforms.find((platform) => platform.platform.startsWith('facebook_'));
}

function linkedinPlatforms(post) {
    return post.platforms.filter((platform) => platform.platform.startsWith('linkedin'));
}

function canLinkedinBoth(post) {
    return linkedinPlatforms(post).some((platform) => platform.can_publish);
}

function linkedinLabel(key) {
    return key === 'linkedin' ? 'LinkedIn — Jhon' : 'LinkedIn — Jessika';
}

function deleteFromFacebook(post, platform) {
    const label = platform.status === 'scheduled' ? 'programación' : 'publicación';

    if (!confirm(`¿Eliminar esta ${label} de Facebook (${platform.platform_label})? Podrás enviar el artículo de nuevo.`)) {
        return;
    }

    router.delete(route('articles.publications.facebook.destroy', [post.id, platform.publication_id]));
}
</script>

<template>
    <AppLayout>
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold">Artículos WordPress</h2>
                <p class="mt-1 text-slate-400">
                    Sincroniza artículos de Esquina Web y Esquina Gamers y publícalos en Facebook y LinkedIn sin repetir.
                </p>
            </div>
        </div>

        <div class="mb-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <div
                v-for="card in statCards"
                :key="card.key"
                class="rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5"
            >
                <p class="text-xs text-slate-500">{{ card.label }}</p>
                <p class="text-lg font-semibold" :class="card.class">{{ card.value }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2">
            <span class="text-xs text-slate-500">Tanda sync:</span>
            <label class="flex items-center gap-1.5 text-xs text-slate-300">
                /pág
                <input
                    v-model.number="syncForm.per_page"
                    type="number"
                    min="1"
                    :max="sync.limits?.per_page?.max ?? 100"
                    class="w-14 rounded border border-slate-700 bg-slate-950 px-1.5 py-1 text-center text-sm"
                />
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-300">
                Viejos
                <input
                    v-model.number="syncForm.backfill_pages"
                    type="number"
                    min="1"
                    :max="sync.limits?.backfill_pages?.max ?? 20"
                    class="w-14 rounded border border-slate-700 bg-slate-950 px-1.5 py-1 text-center text-sm"
                />
                pág
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-300">
                Nuevos
                <input
                    v-model.number="syncForm.new_pages"
                    type="number"
                    min="1"
                    :max="sync.limits?.new_pages?.max ?? 10"
                    class="w-14 rounded border border-slate-700 bg-slate-950 px-1.5 py-1 text-center text-sm"
                />
                pág
            </label>
            <span class="text-xs text-slate-500">máx ~{{ syncBatchMax }}/sync · omite los ya guardados</span>
            <button
                type="button"
                class="ml-auto rounded-lg bg-violet-600 px-3 py-1.5 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                :disabled="syncForm.processing"
                @click="syncArticles"
            >
                {{ syncForm.processing ? 'Sincronizando…' : 'Sincronizar' }}
            </button>
        </div>

        <div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2.5">
            <div class="flex min-w-0 items-center gap-2">
                <label for="articles-search" class="shrink-0 text-sm text-slate-400">Buscar</label>
                <input
                    id="articles-search"
                    v-model="searchQuery"
                    type="search"
                    placeholder="Título, URL…"
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

            <div class="flex items-center gap-2">
                <label for="articles-site" class="shrink-0 text-sm text-slate-400">Sitio</label>
                <select
                    id="articles-site"
                    :value="filters.site"
                    class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-sm"
                    @change="applyFilters({ site: $event.target.value })"
                >
                    <option
                        v-for="site in sites"
                        :key="site.key"
                        :value="site.key"
                        :disabled="!site.enabled || site.coming_soon"
                    >
                        {{ site.label }}{{ site.coming_soon ? ' (pronto)' : '' }}
                    </option>
                </select>
            </div>

            <div class="hidden h-5 w-px bg-slate-700 md:block" />

            <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                <input
                    type="checkbox"
                    :checked="filters.pending_facebook"
                    class="rounded border-slate-600 bg-slate-950"
                    @change="applyFilters({ pending_facebook: $event.target.checked })"
                />
                <span class="whitespace-nowrap">Pend. Facebook</span>
            </label>

            <label class="flex cursor-pointer items-center gap-1.5 text-sm text-slate-300">
                <input
                    type="checkbox"
                    :checked="filters.pending_linkedin"
                    class="rounded border-slate-600 bg-slate-950"
                    @change="applyFilters({ pending_linkedin: $event.target.checked })"
                />
                <span class="whitespace-nowrap">Pend. LinkedIn</span>
            </label>
        </div>

        <div v-if="enabledSites.length === 0" class="rounded-2xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
            Configura las URLs de WordPress en el servidor (<code class="text-slate-300">WORDPRESS_ESQUINAWEB_URL</code>, <code class="text-slate-300">WORDPRESS_ESQUINAGAMERS_URL</code>) y pulsa Sincronizar.
        </div>

        <div v-else-if="posts.data.length === 0" class="rounded-2xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
            <template v-if="filters.q">
                No hay resultados para «{{ filters.q }}».
                <button type="button" class="ml-1 text-violet-400 hover:underline" @click="clearSearch">
                    Limpiar búsqueda
                </button>
            </template>
            <template v-else>
                No hay artículos sincronizados para este sitio.
                <button type="button" class="ml-1 text-violet-400 hover:underline" @click="syncArticles">
                    Sincronizar ahora
                </button>
            </template>
        </div>

        <div v-else class="space-y-3">
            <div
                v-if="selectedPostIds.length > 0"
                class="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-xl border border-violet-700/50 bg-violet-950/40 px-3 py-2"
            >
                <span class="text-sm text-violet-200">{{ selectedPostIds.length }} seleccionado(s)</span>
                <button
                    type="button"
                    class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm hover:bg-violet-500"
                    @click="openBatchModal"
                >
                    Programar / publicar…
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-600 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
                    @click="clearPostSelection"
                >
                    Limpiar
                </button>
            </div>

            <div class="flex flex-wrap gap-2 text-xs text-slate-500">
                <button type="button" class="text-violet-400 hover:underline" @click="selectAllVisiblePosts">
                    Seleccionar página
                </button>
            </div>

            <article
                v-for="post in posts.data"
                :key="post.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
            >
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-start pt-1">
                        <input
                            type="checkbox"
                            class="mt-1 rounded border-slate-600 bg-slate-950"
                            :checked="selectedPostIds.includes(post.id)"
                            @change="togglePostSelection(post.id)"
                        />
                    </label>
                    <img
                        v-if="post.featured_image_url"
                        :src="post.featured_image_url"
                        :alt="post.title"
                        class="h-24 w-36 rounded-xl object-cover"
                    />
                    <div
                        v-else
                        class="flex h-24 w-36 items-center justify-center rounded-xl border border-dashed border-slate-700 bg-slate-900/80 text-xs text-slate-500"
                    >
                        Sin imagen
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ post.title }}</h3>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ post.site_label }} · Publicado en WP: {{ formatDate(post.published_at_wp) }}
                                </p>
                                <a
                                    :href="post.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-1 inline-block text-xs text-violet-400 hover:underline"
                                >
                                    Ver artículo
                                </a>
                            </div>
                        </div>

                        <p v-if="post.excerpt" class="mt-2 line-clamp-2 text-sm text-slate-400">
                            {{ post.excerpt }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="platform in post.platforms"
                                :key="platform.platform"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs"
                                :class="statusBadgeClass(platform.status)"
                            >
                                <span>{{ platform.status_icon }}</span>
                                <span class="font-medium">{{ platform.platform_label }}</span>
                                <span class="opacity-80">{{ platform.status_label }}</span>
                            </span>
                        </div>

                        <div class="mt-2 space-y-1">
                            <p
                                v-for="platform in post.platforms.filter((item) => item.status === 'scheduled' && item.scheduled_at)"
                                :key="`schedule-${platform.platform}`"
                                class="text-xs text-sky-300"
                            >
                                {{ platform.platform_label }} · Programado en Facebook: {{ formatDate(platform.scheduled_at) }}
                            </p>
                            <p
                                v-for="platform in post.platforms.filter((item) => item.queued_publish_at && (item.status === 'queued' || new Date(item.queued_publish_at) > new Date()))"
                                :key="`queue-${platform.platform}`"
                                class="text-xs text-cyan-300"
                            >
                                {{ platform.platform_label }} · Cola app: {{ formatDate(platform.queued_publish_at) }}
                            </p>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button
                                v-if="facebookPlatform(post)?.can_publish"
                                type="button"
                                class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm hover:bg-violet-500"
                                @click="openPublish(post, facebookPlatform(post))"
                            >
                                Facebook (ahora o programar)
                            </button>
                            <button
                                v-if="canLinkedinBoth(post)"
                                type="button"
                                class="rounded-lg border border-sky-600/60 px-3 py-1.5 text-sm text-sky-300 hover:bg-sky-950/40"
                                @click="openLinkedinBoth(post)"
                            >
                                LinkedIn (ambos)
                            </button>
                            <button
                                v-for="platform in linkedinPlatforms(post).filter((item) => item.can_publish)"
                                :key="platform.platform"
                                type="button"
                                class="rounded-lg border border-slate-600 px-3 py-1.5 text-sm hover:bg-slate-800"
                                @click="openPublish(post, platform)"
                            >
                                {{ platform.platform_label }}
                            </button>
                            <template v-for="platform in post.platforms" :key="`link-${platform.platform}`">
                                <a
                                    v-if="platform.external_url"
                                    :href="platform.external_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
                                >
                                    Ver en {{ platform.platform_label }}
                                </a>
                                <button
                                    v-if="platform.can_delete_from_facebook"
                                    type="button"
                                    class="rounded-lg border border-red-900/60 px-3 py-1.5 text-sm text-red-400 hover:bg-red-950/40"
                                    @click="deleteFromFacebook(post, platform)"
                                >
                                    Eliminar de Facebook
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </article>

            <div v-if="posts.links?.length > 3" class="flex flex-wrap gap-2 pt-2">
                <Link
                    v-for="link in posts.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="rounded-lg border px-3 py-1.5 text-sm"
                    :class="link.active
                        ? 'border-violet-500 bg-violet-600/20 text-violet-200'
                        : link.url
                            ? 'border-slate-700 text-slate-300 hover:bg-slate-800'
                            : 'border-slate-800 text-slate-600 pointer-events-none'"
                    v-html="link.label"
                />
            </div>
        </div>

        <div
            v-if="publishModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="closePublish"
        >
            <div
                class="w-full rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-xl"
                :class="isLinkedinBoth ? 'max-w-2xl' : 'max-w-lg'"
            >
                <h3 class="text-lg font-semibold">Publicar artículo</h3>
                <p class="mt-1 text-sm text-slate-400">{{ publishModal.title }}</p>
                <p v-if="publishTarget" class="mt-2 text-xs text-slate-500">
                    Destino: {{ publishTarget.platform_label }}
                </p>
                <p v-else-if="isLinkedinBoth" class="mt-2 text-xs text-slate-500">
                    Destino: LinkedIn Jhon + Jessika
                </p>

                <form class="mt-4 space-y-4" @submit.prevent="submitPublish">
                    <div v-if="isLinkedinBoth" class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-violet-500/50 px-3 py-1.5 text-xs text-violet-300 hover:bg-violet-950/40 disabled:opacity-50"
                            :disabled="generatingAi || captionAiProvider === 'none'"
                            @click="generateLinkedinBothAi"
                        >
                            {{ generatingAi ? 'Generando ambos…' : 'Generar ambos con IA' }}
                        </button>
                    </div>

                    <template v-if="isLinkedinBoth">
                        <div
                            v-for="key in LINKEDIN_PLATFORMS"
                            :key="key"
                            class="rounded-xl border border-slate-800 p-4"
                        >
                            <div class="mb-2 flex flex-wrap items-center gap-3 text-sm text-slate-300">
                                <span class="font-medium text-slate-200">{{ linkedinLabel(key) }}</span>
                                <label class="flex cursor-pointer items-center gap-1.5">
                                    <input
                                        type="radio"
                                        :checked="linkedinDrafts[key].source === 'excerpt'"
                                        class="rounded-full border-slate-600 bg-slate-950"
                                        @change="selectLinkedinSource(key, 'excerpt')"
                                    />
                                    Extracto
                                </label>
                                <label class="flex cursor-pointer items-center gap-1.5">
                                    <input
                                        type="radio"
                                        :checked="linkedinDrafts[key].source === 'ai'"
                                        class="rounded-full border-slate-600 bg-slate-950"
                                        @change="selectLinkedinSource(key, 'ai')"
                                    />
                                    IA
                                </label>
                                <button
                                    type="button"
                                    class="rounded-lg border border-violet-500/50 px-2 py-0.5 text-xs text-violet-300 hover:bg-violet-950/40 disabled:opacity-50"
                                    :disabled="generatingLinkedinKey === key || captionAiProvider === 'none'"
                                    @click="generateLinkedinAi(key)"
                                >
                                    {{ generatingLinkedinKey === key ? 'Generando…' : 'Generar IA' }}
                                </button>
                            </div>
                            <textarea
                                v-model="linkedinDrafts[key].message"
                                rows="4"
                                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                                @blur="syncLinkedinDraft(key)"
                            />
                        </div>
                    </template>

                    <div v-if="showAiControls || isLinkedinBoth" class="space-y-2 rounded-xl border border-slate-800 p-3">
                        <label class="block text-xs text-slate-400">IA para el texto</label>
                        <select
                            v-model="captionAiProvider"
                            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                        >
                            <option
                                v-for="opt in captionAiOptions"
                                :key="opt.value"
                                :value="opt.value"
                                :disabled="opt.disabled"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <p class="text-xs text-slate-500">
                            {{ captionAiOptions.find((o) => o.value === captionAiProvider)?.hint }}
                        </p>
                    </div>

                    <div v-if="showAiControls" class="flex flex-wrap items-center gap-3 text-sm text-slate-300">
                        <span class="text-slate-400">Texto:</span>
                        <label class="flex cursor-pointer items-center gap-1.5">
                            <input
                                type="radio"
                                value="excerpt"
                                :checked="messageSource === 'excerpt'"
                                class="rounded-full border-slate-600 bg-slate-950"
                                @change="selectMessageSource('excerpt')"
                            />
                            Extracto
                        </label>
                        <label class="flex cursor-pointer items-center gap-1.5">
                            <input
                                type="radio"
                                value="ai"
                                :checked="messageSource === 'ai'"
                                class="rounded-full border-slate-600 bg-slate-950"
                                @change="selectMessageSource('ai')"
                            />
                            IA generada
                        </label>
                        <button
                            type="button"
                            class="rounded-lg border border-violet-500/50 px-2.5 py-1 text-xs text-violet-300 hover:bg-violet-950/40 disabled:opacity-50"
                            :disabled="generatingAi || captionAiProvider === 'none'"
                            @click="generateAiCaption"
                        >
                            {{ generatingAi ? 'Generando…' : 'Generar y ver texto' }}
                        </button>
                    </div>

                    <div v-if="!isLinkedinBoth">
                        <label class="mb-2 block text-sm text-slate-300">Texto del post</label>
                        <textarea
                            v-model="publishForm.message"
                            rows="5"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm"
                            placeholder="Mensaje que acompaña el enlace"
                            @blur="syncActiveMessageBuffer"
                        />
                    </div>

                    <div class="space-y-3 rounded-xl border border-slate-800 p-4">
                        <p class="text-sm font-medium text-slate-200">Cuándo enviar</p>
                        <div class="flex flex-wrap gap-4 text-sm text-slate-300">
                            <label class="flex items-center gap-2">
                                <input v-model="deliveryMode" type="radio" value="now" class="rounded-full border-slate-600 bg-slate-950" />
                                Publicar ahora
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="deliveryMode" type="radio" value="queue" class="rounded-full border-slate-600 bg-slate-950" />
                                Enviar automáticamente (app)
                            </label>
                        </div>
                        <div v-if="deliveryMode === 'queue'" class="schedule-datetime-wrap relative">
                            <label class="mb-1 block text-xs text-slate-400">Fecha cola app (Facebook y LinkedIn)</label>
                            <input
                                v-model="publishForm.queued_publish_at"
                                type="datetime-local"
                                class="schedule-datetime-input w-full rounded-xl border border-sky-800/40 bg-slate-950 px-4 py-2.5 text-sm"
                            />
                            <button
                                type="button"
                                class="schedule-datetime-trigger absolute bottom-0 right-0 px-3 py-2.5 text-slate-400 hover:text-white"
                                @click="openSchedulePicker"
                            >
                                📅
                            </button>
                            <p class="mt-1 text-xs text-slate-500">
                                Activa «Cola de artículos» en Configuración → Planificador.
                            </p>
                        </div>
                        <div v-if="isFacebookTarget" class="schedule-datetime-wrap relative">
                            <label class="mb-1 block text-xs text-slate-400">
                                Programar en Facebook (Meta)
                                <span class="text-slate-600"> · opcional, aparte de la cola</span>
                            </label>
                            <input
                                v-model="publishForm.scheduled_at"
                                type="datetime-local"
                                class="schedule-datetime-input w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm"
                            />
                            <p class="mt-1 text-xs text-slate-500">
                                Hora en la que Meta hará visible el post (independiente de la cola de la app).
                            </p>
                        </div>
                        <p v-else-if="isLinkedinBoth || isLinkedinSingle" class="text-xs text-slate-500">
                            LinkedIn no tiene planificador nativo; usa la cola de la app.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-600 px-4 py-2 text-sm hover:bg-slate-800"
                            @click="closePublish"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                            :disabled="publishForm.processing || batchForm.processing || (deliveryMode === 'queue' && !publishForm.queued_publish_at)"
                        >
                            {{ submitLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="batchModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="closeBatchModal"
        >
            <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-xl">
                <h3 class="text-lg font-semibold">Varios artículos</h3>
                <p class="mt-1 text-sm text-slate-400">{{ selectedPostIds.length }} artículo(s) · sitio {{ filters.site }}</p>

                <div class="mt-4 space-y-4">
                    <div>
                        <p class="mb-2 text-sm text-slate-300">Redes</p>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <label v-if="siteFacebookPlatform" class="flex items-center gap-2">
                                <input v-model="batchPlatforms.facebook" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                                Facebook
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="batchPlatforms.linkedin" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                                LinkedIn Jhon
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="batchPlatforms.linkedin_jessika" type="checkbox" class="rounded border-slate-600 bg-slate-950" />
                                LinkedIn Jessika
                            </label>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="mb-2 text-sm text-slate-300">Acción</p>
                            <label class="mb-2 flex items-center gap-2 text-sm">
                                <input v-model="batchDeliveryMode" type="radio" value="queue" class="rounded-full" />
                                Encolar en la app
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="batchDeliveryMode" type="radio" value="now" class="rounded-full" />
                                Publicar ahora
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-400">Enviar automáticamente (app)</label>
                            <input
                                v-model="batchQueuedAt"
                                type="datetime-local"
                                class="w-full rounded-xl border border-sky-800/40 bg-slate-950 px-3 py-2 text-sm"
                                :disabled="batchDeliveryMode !== 'queue'"
                            />
                            <label class="mb-1 mt-3 block text-xs text-slate-400">Programar en Facebook (Meta)</label>
                            <input
                                v-model="batchFacebookScheduledAt"
                                type="datetime-local"
                                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                            />
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-800 p-3">
                        <label class="mb-2 block text-xs text-slate-400">Texto</label>
                        <div class="mb-3 flex flex-wrap gap-3 text-sm">
                            <label class="flex items-center gap-2">
                                <input v-model="batchTextMode" type="radio" value="excerpt" class="rounded-full" />
                                Extracto por defecto
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="batchTextMode" type="radio" value="custom" class="rounded-full" />
                                Personalizado / IA
                            </label>
                        </div>
                        <label class="mb-1 block text-xs text-slate-400">Proveedor IA</label>
                        <select v-model="batchCaptionAiProvider" class="mb-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm">
                            <option v-for="opt in captionAiOptions" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
                                {{ opt.label }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="rounded-lg border border-violet-500/50 px-3 py-1.5 text-sm text-violet-300 hover:bg-violet-950/40 disabled:opacity-50"
                            :disabled="batchGenerating || batchCaptionAiProvider === 'none'"
                            @click="generateBatchCaptions"
                        >
                            {{ batchGenerating ? 'Generando…' : 'Generar textos con IA (selección)' }}
                        </button>
                    </div>

                    <div v-if="batchTextMode === 'custom'" class="max-h-64 space-y-3 overflow-y-auto">
                        <div
                            v-for="post in selectedPosts"
                            :key="post.id"
                            class="rounded-lg border border-slate-800 p-3"
                        >
                            <p class="mb-2 text-sm font-medium text-slate-200">{{ post.title }}</p>
                            <div v-for="platform in batchPlatformKeys()" :key="`${post.id}-${platform}`" class="mb-2">
                                <label class="mb-1 block text-xs text-slate-500">{{ platform }}</label>
                                <textarea
                                    :value="batchMessage(post.id, platform) || defaultMessageForPost(post)"
                                    rows="3"
                                    class="w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs"
                                    @input="setBatchMessage(post.id, platform, $event.target.value)"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-600 px-4 py-2 text-sm" @click="closeBatchModal">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                            :disabled="batchSubmitting || (batchDeliveryMode === 'queue' && !batchQueuedAt)"
                            @click="submitBatch"
                        >
                            {{ batchSubmitting ? 'Guardando…' : batchDeliveryMode === 'queue' ? 'Encolar' : 'Publicar ahora' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.schedule-datetime-wrap {
    position: relative;
}

.schedule-datetime-input {
    color-scheme: dark;
}

.schedule-datetime-trigger {
    border: none;
    background: transparent;
    cursor: pointer;
}
</style>
