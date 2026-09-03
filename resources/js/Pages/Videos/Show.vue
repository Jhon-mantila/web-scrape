<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FilePicker from '@/Components/FilePicker.vue';

const props = defineProps({
    video: Object,
});

const thumbnailPreview = ref(null);

const form = useForm({
    title: props.video.title,
    notes: props.video.notes || '',
    thumbnail: null,
    publications: props.video.publications.map((p) => ({
        id: p.id,
        caption_edited: p.caption_edited || p.caption_generated || '',
        scheduled_at: p.scheduled_at ? p.scheduled_at.slice(0, 16) : '',
    })),
});

const displayThumbnail = computed(() => thumbnailPreview.value || props.video.thumbnail_url || null);

const publishablePublications = computed(() =>
    props.video.publications.filter(
        (p) => !p.coming_soon && p.status !== 'published' && p.status !== 'scheduled',
    ),
);

const publishableCount = computed(() => publishablePublications.value.length);

const selectedPublicationIds = ref([]);

const selectedPublishableCount = computed(() =>
    selectedPublicationIds.value.filter((id) =>
        publishablePublications.value.some((p) => p.id === id),
    ).length,
);

function isPublishable(pub) {
    return !pub.coming_soon && pub.status !== 'published' && pub.status !== 'scheduled';
}

function syncPublicationSelection() {
    const publishableIds = publishablePublications.value.map((p) => p.id);

    selectedPublicationIds.value = selectedPublicationIds.value.filter((id) =>
        publishableIds.includes(id),
    );

    if (selectedPublicationIds.value.length === 0 && publishableIds.length > 0) {
        selectedPublicationIds.value = [...publishableIds];
    }
}

function togglePublicationSelection(id) {
    if (selectedPublicationIds.value.includes(id)) {
        selectedPublicationIds.value = selectedPublicationIds.value.filter((item) => item !== id);
    } else {
        selectedPublicationIds.value = [...selectedPublicationIds.value, id];
    }
}

function selectAllPublishable() {
    selectedPublicationIds.value = publishablePublications.value.map((p) => p.id);
}

function selectNoPublishable() {
    selectedPublicationIds.value = [];
}

const hasLinkedIn = computed(() =>
    props.video.publications.some((p) => isLinkedIn(p.platform)),
);

function isLinkedIn(platform) {
    return platform === 'linkedin' || platform === 'linkedin_jessika';
}

function linkedInObservations(pub) {
    const hints = pub.platform_hints;
    if (!hints) {
        return [];
    }

    const items = [];

    if (hints.scheduling === false) {
        items.push('No se puede programar: LinkedIn publica de inmediato.');
    }

    if (hints.thumbnail === false) {
        items.push('No usa miniatura personalizada; LinkedIn genera una del video.');
    }

    if (hints.max_video_gb) {
        items.push(`Video máximo ${hints.max_video_gb} GB (MP4 recomendado).`);
    }

    if (hints.max_duration_minutes) {
        items.push(`Duración recomendada: hasta ~${hints.max_duration_minutes} minutos.`);
    }

    if (props.video.max_video_mb) {
        items.push(`Límite de subida en Esquina AI: ${props.video.max_video_mb} MB.`);
    }

    return items;
}

watch(
    () => props.video,
    (video) => {
        form.title = video.title;
        form.notes = video.notes || '';
        form.thumbnail = null;
        thumbnailPreview.value = null;
        form.publications = video.publications.map((p) => ({
            id: p.id,
            caption_edited: p.caption_edited || p.caption_generated || '',
            scheduled_at: p.scheduled_at ? p.scheduled_at.slice(0, 16) : '',
        }));
        syncPublicationSelection();
    },
    { deep: true, immediate: true },
);

const payload = () => ({
    title: form.title,
    notes: form.notes,
    publications: form.publications,
});

function onThumbnailSelected(file) {
    if (thumbnailPreview.value) {
        URL.revokeObjectURL(thumbnailPreview.value);
    }

    thumbnailPreview.value = file ? URL.createObjectURL(file) : null;
}

