<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    platformOptions: {
        type: Array,
        default: () => [],
    },
});

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
                <label class="mb-2 block text-sm text-slate-300">Video (.mp4)</label>
                <input
                    type="file"
                    accept="video/mp4,video/webm,video/quicktime"
                    class="block w-full text-sm text-slate-400"
                    @change="form.video = $event.target.files[0]"
                />
                <p v-if="form.errors.video" class="mt-1 text-sm text-red-400">{{ form.errors.video }}</p>
            </div>

            <div>
                <label class="mb-2 block text-sm text-slate-300">Thumbnail</label>
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm text-slate-400"
                    @change="form.thumbnail = $event.target.files[0]"
                />
                <p v-if="form.errors.thumbnail" class="mt-1 text-sm text-red-400">{{ form.errors.thumbnail }}</p>
            </div>

            <div>
                <p class="mb-3 text-sm text-slate-300">Plataformas</p>
                <div class="space-y-2">
                    <label
                        v-for="platform in platformOptions"
                        :key="platform.key"
                        class="flex items-center gap-3 rounded-xl border border-slate-800 px-4 py-3"
                        :class="platform.coming_soon ? 'opacity-50' : 'cursor-pointer hover:bg-slate-900'"
                    >
                        <input
                            type="checkbox"
                            :disabled="platform.coming_soon || !platform.enabled"
                            :checked="form.platforms.includes(platform.key)"
                            @change="togglePlatform(platform.key)"
                        />
                        <span>{{ platform.label }}</span>
                        <span v-if="platform.coming_soon" class="text-xs text-slate-500">Próximamente</span>
                    </label>
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
