<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FilePicker from '@/Components/FilePicker.vue';

const props = defineProps({
    video: {
        type: Object,
        required: true,
    },
    job: {
        type: Object,
        default: null,
    },
    defaults: {
        type: Object,
        default: () => ({}),
    },
    modeOptions: {
        type: Array,
        default: () => [],
    },
    framingOptions: {
        type: Array,
        default: () => [],
    },
    fitFillOptions: {
        type: Array,
        default: () => [],
    },
    workerOnline: {
        type: Boolean,
        default: false,
    },
});

const player = ref(null);
const currentTimeMs = ref(0);
const durationMs = ref(0);
const segments = ref([]);
const selectedLocalId = ref(null);
const previewUntilMs = ref(null);
let pollTimer = null;

const form = useForm({
    target_clip_count: props.defaults.target_clip_count ?? 7,
    min_duration_seconds: props.defaults.min_duration_seconds ?? 15,
    max_duration_seconds: props.defaults.max_duration_seconds ?? 60,
    mode: props.defaults.mode ?? 'smart',
    framing: props.defaults.framing ?? 'smart_crop',
    fit_fill: props.defaults.fit_fill ?? 'blur',
    fit_part_label: props.defaults.fit_part_label ?? true,
    fit_part_label_color: props.defaults.fit_part_label_color ?? '#FFFFFF',
    fit_template: null,
});

const status = computed(() => props.job?.status ?? null);
const awaiting = computed(() => status.value === 'awaiting_approval');
const waiting = computed(() => props.job?.needs_poll === true);
const canQueue = computed(() => !props.job || props.job.is_active !== true);
const completed = computed(() => status.value === 'completed');
const failed = computed(() => status.value === 'failed');

const outputDurationMs = computed(() => {
    if (durationMs.value > 0) {
        return durationMs.value;
    }

    if (props.job?.duration_ms) {
        return props.job.duration_ms;
    }

    const last = segments.value.at(-1);

    return last ? last.end_ms : 0;
});

const playheadPercent = computed(() => {
    if (outputDurationMs.value <= 0) {
        return 0;
    }

    return Math.min(100, (currentTimeMs.value / outputDurationMs.value) * 100);
});

const overlapError = computed(() => {
    const ordered = [...segments.value].sort((a, b) => a.start_ms - b.start_ms);

    for (let i = 0; i < ordered.length; i += 1) {
        if (ordered[i].end_ms <= ordered[i].start_ms) {
            return 'Cada clip debe terminar después de empezar.';
        }

        if (i > 0 && ordered[i].start_ms < ordered[i - 1].end_ms) {
            return 'Los clips no pueden solaparse.';
        }
    }

    return null;
});

const barColors = ['bg-violet-500/80', 'bg-emerald-500/80', 'bg-amber-500/80', 'bg-sky-500/80', 'bg-rose-500/80'];

function cloneSegments(list) {
    return (list ?? []).map((segment, index) => ({
        localId: segment.id ? `db-${segment.id}` : `new-${index}-${segment.start_ms}`,
        id: segment.id ?? null,
        start_ms: segment.start_ms,
        end_ms: segment.end_ms,
        source: segment.source,
        warnings: segment.warnings ?? [],
        output_video: segment.output_video ?? null,
    }));
}