function save() {
    form.transform((data) => {
        const payload = {
            title: data.title,
            notes: data.notes,
            publications: data.publications,
            _method: 'put',
        };

        if (data.thumbnail) {
            payload.thumbnail = data.thumbnail;
        }

        return payload;
    }).post(route('videos.update', props.video.id), {
        forceFormData: true,
        onSuccess: () => {
            if (thumbnailPreview.value) {
                URL.revokeObjectURL(thumbnailPreview.value);
                thumbnailPreview.value = null;
            }
            form.thumbnail = null;
        },
    });
}

function generateCaptions() {
    router.post(route('videos.generate-captions', props.video.id));
}

function generateTitle() {
    router.post(route('videos.generate-title', props.video.id));
}

function isFacebook(platform) {
    return platform.startsWith('facebook_');
}

function facebookMaxVideoGb() {
    return props.video.facebook_max_video_gb ?? 2;
}

function facebookVideoTooLarge() {
    const maxBytes = facebookMaxVideoGb() * 1024 * 1024 * 1024;

    return (props.video.video_size_bytes ?? 0) > maxBytes;
}

function facebookContentTypeLabel() {
    return 'Video de página';
}

function facebookSizeHint(pub) {
    const maxGb = pub.platform_hints?.max_video_gb ?? facebookMaxVideoGb();
    const contentType = isFacebook(pub.platform)
        ? facebookContentTypeLabel()
        : (pub.platform_hints?.content_type ?? 'Video de página');
    const sizeLabel = props.video.video_size_label ?? 'desconocido';
    const dims =
        props.video.video_width && props.video.video_height
            ? `${props.video.video_width}×${props.video.video_height}`
            : null;

    return [contentType, dims, sizeLabel, `API Meta: máx. ${maxGb} GB`].filter(Boolean).join(' · ');
}

function canSend(pub) {
    if (!isPublishable(pub)) {
        return false;
    }

    if (isFacebook(pub.platform) && facebookVideoTooLarge()) {
        return false;
    }

    return true;
}

function publish(publicationId) {
    router.post(route('videos.publications.publish', [props.video.id, publicationId]), payload());
}

function facebookVideoId(pub) {
    if (pub.external_id) {
        return pub.external_id;
    }

    const response = pub.api_response;

    if (!response) {
        return null;
    }

    return response.facebook_video_id || response.id || null;
}

function deleteFromFacebook(pub) {
    const videoId = facebookVideoId(pub);

    if (!videoId) {
        return;
    }

    if (!confirm(`¿Eliminar este video de Facebook (${pub.platform_label})? Luego podrás enviarlo de nuevo desde aquí.`)) {
        return;
    }

    router.delete(route('videos.publications.facebook.destroy', [props.video.id, pub.id]));
}

function canDeleteFromFacebook(pub) {
    return isFacebook(pub.platform) && facebookVideoId(pub);
}

function publishSelected() {
    const selected = selectedPublishableCount.value;
    const total = publishableCount.value;

    if (selected === 0) {
        return;
    }

    const message = selected === total
        ? `¿Enviar a las ${selected} plataforma(s)?`
        : `¿Enviar a ${selected} de ${total} plataforma(s) seleccionada(s)?`;

    if (!confirm(message)) {
        return;
    }

    router.post(route('videos.publish-all', props.video.id), {
        ...payload(),
        publication_ids: selectedPublicationIds.value.filter((id) =>
            publishablePublications.value.some((p) => p.id === id),
        ),
    });
}

function deleteVideo() {
    if (!confirm(`¿Eliminar "${props.video.title}"? Se borrarán el video, la miniatura y sus publicaciones.`)) {
        return;
    }

    router.delete(route('videos.destroy', props.video.id));
}

