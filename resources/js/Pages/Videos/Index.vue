<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    videos: {
        type: Array,
        default: () => [],
    },
});

const selected = ref([]);

const allSelected = computed({
    get: () => props.videos.length > 0 && selected.value.length === props.videos.length,
    set: (checked) => {
        selected.value = checked ? props.videos.map((video) => video.id) : [];
    },
});

const selectedCount = computed(() => selected.value.length);

function toggleSelected(id) {
    if (selected.value.includes(id)) {
        selected.value = selected.value.filter((item) => item !== id);
    } else {
        selected.value = [...selected.value, id];
    }
}

function deleteSelected() {
    if (selectedCount.value === 0) {
        return;
    }

    if (!confirm(`¿Eliminar ${selectedCount.value} video(s)? Se borrarán videos, miniaturas y registros.`)) {
        return;
    }

    router.post(route('videos.bulk-destroy'), { ids: selected.value }, {
        onSuccess: () => {
            selected.value = [];
        },
    });
}

function deleteAll() {
    if (props.videos.length === 0) {
        return;
    }

    if (!confirm(`¿Eliminar TODO el historial (${props.videos.length} video(s))? Esta acción no se puede deshacer.`)) {
        return;
    }

    router.post(route('videos.bulk-destroy'), { all: true }, {
        onSuccess: () => {
            selected.value = [];
        },
    });
}

function deleteOne(video) {
    if (!confirm(`¿Eliminar "${video.title}"? Se borrarán el video, la miniatura y sus publicaciones.`)) {
        return;
    }

    router.delete(route('videos.destroy', video.id));
}
</script>

<template>
    <AppLayout>
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
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

        <div v-else class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-900/60 px-5 py-4">
                <label class="flex items-center gap-3 text-sm text-slate-300">
                    <input
                        v-model="allSelected"
                        type="checkbox"
                        class="rounded border-slate-600 bg-slate-950"
                    />
                    Seleccionar todos ({{ videos.length }})
                </label>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-red-900/60 px-3 py-1.5 text-sm text-red-400 hover:bg-red-950/40 disabled:opacity-40"
                        :disabled="selectedCount === 0"
                        @click="deleteSelected"
                    >
                        Eliminar seleccionados ({{ selectedCount }})
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-red-700 px-3 py-1.5 text-sm text-red-300 hover:bg-red-950/50"
                        @click="deleteAll"
                    >
                        Eliminar todo el historial
                    </button>
                </div>
            </div>

            <article
                v-for="video in videos"
                :key="video.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
                :class="selected.includes(video.id) ? 'ring-1 ring-red-500/40' : ''"
            >
                <div class="flex gap-5">
                    <div class="flex items-start pt-1">
                        <input
                            :checked="selected.includes(video.id)"
                            type="checkbox"
                            class="rounded border-slate-600 bg-slate-950"
                            @change="toggleSelected(video.id)"
                        />
                    </div>
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
                                <button
                                    type="button"
                                    class="rounded-lg border border-red-900/60 px-3 py-1.5 text-sm text-red-400 hover:bg-red-950/40"
                                    @click="deleteOne(video)"
                                >
                                    Eliminar
                                </button>
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