function formatMs(ms) {
    const total = Math.max(0, Math.round((ms ?? 0) / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function msToInput(ms) {
    return Math.round((ms ?? 0) / 100) / 10;
}

function inputToMs(value) {
    const parsed = Number(value);

    if (Number.isNaN(parsed) || parsed < 0) {
        return 0;
    }

    return Math.round(parsed * 1000);
}

function barStyle(segment) {
    const duration = outputDurationMs.value;

    if (duration <= 0) {
        return { left: '0%', width: '0%' };
    }

    return {
        left: `${(segment.start_ms / duration) * 100}%`,
        width: `${((segment.end_ms - segment.start_ms) / duration) * 100}%`,
    };
}

function stopPoll() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function startPoll() {
    stopPoll();

    if (!waiting.value) {
        return;
    }

    pollTimer = window.setInterval(() => {
        router.reload({ only: ['job', 'workerOnline'], preserveScroll: true, preserveState: true });
    }, 2500);
}

function onTimeUpdate() {
    const seconds = player.value?.currentTime ?? 0;
    currentTimeMs.value = Math.round(seconds * 1000);

    if (previewUntilMs.value !== null && currentTimeMs.value >= previewUntilMs.value) {
        player.value?.pause();
        previewUntilMs.value = null;
    }
}

function onLoadedMetadata() {
    const seconds = player.value?.duration;

    if (Number.isFinite(seconds) && seconds > 0) {
        durationMs.value = Math.round(seconds * 1000);
    }
}

function seekTo(ms) {
    if (!player.value) {
        return;
    }

    player.value.currentTime = Math.max(0, ms / 1000);
    currentTimeMs.value = ms;
}

function preview(segment) {
    selectedLocalId.value = segment.localId;
    previewUntilMs.value = segment.end_ms;
    seekTo(segment.start_ms);
    player.value?.play();
}

function seekFromTimeline(event) {
    const duration = outputDurationMs.value;

    if (duration <= 0) {
        return;
    }

    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    seekTo(Math.round(ratio * duration));
}

function addSegment() {
    const minMs = (form.min_duration_seconds || 15) * 1000;
    const start = currentTimeMs.value;
    const end = Math.min(
        outputDurationMs.value || start + minMs,
        start + minMs,
    );

    if (end <= start) {
        return;
    }

    const next = {
        localId: `new-${Date.now()}`,
        id: null,
        start_ms: start,
        end_ms: end,
        source: 'manual',
        warnings: [],
        output_video: null,
    };

    segments.value = [...segments.value, next].sort((a, b) => a.start_ms - b.start_ms);
    selectedLocalId.value = next.localId;
}

function removeSegment(localId) {
    segments.value = segments.value.filter((segment) => segment.localId !== localId);
}

function updateStart(segment, value) {
    segment.start_ms = inputToMs(value);
}

function updateEnd(segment, value) {
    segment.end_ms = inputToMs(value);
}

function queueAnalysis() {
    form.transform((data) => ({
        ...data,
        fit_part_label: data.fit_part_label ? 1 : 0,
    })).post(route('videos.split.store', props.video.id), {
        forceFormData: true,
    });
}

function approve() {
    if (!props.job || overlapError.value || segments.value.length === 0) {
        return;
    }

    router.post(route('videos.split.approve', [props.video.id, props.job.id]), {
        segments: segments.value.map((segment) => ({
            ...(segment.id ? { id: segment.id } : {}),
            start_ms: segment.start_ms,
            end_ms: segment.end_ms,
        })),
    });
}

function cancelJob() {
    if (!props.job) {
        return;
    }

    if (!confirm('¿Cancelar este trabajo? Podrás lanzar un análisis nuevo.')) {
        return;
    }

    router.post(route('videos.split.cancel', [props.video.id, props.job.id]));
}

watch(
    () => [props.job?.id, props.job?.status, JSON.stringify(props.job?.segments ?? [])],
    () => {
        if (awaiting.value || completed.value) {
            segments.value = cloneSegments(props.job?.segments);
        }
    },
    { immediate: true },
);

watch(waiting, () => {
    startPoll();
}, { immediate: true });

onMounted(() => {
    startPoll();
});

onBeforeUnmount(() => {
    stopPoll();
});
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link :href="route('videos.show', video.id)" class="text-sm text-slate-400 hover:text-white">
                    ← Volver al video
                </Link>
                <h2 class="mt-2 text-2xl font-semibold">Cortar para Shorts</h2>
                <p class="mt-1 text-slate-400">{{ video.title }}</p>
            </div>
            <button
                v-if="job?.is_active"
                type="button"
                class="rounded-lg border border-red-900/60 px-4 py-2 text-sm text-red-400 hover:bg-red-950/40"
                @click="cancelJob"
            >
                Cancelar trabajo
            </button>
        </div>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
            <div class="space-y-4">
                <video
                    v-if="video.video_url && !video.video_missing"
                    ref="player"
                    :src="video.video_url"
                    controls
                    class="w-full rounded-2xl bg-black"
                    @timeupdate="onTimeUpdate"
                    @loadedmetadata="onLoadedMetadata"
                />
                <div
                    v-else
                    class="flex aspect-video w-full items-center justify-center rounded-2xl border border-dashed border-red-900/60 bg-red-950/20 text-sm text-red-300"
                >
                    El archivo original no está en el servidor.
                </div>

                <div v-if="awaiting || completed" class="space-y-3">
                    <div class="flex items-center justify-between text-xs text-slate-500">
                        <span>{{ formatMs(0) }}</span>
                        <span>{{ formatMs(currentTimeMs) }}</span>
                        <span>{{ formatMs(outputDurationMs) }}</span>
                    </div>
                    <div
                        class="relative h-14 cursor-pointer overflow-hidden rounded-2xl border border-slate-800 bg-slate-900"
                        @click="seekFromTimeline"
                    >
                        <div
                            v-for="(segment, index) in segments"
                            :key="segment.localId"
                            class="absolute top-2 bottom-2 rounded-md"
                            :class="[
                                barColors[index % barColors.length],
                                selectedLocalId === segment.localId ? 'ring-2 ring-white' : '',
                            ]"
                            :style="barStyle(segment)"
                            @click.stop="preview(segment)"
                        />
                        <div
                            class="pointer-events-none absolute top-0 bottom-0 w-0.5 bg-white"
                            :style="{ left: playheadPercent + '%' }"
                        />
                    </div>
                    <p class="text-xs text-slate-500">
                        Pulsa un bloque para previsualizar ese clip. El corte no se hace a intervalos iguales: ajusta o quita los que no sirvan.
                    </p>
                </div>
            </div>

            <div class="space-y-6">
                <div
                    v-if="job && !canQueue"
                    class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
                >
                    <p class="text-sm font-medium text-slate-200">{{ job.status_label }}</p>
                    <p v-if="job.progress_message" class="mt-1 text-sm text-slate-400">{{ job.progress_message }}</p>
                    <div v-if="waiting" class="mt-4 h-2 overflow-hidden rounded-full bg-slate-800">
                        <div
                            class="h-full rounded-full bg-violet-500 transition-all"
                            :style="{ width: Math.max(job.progress_percent || 4, 4) + '%' }"
                        />
                    </div>
                    <p v-if="waiting" class="mt-2 text-xs text-slate-500">
                        <template v-if="workerOnline">El worker del PC está en línea. Si no avanza en unos segundos, espera: está descargando o analizando el video.</template>
                        <template v-else>No hay worker en línea. En Docker Desktop dale Play a esquina_video_worker.</template>
                    </p>
                </div>

                <div
                    v-if="job?.recommendation_reason || job?.recommended_clip_count"
                    class="rounded-2xl border border-amber-900/50 bg-amber-950/20 p-5 text-sm text-amber-100"
                >
                    <p v-if="job.recommended_clip_count">
                        Propuesta: {{ job.recommended_clip_count }} clip(s)
                        <span v-if="job.params?.target_clip_count">
                            (objetivo {{ job.params.target_clip_count }})
                        </span>
                    </p>
                    <p v-if="job.recommendation_reason" class="mt-1 text-amber-200/80">
                        {{ job.recommendation_reason }}
                    </p>
                </div>

                <div v-if="failed" class="rounded-2xl border border-red-900/60 bg-red-950/20 p-5 text-sm text-red-200">
                    <p class="font-medium">El análisis o el render falló.</p>
                    <p v-if="job?.error_message" class="mt-1 text-red-300/80">{{ job.error_message }}</p>
                </div>

                <form v-if="canQueue" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900/60 p-5" @submit.prevent="queueAnalysis">
                    <h3 class="font-medium">Pedir análisis</h3>
                    <p class="text-sm text-slate-400">
                        No se vuelve a subir el original. El worker del PC busca cortes naturales.
                    </p>
                    <div>
                        <label class="mb-1 block text-sm text-slate-300">Clips objetivo</label>
                        <input
                            v-model.number="form.target_clip_count"
                            type="number"
                            min="1"
                            max="30"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5"
                        />
                        <p v-if="form.errors.target_clip_count" class="mt-1 text-sm text-red-400">{{ form.errors.target_clip_count }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm text-slate-300">Mín. (s)</label>
                            <input
                                v-model.number="form.min_duration_seconds"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm text-slate-300">Máx. (s)</label>
                            <input
                                v-model.number="form.max_duration_seconds"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5"
                            />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm text-slate-300">Modo</p>
                        <label
                            v-for="option in modeOptions"
                            :key="option.value"
                            class="flex cursor-pointer gap-3 rounded-xl border border-slate-800 px-4 py-3 hover:bg-slate-950"
                        >
                            <input v-model="form.mode" type="radio" :value="option.value" class="mt-1" />
                            <span class="text-sm">
                                <span class="font-medium text-slate-200">{{ option.label }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm text-slate-300">Encuadre 9:16</p>
                        <label
                            v-for="option in framingOptions"
                            :key="option.value"
                            class="flex cursor-pointer gap-3 rounded-xl border border-slate-800 px-4 py-3 hover:bg-slate-950"
                        >
                            <input v-model="form.framing" type="radio" :value="option.value" class="mt-1" />
                            <span class="text-sm">
                                <span class="font-medium text-slate-200">{{ option.label }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                    <div v-if="form.framing === 'fit'" class="space-y-3">
                        <p class="text-sm text-slate-300">Fondo de las bandas</p>
                        <label
                            v-for="option in fitFillOptions"
                            :key="option.value"
                            class="flex cursor-pointer gap-3 rounded-xl border border-slate-800 px-4 py-3 hover:bg-slate-950"
                        >
                            <input v-model="form.fit_fill" type="radio" :value="option.value" class="mt-1" />
                            <span class="text-sm">
                                <span class="font-medium text-slate-200">{{ option.label }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ option.hint }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-800 px-4 py-3 hover:bg-slate-950">
                            <input v-model="form.fit_part_label" type="checkbox" class="mt-1" />
                            <span class="text-sm">
                                <span class="font-medium text-slate-200">Texto Parte 1, 2, 3…</span>
                                <span class="mt-0.5 block text-xs text-slate-500">
                                    Centrado en la banda de abajo. Si hay plantilla, el texto va encima.
                                </span>
                            </span>
                        </label>
                        <label v-if="form.fit_part_label" class="flex items-center justify-between gap-3 rounded-xl border border-slate-800 px-4 py-3">
                            <span class="text-sm text-slate-300">Color del texto</span>
                            <input v-model="form.fit_part_label_color" type="color" class="h-9 w-14 cursor-pointer rounded border border-slate-700 bg-slate-950" />
                        </label>
                        <p v-if="form.errors.fit_part_label_color" class="text-sm text-red-400">{{ form.errors.fit_part_label_color }}</p>
                        <FilePicker
                            v-model="form.fit_template"
                            label="Plantilla / marco (opcional)"
                            accept="image/jpeg,image/png,image/webp"
                            choose-label="Elegir imagen"
                            empty-label="Sin plantilla — solo el fondo elegido"
                            hint="PNG con transparencia para marco. Lo que no cubra la imagen se rellena con desenfocado, blanco o negro. El texto Parte N queda encima."
                        />
                        <p v-if="form.errors.fit_template" class="text-sm text-red-400">{{ form.errors.fit_template }}</p>
                    </div>
                    <button
                        type="submit"
                        class="w-full rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-medium hover:bg-violet-500 disabled:opacity-50"
                        :disabled="form.processing || video.video_missing"
                    >
                        Encolar análisis
                    </button>
                </form>

                <div v-if="awaiting" class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-medium">Cortes propuestos ({{ segments.length }})</h3>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800"
                            @click="addSegment"
                        >
                            Añadir desde el playhead
                        </button>
                    </div>
                    <p v-if="overlapError" class="text-sm text-red-400">{{ overlapError }}</p>
                    <article
                        v-for="(segment, index) in segments"
                        :key="segment.localId"
                        class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
                        :class="selectedLocalId === segment.localId ? 'ring-1 ring-violet-400/60' : ''"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-sm font-medium">Clip {{ index + 1 }} · {{ formatMs(segment.end_ms - segment.start_ms) }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="text-xs text-violet-300 hover:text-white" @click="preview(segment)">
                                    Ver
                                </button>
                                <button type="button" class="text-xs text-red-400 hover:text-red-300" @click="removeSegment(segment.localId)">
                                    Quitar
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="text-xs text-slate-400">
                                Inicio (s)
                                <input
                                    :value="msToInput(segment.start_ms)"
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                                    @change="updateStart(segment, $event.target.value)"
                                />
                            </label>
                            <label class="text-xs text-slate-400">
                                Fin (s)
                                <input
                                    :value="msToInput(segment.end_ms)"
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                                    @change="updateEnd(segment, $event.target.value)"
                                />
                            </label>
                        </div>
                        <p v-if="segment.warnings?.length" class="mt-2 text-xs text-amber-300">
                            {{ segment.warnings.join(' ') }}
                        </p>
                    </article>
                    <button
                        type="button"
                        class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium hover:bg-emerald-500 disabled:opacity-50"
                        :disabled="segments.length === 0 || Boolean(overlapError)"
                        @click="approve"
                    >
                        Aprobar y renderizar 9:16
                    </button>
                </div>

                <div v-if="completed && segments.some((segment) => segment.output_video)" class="space-y-3">
                    <h3 class="font-medium">Clips listos</h3>
                    <Link
                        v-for="segment in segments.filter((item) => item.output_video)"
                        :key="segment.output_video.id"
                        :href="route('videos.show', segment.output_video.id)"
                        class="block rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3 text-sm hover:bg-slate-800"
                    >
                        {{ segment.output_video.title }}
                        <span class="ml-2 text-slate-500">{{ formatMs(segment.start_ms) }}–{{ formatMs(segment.end_ms) }}</span>
                    </Link>
                    <p class="text-xs text-slate-500">
                        Cada clip es un video propio: edita textos y publícalo desde su ficha.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
