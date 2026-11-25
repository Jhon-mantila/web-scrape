<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClipPlatformPicker from '@/Components/ClipPlatformPicker.vue';
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
    platformOptions: {
        type: Array,
        default: () => [],
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
    inherit_platforms: props.job?.params?.inherit_platforms ?? props.defaults.inherit_platforms ?? true,
    clip_platforms: [...(props.job?.params?.clip_platforms ?? props.defaults.clip_platforms ?? [])],
    fit_template: null,
});

const status = computed(() => props.job?.status ?? null);
const awaiting = computed(() => status.value === 'awaiting_approval');
const waiting = computed(() => props.job?.needs_poll === true);
const canQueue = computed(() => !props.job || props.job.is_active !== true);
const completed = computed(() => status.value === 'completed');
const failed = computed(() => status.value === 'failed');
const analysisOpen = ref(props.job?.status !== 'completed');

const readyClips = computed(() => segments.value.filter((segment) => segment.output_video));

const selectedModeHint = computed(
    () => props.modeOptions.find((option) => option.value === form.mode)?.hint ?? '',
);

const selectedFramingHint = computed(
    () => props.framingOptions.find((option) => option.value === form.framing)?.hint ?? '',
);

const selectedFitFillHint = computed(
    () => props.fitFillOptions.find((option) => option.value === form.fit_fill)?.hint ?? '',
);