function openSchedulePicker(event) {
    const wrap = event.currentTarget.closest('.schedule-datetime-wrap');
    const input = wrap?.querySelector('.schedule-datetime-input');

    if (!input || input.disabled) {
        return;
    }

    if (typeof input.showPicker === 'function') {
        try {
            input.showPicker();

            return;
        } catch {
            // Algunos navegadores lanzan si no hubo gesto directo; fallback abajo.
        }
    }

    input.focus();
    input.click();
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link :href="route('videos.index')" class="text-sm text-slate-400 hover:text-white">← Volver</Link>
                <h2 class="mt-2 text-2xl font-semibold">{{ form.title }}</h2>
            </div>
            <div v-if="publishableCount > 0" class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="selectedPublishableCount === 0"
                    @click="publishSelected"
                >
                    Enviar seleccionados ({{ selectedPublishableCount }} de {{ publishableCount }})
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800"
                    @click="selectAllPublishable"
                >
                    Todas
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800"
                    @click="selectNoPublishable"
                >
                    Ninguna
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-red-900/60 px-4 py-2 text-sm text-red-400 hover:bg-red-950/40"
                    @click="deleteVideo"
                >
                    Eliminar video
                </button>
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-[320px_1fr]">
            <div class="space-y-4">
                <div class="space-y-3">
                    <img
                        v-if="displayThumbnail"
                        :src="displayThumbnail"
                        :alt="form.title"
                        class="w-full rounded-2xl object-cover"
                    />
                    <div
                        v-else
                        class="flex aspect-video w-full items-center justify-center rounded-2xl border border-dashed border-slate-700 bg-slate-900/80 text-sm text-slate-500"
                    >
                        Sin miniatura
                    </div>
                    <div>
                        <FilePicker
                            v-model="form.thumbnail"
                            label="Cambiar miniatura (opcional)"
                            accept="image/jpeg,image/png,image/webp"
                            choose-label="Elegir imagen"
                            empty-label="Sin miniatura — no se enviará a YouTube ni Facebook"
                            :hint="hasLinkedIn ? 'Opcional. Si cargas una, aplica a YouTube y Facebook; LinkedIn no la utiliza.' : 'Opcional. Solo se envía a YouTube y Facebook si cargas una.'"
                            @change="onThumbnailSelected"
                        />
                        <p v-if="form.errors.thumbnail" class="mt-1 text-sm text-red-400">{{ form.errors.thumbnail }}</p>
                        <p v-else-if="form.thumbnail" class="mt-1 text-xs text-emerald-400">
                            Nueva imagen seleccionada. Pulsa «Guardar cambios» para aplicarla.
                        </p>
                    </div>
                </div>
                <video :src="video.video_url" controls class="w-full rounded-2xl bg-black" />
                <div class="rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3 text-sm">
                    <p class="font-medium text-slate-200">Tamaño del archivo</p>
                    <p class="mt-1 text-slate-400">
                        {{ video.video_size_label ?? 'desconocido' }}
                        <span v-if="video.video_size_mb"> ({{ video.video_size_mb }} MB)</span>
                    </p>
                    <p class="mt-2 text-xs text-slate-500">
                        Facebook (API): máx. {{ facebookMaxVideoGb() }} GB ·
                        {{ facebookContentTypeLabel() }}
                        <span v-if="video.video_width && video.video_height">
                            · {{ video.video_width }}×{{ video.video_height }}
                        </span>
                    </p>
                    <p
                        v-if="facebookVideoTooLarge()"
                        class="mt-2 text-xs text-amber-400"
                    >
                        Este video supera el límite de 2 GB de la API de Facebook.
                    </p>
                </div>
                <button
                    type="button"
                    class="w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium hover:bg-violet-500"
                    @click="generateCaptions"
                >
                    Generar textos con IA
                </button>
            </div>

            <form @submit.prevent="save" class="space-y-6">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="text-sm text-slate-300">Título</label>
                        <button
                            type="button"
                            class="text-sm text-violet-400 hover:text-violet-300"
                            @click="generateTitle"
                        >
                            Generar título con IA
                        </button>
                    </div>
                    <input v-model="form.title" type="text" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5" />
                </div>

                <div>
                    <label class="mb-2 block text-sm text-slate-300">Notas (contexto para IA)</label>
                    <textarea v-model="form.notes" rows="2" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5" />
                </div>

                <div
                    v-for="(pub, index) in video.publications"
                    :key="pub.id"
                    class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
                >
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <label
                                v-if="canSend(pub)"
                                class="mt-0.5 flex shrink-0 cursor-pointer items-center"
                                :title="`Incluir ${pub.platform_label} en el envío masivo`"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4 rounded border-slate-600 bg-slate-950 text-emerald-600 focus:ring-emerald-500/40"
                                    :checked="selectedPublicationIds.includes(pub.id)"
                                    @change="togglePublicationSelection(pub.id)"
                                />
                            </label>
                            <div class="min-w-0">
                                <h3 class="font-medium">{{ pub.platform_label }}</h3>
                                <p class="text-sm text-slate-400">{{ pub.status_icon }} {{ pub.status_label }}</p>
                                <p v-if="isFacebook(pub.platform)" class="mt-1 text-xs text-slate-500">
                                    {{ facebookSizeHint(pub) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="canSend(pub)"
                                type="button"
                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm hover:bg-emerald-500"
                                @click="publish(pub.id)"
                            >
                                Enviar
                            </button>
                            <button
                                v-if="canDeleteFromFacebook(pub)"
                                type="button"
                                class="rounded-lg border border-red-900/60 px-3 py-1.5 text-sm text-red-400 hover:bg-red-950/40"
                                @click="deleteFromFacebook(pub)"
                            >
                                Eliminar de Facebook
                            </button>
                        </div>
                    </div>

                    <textarea
                        v-model="form.publications[index].caption_edited"
                        rows="5"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm"
                        :placeholder="pub.coming_soon ? 'Próximamente' : 'Texto para publicar…'"
                        :disabled="pub.coming_soon"
                    />

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div v-if="!isLinkedIn(pub.platform)" class="schedule-datetime-group">
                            <label
                                :for="`schedule-${pub.id}`"
                                class="mb-1.5 block text-xs font-medium text-slate-400"
                            >
                                Programar publicación
                            </label>
                            <div class="schedule-datetime-wrap">
                                <input
                                    :id="`schedule-${pub.id}`"
                                    v-model="form.publications[index].scheduled_at"
                                    type="datetime-local"
                                    class="schedule-datetime-input"
                                    :disabled="pub.coming_soon"
                                />
                                <button
                                    type="button"
                                    class="schedule-datetime-trigger"
                                    :disabled="pub.coming_soon"
                                    aria-label="Abrir calendario"
                                    @click="openSchedulePicker"
                                >
                                    <svg
                                        class="schedule-datetime-icon"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.75"
                                        aria-hidden="true"
                                    >
                                        <rect x="3" y="4" width="18" height="18" rx="2" />
                                        <path d="M16 2v4M8 2v4M3 10h18" />
                                    </svg>
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-slate-500">
                                Clic en el campo o en el calendario · mín. 10 min en el futuro (Facebook)
                            </p>
                        </div>
                        <div v-else class="rounded-lg border border-slate-700/80 bg-slate-950/80 px-3 py-2">
                            <p class="mb-1 text-xs font-medium text-slate-400">Observaciones LinkedIn</p>
                            <ul class="space-y-1 text-xs text-slate-500">
                                <li v-for="(note, noteIndex) in linkedInObservations(pub)" :key="noteIndex">
                                    • {{ note }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="pub.external_url" class="flex items-end">
                            <a :href="pub.external_url" target="_blank" class="text-sm text-violet-400 hover:underline">
                                Ver publicación →
                            </a>
                        </div>
                    </div>

                    <p v-if="pub.status === 'scheduled' && pub.scheduled_at" class="mt-2 text-sm text-emerald-400">
                        Se publicará el {{ new Date(pub.scheduled_at).toLocaleString('es-CO') }}
                    </p>
                    <p v-if="pub.last_error" class="mt-3 text-sm text-red-400">{{ pub.last_error }}</p>
                    <p
                        v-else-if="isFacebook(pub.platform) && isPublishable(pub)"
                        class="mt-3 text-xs text-slate-500"
                    >
                        Facebook puede tardar varios minutos en procesar el video. No cierres la página hasta ver el resultado.
                    </p>
                    <p
                        v-else-if="isFacebook(pub.platform) && facebookVideoTooLarge() && isPublishable(pub)"
                        class="mt-3 text-sm text-amber-400"
                    >
                        No se puede enviar: el video ({{ video.video_size_label }}) supera el límite de
                        {{ facebookMaxVideoGb() }} GB de la API de Facebook.
                    </p>
                    <p
                        v-else-if="pub.api_response?.thumbnail_upload && !pub.api_response.thumbnail_upload.ok"
                        class="mt-3 text-sm text-amber-400"
                    >
                        Video publicado, pero miniatura no subida: {{ pub.api_response.thumbnail_upload.error }}
                    </p>
                </div>

                <button
                    type="submit"
                    class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                    :disabled="form.processing"
                >
                    Guardar cambios
                </button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.schedule-datetime-group {
    min-width: 0;
}

.schedule-datetime-wrap {
    position: relative;
}

.schedule-datetime-icon {
    pointer-events: none;
    height: 1.25rem;
    width: 1.25rem;
    color: rgb(248 250 252);
}

.schedule-datetime-trigger {
    position: absolute;
    right: 0.375rem;
    top: 50%;
    z-index: 2;
    display: flex;
    height: 2rem;
    width: 2rem;
    transform: translateY(-50%);
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 0.5rem;
    background: transparent;
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.schedule-datetime-trigger:hover:not(:disabled) {
    background-color: rgb(51 65 85 / 0.65);
}

.schedule-datetime-trigger:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px rgb(167 139 250 / 0.6);
}

.schedule-datetime-trigger:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}

.schedule-datetime-input {
    width: 100%;
    cursor: pointer;
    border-radius: 0.75rem;
    border: 1px solid rgb(71 85 105);
    background-color: rgb(15 23 42);
    padding: 0.625rem 3rem 0.625rem 0.875rem;
    font-size: 0.875rem;
    line-height: 1.25rem;
    color: rgb(226 232 240);
    color-scheme: dark;
    transition:
        border-color 0.15s ease,
        box-shadow 0.15s ease,
        background-color 0.15s ease;
}

.schedule-datetime-input:hover:not(:disabled) {
    border-color: rgb(100 116 139);
    background-color: rgb(30 41 59);
}

.schedule-datetime-wrap:has(.schedule-datetime-input:focus) .schedule-datetime-icon,
.schedule-datetime-wrap:has(.schedule-datetime-input:focus) .schedule-datetime-trigger {
    color: rgb(255 255 255);
}

.schedule-datetime-input:focus,
.schedule-datetime-input:focus-visible {
    outline: none;
    border-color: rgb(167 139 250);
    background-color: rgb(30 41 59);
    box-shadow:
        0 0 0 3px rgb(139 92 246 / 0.25),
        inset 0 0 0 1px rgb(167 139 250 / 0.35);
}

.schedule-datetime-input:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

/* Ocultar icono nativo gris; usamos el botón blanco a la derecha */
.schedule-datetime-input::-webkit-calendar-picker-indicator {
    opacity: 0;
    position: absolute;
    right: 0;
    width: 3rem;
    height: 100%;
    cursor: pointer;
}

.schedule-datetime-input::-webkit-datetime-edit-fields-wrapper {
    padding: 0;
}

.schedule-datetime-input::-webkit-datetime-edit {
    color: rgb(226 232 240);
}

.schedule-datetime-input::-webkit-datetime-edit-text,
.schedule-datetime-input::-webkit-datetime-edit-month-field,
.schedule-datetime-input::-webkit-datetime-edit-day-field,
.schedule-datetime-input::-webkit-datetime-edit-year-field,
.schedule-datetime-input::-webkit-datetime-edit-hour-field,
.schedule-datetime-input::-webkit-datetime-edit-minute-field {
    color: rgb(226 232 240);
    padding: 0 0.125rem;
}

.schedule-datetime-input:focus::-webkit-datetime-edit-month-field,
.schedule-datetime-input:focus::-webkit-datetime-edit-day-field,
.schedule-datetime-input:focus::-webkit-datetime-edit-year-field,
.schedule-datetime-input:focus::-webkit-datetime-edit-hour-field,
.schedule-datetime-input:focus::-webkit-datetime-edit-minute-field {
    background-color: rgb(139 92 246 / 0.2);
    border-radius: 0.25rem;
}
</style>
