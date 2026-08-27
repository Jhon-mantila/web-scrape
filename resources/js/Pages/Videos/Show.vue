<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    video: Object,
});

const thumbnailInput = ref(null);
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

const displayThumbnail = computed(() => thumbnailPreview.value || props.video.thumbnail_url);

const publishableCount = computed(() =>
    props.video.publications.filter(
        (p) => !p.coming_soon && p.status !== 'published' && p.status !== 'scheduled',
    ).length,
);

watch(
    () => props.video,
    (video) => {
        form.title = video.title;
        form.notes = video.notes || '';
        form.thumbnail = null;
        thumbnailPreview.value = null;
        if (thumbnailInput.value) {
            thumbnailInput.value.value = '';
        }
        form.publications = video.publications.map((p) => ({
            id: p.id,
            caption_edited: p.caption_edited || p.caption_generated || '',
            scheduled_at: p.scheduled_at ? p.scheduled_at.slice(0, 16) : '',
        }));
    },
    { deep: true },
);

const payload = () => ({
    title: form.title,
    notes: form.notes,
    publications: form.publications,
});

function onThumbnailChange(event) {
    const file = event.target.files?.[0] ?? null;
    form.thumbnail = file;

    if (thumbnailPreview.value) {
        URL.revokeObjectURL(thumbnailPreview.value);
    }

    thumbnailPreview.value = file ? URL.createObjectURL(file) : null;
}

function save() {
    form.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('videos.update', props.video.id), {
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

function publish(publicationId) {
    router.post(route('videos.publications.publish', [props.video.id, publicationId]), payload());
}

function publishAll() {
    if (!confirm(`¿Enviar a ${publishableCount.value} plataforma(s)?`)) {
        return;
    }

    router.post(route('videos.publish-all', props.video.id), payload());
}

function deleteVideo() {
    if (!confirm(`¿Eliminar "${props.video.title}"? Se borrarán el video, la miniatura y sus publicaciones.`)) {
        return;
    }

    router.delete(route('videos.destroy', props.video.id));
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link :href="route('videos.index')" class="text-sm text-slate-400 hover:text-white">← Volver</Link>
                <h2 class="mt-2 text-2xl font-semibold">{{ form.title }}</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-if="publishableCount > 0"
                    type="button"
                    class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium hover:bg-emerald-500"
                    @click="publishAll"
                >
                    Enviar a todas ({{ publishableCount }})
                </button>
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
                    <img :src="displayThumbnail" :alt="form.title" class="w-full rounded-2xl object-cover" />
                    <div>
                        <label class="mb-2 block text-sm text-slate-300">Cambiar miniatura</label>
                        <input
                            ref="thumbnailInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-slate-400"
                            @change="onThumbnailChange"
                        />
                        <p v-if="form.errors.thumbnail" class="mt-1 text-sm text-red-400">{{ form.errors.thumbnail }}</p>
                        <p v-else-if="form.thumbnail" class="mt-1 text-xs text-emerald-400">
                            Nueva imagen seleccionada. Pulsa «Guardar cambios» para aplicarla.
                        </p>
                    </div>
                </div>
                <video :src="video.video_url" controls class="w-full rounded-2xl bg-black" />
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
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-medium">{{ pub.platform_label }}</h3>
                            <p class="text-sm text-slate-400">{{ pub.status_icon }} {{ pub.status_label }}</p>
                        </div>
                        <button
                            v-if="!pub.coming_soon && pub.status !== 'published' && pub.status !== 'scheduled'"
                            type="button"
                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm hover:bg-emerald-500"
                            @click="publish(pub.id)"
                        >
                            Enviar
                        </button>
                    </div>

                    <textarea
                        v-model="form.publications[index].caption_edited"
                        rows="5"
                        class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm"
                        :placeholder="pub.coming_soon ? 'Próximamente' : 'Texto para publicar…'"
                        :disabled="pub.coming_soon"
                    />

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Programar publicación</label>
                            <input
                                v-model="form.publications[index].scheduled_at"
                                type="datetime-local"
                                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                                :disabled="pub.coming_soon"
                            />
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
