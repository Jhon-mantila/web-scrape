<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FilePicker from '@/Components/FilePicker.vue';

const props = defineProps({
    platformOptions: {
        type: Array,
        default: () => [],
    },
    captionAiOptions: {
        type: Array,
        default: () => [],
    },
});

const platformByKey = computed(() =>
    Object.fromEntries(props.platformOptions.map((p) => [p.key, p])),
);

const platformGroups = [
    { keys: ['youtube'] },
    { keys: ['facebook_esquinaweb', 'facebook_esquinagamers'], columns: 2 },
    { keys: ['linkedin', 'linkedin_jessika'], columns: 2 },
    { keys: ['tiktok'] },
];

const videoFile = ref(null);
const thumbnailFile = ref(null);

function isUploadFile(value) {
    if (value == null || typeof value !== 'object') {
        return false;
    }

    return typeof value.name === 'string'
        && value.name !== ''
        && typeof value.size === 'number';
}

const hasVideoSelected = computed(() => isUploadFile(videoFile.value));

const form = useForm({
    title: '',
    notes: '',
    caption_ai_provider: 'ollama_local',
    platforms: props.platformOptions.filter((p) => p.enabled && !p.coming_soon).map((p) => p.key),
});

function onVideoPicked(file) {
    videoFile.value = file;
    form.clearErrors('video');
}

function onThumbnailPicked(file) {
    thumbnailFile.value = file;
    form.clearErrors('thumbnail');
}

function submit() {
    if (!isUploadFile(videoFile.value)) {
        form.setError('video', 'Selecciona un archivo MP4, MOV o WebM.');

        return;
    }

    form
        .transform((data) => ({
            ...data,
            video: videoFile.value,
            thumbnail: isUploadFile(thumbnailFile.value) ? thumbnailFile.value : null,
        }))
        .post(route('videos.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                videoFile.value = null;
                thumbnailFile.value = null;
            },
        });
}

function togglePlatform(key) {
    if (form.platforms.includes(key)) {
        form.platforms = form.platforms.filter((p) => p !== key);
    } else {
        form.platforms.push(key);
    }
}
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold">Subir video</h2>
            <p class="mt-1 text-slate-400">
                MP4, MOV o WebM. La miniatura es opcional (YouTube y Facebook la usan solo si la cargas).
            </p>
        </div>

        <form @submit.prevent="submit" class="max-w-2xl space-y-6">
            <div>
                <label class="mb-2 block text-sm text-slate-300">Título</label>
                <input
                    v-model="form.title"
                    type="text"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5"
                    placeholder="Ej. Solo Leveling — Resumen capítulo 12"
                />
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-400">{{ form.errors.title }}</p>
            </div>

            <div>
                <p class="mb-3 text-sm text-slate-300">Generación de textos con IA</p>
                <div class="space-y-2">
                    <label
                        v-for="opt in captionAiOptions"
                        :key="opt.value"
                        class="flex cursor-pointer gap-3 rounded-xl border border-slate-800 px-4 py-3 hover:bg-slate-900"
                        :class="opt.disabled ? 'cursor-not-allowed opacity-50' : ''"
                    >
                        <input
                            v-model="form.caption_ai_provider"
                            type="radio"
                            name="caption_ai_provider"
                            :value="opt.value"
                            :disabled="opt.disabled"
                            class="mt-1"
                        />
                        <span class="text-sm">
                            <span class="font-medium text-slate-200">{{ opt.label }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">{{ opt.hint }}</span>
                        </span>
                    </label>
                </div>
                <p v-if="form.errors.caption_ai_provider" class="mt-1 text-sm text-red-400">
                    {{ form.errors.caption_ai_provider }}
                </p>
            </div>

            <div>
                <label class="mb-2 block text-sm text-slate-300">Notas (opcional, para la IA)</label>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5"
                    :disabled="form.caption_ai_provider === 'none'"
                />
            </div>

            <div>
                <FilePicker
                    :model-value="videoFile"
                    label="Video (MP4, MOV, WebM)"
                    accept="video/mp4,video/webm,video/quicktime,.mp4,.mov,.webm,video/*"
                    choose-label="Elegir video"
                    empty-label="Ningún video seleccionado"
                    hint="Formatos: MP4, WebM o MOV."
                    @update:model-value="onVideoPicked"
                />
                <p v-if="form.errors.video" class="mt-1 text-sm text-red-400">{{ form.errors.video }}</p>
            </div>

            <div>
                <FilePicker
                    :model-value="thumbnailFile"
                    label="Miniatura (opcional)"
                    @update:model-value="onThumbnailPicked"
                    accept="image/jpeg,image/png,image/webp"
                    choose-label="Elegir imagen"
                    empty-label="Sin miniatura — YouTube/Facebook usarán la suya"
                    hint="JPG, PNG o WebP. Solo se envía a YouTube y Facebook si cargas una."
                />
                <p v-if="form.errors.thumbnail" class="mt-1 text-sm text-red-400">{{ form.errors.thumbnail }}</p>
            </div>

            <div>
                <p class="mb-3 text-sm text-slate-300">Plataformas</p>
                <div class="space-y-2">
                    <div
                        v-for="group in platformGroups"
                        :key="group.keys.join('-')"
                        :class="group.columns === 2 ? 'grid gap-2 sm:grid-cols-2' : ''"
                    >
                        <label
                            v-for="key in group.keys"
                            :key="key"
                            class="flex items-center gap-3 rounded-xl border border-slate-800 px-4 py-3"
                            :class="platformByKey[key]?.coming_soon ? 'opacity-50' : 'cursor-pointer hover:bg-slate-900'"
                        >
                            <template v-if="platformByKey[key]">
                                <input
                                    type="checkbox"
                                    :disabled="platformByKey[key].coming_soon || !platformByKey[key].enabled"
                                    :checked="form.platforms.includes(key)"
                                    @change="togglePlatform(key)"
                                />
                                <span class="text-sm">{{ platformByKey[key].label }}</span>
                                <span v-if="platformByKey[key].coming_soon" class="text-xs text-slate-500">Próximamente</span>
                            </template>
                        </label>
                    </div>
                </div>
                <p v-if="form.errors.platforms" class="mt-1 text-sm text-red-400">{{ form.errors.platforms }}</p>
            </div>

            <button
                type="submit"
                class="rounded-lg bg-violet-600 px-5 py-2.5 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                :disabled="form.processing || !hasVideoSelected"
            >
                {{ form.processing ? 'Subiendo…' : 'Subir video' }}
            </button>
        </form>
    </AppLayout>
</template>
