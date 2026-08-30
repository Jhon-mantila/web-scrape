<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FilePicker from '@/Components/FilePicker.vue';

const props = defineProps({
    platformOptions: {
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

const form = useForm({
    title: '',
    notes: '',
    platforms: props.platformOptions.filter((p) => p.enabled && !p.coming_soon).map((p) => p.key),
    video: null,
    thumbnail: null,
});

function submit() {
    form.post(route('videos.store'), {
        forceFormData: true,
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
            <p class="mt-1 text-slate-400">MP4 + thumbnail JPG/PNG/WebP.</p>
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
                <label class="mb-2 block text-sm text-slate-300">Notas (opcional, para la IA)</label>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5"
                />
            </div>

            <div>
                <FilePicker
                    v-model="form.video"
                    label="Video (.mp4)"
                    accept="video/mp4,video/webm,video/quicktime"
                    choose-label="Elegir video"
                    empty-label="Ningún video seleccionado"
                    hint="Formatos: MP4, WebM o MOV."
                />
                <p v-if="form.errors.video" class="mt-1 text-sm text-red-400">{{ form.errors.video }}</p>
            </div>

            <div>
                <FilePicker
                    v-model="form.thumbnail"
                    label="Miniatura"
                    accept="image/jpeg,image/png,image/webp"
                    choose-label="Elegir imagen"
                    empty-label="Ninguna imagen seleccionada"
                    hint="JPG, PNG o WebP. Se usa en YouTube y Facebook."
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
                :disabled="form.processing"
            >
                {{ form.processing ? 'Subiendo…' : 'Subir video' }}
            </button>
        </form>
    </AppLayout>
</template>