const chipClass = (active) => active
    ? 'border-violet-400 bg-violet-600/25 text-violet-50'
    : 'border-slate-700 bg-slate-950 text-slate-300 hover:border-slate-500 hover:text-white';

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
        inherit_platforms: data.inherit_platforms ? 1 : 0,
        clip_platforms: data.clip_platforms ?? [],
        clip_platforms_set: 1,
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
        inherit_platforms: form.inherit_platforms ? 1 : 0,
        clip_platforms: form.clip_platforms ?? [],
        clip_platforms_set: 1,
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

        <div
            v-if="completed && readyClips.length"
            class="mb-6 rounded-2xl border border-slate-800 bg-slate-900/50 p-4"
        >
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <h3 class="font-medium">Clips listos</h3>
                    <p class="text-xs text-slate-500">{{ readyClips.length }} clip(s) · abre uno para editar y publicar</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                <Link
                    v-for="(segment, index) in readyClips"
                    :key="segment.output_video.id"
                    :href="route('videos.show', segment.output_video.id)"
                    class="group rounded-xl border border-slate-800 bg-slate-950/80 px-3 py-3 hover:border-violet-400/60 hover:bg-slate-900"
                >
                    <p class="text-xs font-medium uppercase tracking-wide text-violet-300">Clip {{ index + 1 }}</p>
                    <p class="mt-1 text-sm text-slate-100">{{ formatMs(segment.start_ms) }}–{{ formatMs(segment.end_ms) }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ formatMs(segment.end_ms - segment.start_ms) }}</p>
                </Link>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">
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

            <div class="space-y-4">
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
                    v-if="!completed && (job?.recommendation_reason || job?.recommended_clip_count)"
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

                <div
                    v-if="canQueue && completed && !analysisOpen"
                    class="rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
                >
                    <p class="text-sm text-slate-300">¿Otro corte de este video?</p>
                    <button
                        type="button"
                        class="mt-3 w-full rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        @click="analysisOpen = true"
                    >
                        Nuevo análisis
                    </button>
                </div>

                <form
                    v-if="canQueue && (!completed || analysisOpen)"
                    class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900/60 p-4"
                    @submit.prevent="queueAnalysis"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium">Pedir análisis</h3>
                            <p class="mt-0.5 text-xs text-slate-500">No se vuelve a subir el original.</p>
                        </div>
                        <button
                            v-if="completed"
                            type="button"
                            class="text-xs text-slate-400 hover:text-white"
                            @click="analysisOpen = false"
                        >
                            Cerrar
                        </button>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="text-xs text-slate-400">
                            Clips
                            <input
                                v-model.number="form.target_clip_count"
                                type="number"
                                min="1"
                                max="30"
                                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100"
                            />
                        </label>
                        <label class="text-xs text-slate-400">
                            Mín. s
                            <input
                                v-model.number="form.min_duration_seconds"
                                type="number"
                                min="1"
                                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100"
                            />
                        </label>
                        <label class="text-xs text-slate-400">
                            Máx. s
                            <input
                                v-model.number="form.max_duration_seconds"
                                type="number"
                                min="1"
                                class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100"
                            />
                        </label>
                    </div>
                    <p v-if="form.errors.target_clip_count" class="text-sm text-red-400">{{ form.errors.target_clip_count }}</p>
                    <div>
                        <p class="mb-1.5 text-xs text-slate-400">Modo</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="option in modeOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-lg border px-2 py-2 text-xs font-medium transition"
                                :class="chipClass(form.mode === option.value)"
                                @click="form.mode = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] leading-snug text-slate-500">{{ selectedModeHint }}</p>
                    </div>
                    <div>
                        <p class="mb-1.5 text-xs text-slate-400">Encuadre 9:16</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="option in framingOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-lg border px-2 py-2 text-[11px] font-medium leading-tight transition"
                                :class="chipClass(form.framing === option.value)"
                                @click="form.framing = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11px] leading-snug text-slate-500">{{ selectedFramingHint }}</p>
                    </div>
                    <div v-if="form.framing === 'fit'" class="space-y-2 rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                        <p class="text-xs text-slate-400">Fondo de las bandas</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="option in fitFillOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-lg border px-2 py-1.5 text-xs transition"
                                :class="chipClass(form.fit_fill === option.value)"
                                @click="form.fit_fill = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500">{{ selectedFitFillHint }}</p>
                        <label class="flex items-center justify-between gap-2 text-xs text-slate-300">
                            <span class="flex items-center gap-2">
                                <input v-model="form.fit_part_label" type="checkbox" />
                                Texto Parte 1, 2…
                            </span>
                            <input
                                v-if="form.fit_part_label"
                                v-model="form.fit_part_label_color"
                                type="color"
                                class="h-7 w-10 cursor-pointer rounded border border-slate-700 bg-slate-950"
                            />
                        </label>
                        <p v-if="form.errors.fit_part_label_color" class="text-sm text-red-400">{{ form.errors.fit_part_label_color }}</p>
                        <FilePicker
                            v-model="form.fit_template"
                            label="Plantilla (opcional)"
                            accept="image/jpeg,image/png,image/webp"
                            choose-label="Elegir imagen"
                            empty-label="Sin plantilla"
                            hint="PNG con transparencia. El texto Parte N queda encima."
                        />
                        <p v-if="form.errors.fit_template" class="text-sm text-red-400">{{ form.errors.fit_template }}</p>
                    </div>
                    <ClipPlatformPicker
                        v-model:inherit="form.inherit_platforms"
                        v-model:platforms="form.clip_platforms"
                        :options="platformOptions"
                        :parent-platforms="video.platforms ?? []"
                        :error="form.errors.clip_platforms"
                    />
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
                    <div class="max-h-[28rem] space-y-1.5 overflow-y-auto pr-1">
                        <article
                            v-for="(segment, index) in segments"
                            :key="segment.localId"
                            class="rounded-xl border border-slate-800 bg-slate-900/60 px-3 py-2"
                            :class="selectedLocalId === segment.localId ? 'ring-1 ring-violet-400/60' : ''"
                        >
                            <div class="flex items-center gap-2">
                                <p class="w-14 shrink-0 text-xs font-medium text-slate-200">Clip {{ index + 1 }}</p>
                                <input
                                    :value="msToInput(segment.start_ms)"
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    class="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100"
                                    @change="updateStart(segment, $event.target.value)"
                                />
                                <span class="text-xs text-slate-600">–</span>
                                <input
                                    :value="msToInput(segment.end_ms)"
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    class="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-100"
                                    @change="updateEnd(segment, $event.target.value)"
                                />
                                <button type="button" class="text-xs text-violet-300 hover:text-white" @click="preview(segment)">Ver</button>
                                <button type="button" class="text-xs text-red-400 hover:text-red-300" @click="removeSegment(segment.localId)">×</button>
                            </div>
                            <p v-if="segment.warnings?.length" class="mt-1 text-[11px] text-amber-300">
                                {{ segment.warnings.join(' ') }}
                            </p>
                        </article>
                    </div>
                    <ClipPlatformPicker
                        v-model:inherit="form.inherit_platforms"
                        v-model:platforms="form.clip_platforms"
                        :options="platformOptions"
                        :parent-platforms="video.platforms ?? []"
                    />
                    <button
                        type="button"
                        class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium hover:bg-emerald-500 disabled:opacity-50"
                        :disabled="segments.length === 0 || Boolean(overlapError)"
                        @click="approve"
                    >
                        Aprobar y renderizar 9:16
                    </button>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
