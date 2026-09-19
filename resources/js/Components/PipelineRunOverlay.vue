<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import {
    backgroundRunState,
    applyBackgroundSnapshot,
    hasBackgroundActivity,
    hydrateBackgroundRunFromSession,
    setExpandedRunId,
    setPanelCollapsed,
} from '@/stores/backgroundRunStore.js';
import {
    backgroundRunRequestHeaders,
    dismissBackgroundRun,
    fetchBackgroundRunStatus,
} from '@/support/backgroundRun.js';

let pollTimer = null;

const page = usePage();

const runs = computed(() => backgroundRunState.runs);

const isVisible = computed(() => hasBackgroundActivity());

const panelCollapsed = computed(() => backgroundRunState.panelCollapsed);

const expandedRun = computed(() =>
    runs.value.find((run) => run.id === backgroundRunState.expandedRunId) ?? null,
);

const activeRunningRun = computed(
    () => runs.value.find((run) => run.status === 'running') ?? null,
);

const summaryLine = computed(() => {
    const running = backgroundRunState.runningCount;
    const queued = backgroundRunState.queuedCount;
    const parts = [];

    if (running > 0) {
        parts.push(`${running} en curso`);
    }

    if (queued > 0) {
        parts.push(`${queued} en cola`);
    }

    return parts.join(' · ') || `${runs.value.length} tarea(s)`;
});

const needsPolling = computed(() =>
    runs.value.some((run) => run.status === 'running' || run.status === 'queued'),
);

async function refreshStatus() {
    try {
        await fetchBackgroundRunStatus();
    } catch {
        // ignore
    }
}

function startPolling() {
    stopPolling();

    if (!needsPolling.value) {
        return;
    }

    pollTimer = window.setInterval(refreshStatus, 2500);
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function onPipelineStarted(event) {
    const snapshot = event.detail?.snapshot;

    if (snapshot) {
        applyBackgroundSnapshot(snapshot);
    }

    setPanelCollapsed(false);
    startPolling();
    refreshStatus();
}

watch(needsPolling, (active) => {
    if (active) {
        startPolling();
    } else {
        stopPolling();
    }
});

watch(
    () =>
        page.props.backgroundRun
        ?? page.props.pipelineRun
        ?? page.props.flash?.backgroundRunSnapshot,
    (snapshot) => {
        if (snapshot?.runs?.length) {
            applyBackgroundSnapshot(snapshot);
            setPanelCollapsed(false);
        }
    },
    { deep: true, immediate: true },
);

onMounted(async () => {
    hydrateBackgroundRunFromSession();
    await refreshStatus();
    startPolling();

    window.addEventListener('pipeline-run-started', onPipelineStarted);
});

onUnmounted(() => {
    stopPolling();
    window.removeEventListener('pipeline-run-started', onPipelineStarted);
});

function minimizePanel() {
    setPanelCollapsed(true);
}

function expandPanel() {
    setPanelCollapsed(false);
}

function selectRun(runId) {
    setExpandedRunId(runId);
    setPanelCollapsed(false);
}

async function closeRun(run) {
    if (run.status === 'running' || run.status === 'queued') {
        minimizePanel();

        return;
    }

    await dismissBackgroundRun(run.id);

    if (runs.value.length === 0) {
        router.reload({ only: ['stats', 'news', 'videos'], preserveScroll: true });
    }
}

async function clearFinished() {
    await dismissBackgroundRun(null, true);

    if (!hasBackgroundActivity()) {
        router.reload({ only: ['stats', 'news', 'videos'], preserveScroll: true });
    }
}

function runStatusLabel(status) {
    const map = {
        queued: 'En cola',
        running: 'En curso',
        completed: 'Listo',
        failed: 'Error',
    };

    return map[status] ?? status;
}

function runStatusClass(status) {
    const map = {
        queued: 'border-slate-600 bg-slate-900/80 text-slate-300',
        running: 'border-violet-600/50 bg-violet-950/30 text-violet-200',
        completed: 'border-emerald-600/40 bg-emerald-950/20 text-emerald-200',
        failed: 'border-red-600/40 bg-red-950/20 text-red-200',
    };

    return map[status] ?? map.queued;
}

function stepClass(status) {
    const map = {
        done: 'text-emerald-300',
        running: 'text-violet-300',
        skipped: 'text-slate-500',
        pending: 'text-slate-600',
    };

    return map[status] ?? 'text-slate-500';
}

function stepIcon(status) {
    const map = {
        done: '✓',
        running: '●',
        skipped: '−',
        pending: '○',
    };

    return map[status] ?? '○';
}

function showFullRunDetail(run) {
    return run.status === 'running' || run.status === 'queued';
}

</script>

<template>
    <Teleport to="body">
        <div v-if="isVisible" class="fixed bottom-4 right-4 z-[9999] max-w-md pointer-events-auto">
            <button
                v-if="panelCollapsed"
                type="button"
                class="flex max-w-sm flex-col items-start gap-1 rounded-2xl border border-violet-500/60 bg-slate-900/95 px-4 py-2.5 text-left text-sm text-violet-200 shadow-lg backdrop-blur hover:bg-slate-800"
                @click="expandPanel"
            >
                <span class="flex items-center gap-2">
                    <span class="inline-block h-2 w-2 shrink-0 animate-pulse rounded-full bg-violet-400" />
                    <span class="font-medium">Procesos en segundo plano</span>
                    <span class="text-xs opacity-80">· {{ summaryLine }}</span>
                </span>
                <span
                    v-if="activeRunningRun"
                    class="line-clamp-2 pl-4 text-xs text-slate-300"
                >
                    {{ activeRunningRun.label }} · {{ activeRunningRun.progress_percent ?? 0 }}%
                    <template v-if="activeRunningRun.message">
                        — {{ activeRunningRun.message }}
                    </template>
                </span>
            </button>

            <div
                v-else
                class="w-full max-w-md rounded-2xl border border-violet-600/40 bg-slate-900/95 p-4 shadow-2xl backdrop-blur sm:min-w-[22rem]"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">Procesos en segundo plano</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ summaryLine }}</p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg px-2 py-1 text-slate-400 hover:bg-slate-800 hover:text-white"
                        title="Minimizar panel"
                        @click="minimizePanel"
                    >
                        ×
                    </button>
                </div>

                <ul class="mt-3 max-h-[min(70vh,28rem)] space-y-2 overflow-y-auto">
                    <li
                        v-for="run in runs"
                        :key="run.id"
                        class="rounded-xl border p-2.5"
                        :class="runStatusClass(run.status)"
                    >
                        <div class="flex w-full items-start justify-between gap-2">
                            <button
                                type="button"
                                class="min-w-0 flex-1 text-left"
                                :class="run.status === 'completed' || run.status === 'failed' ? 'cursor-pointer' : 'cursor-default'"
                                @click="
                                    run.status === 'completed' || run.status === 'failed'
                                        ? selectRun(run.id)
                                        : undefined
                                "
                            >
                                <p class="text-sm font-medium">{{ run.label }}</p>
                                <p v-if="run.video_title" class="truncate text-xs opacity-80">
                                    {{ run.video_title }}
                                </p>
                                <p class="mt-0.5 text-xs opacity-90">
                                    {{ runStatusLabel(run.status) }}
                                    <span v-if="run.status === 'queued' && run.queue_position">
                                        · posición {{ run.queue_position }}
                                    </span>
                                    <span v-if="run.status === 'running'">
                                        · {{ run.progress_percent ?? 0 }}%
                                    </span>
                                </p>
                            </button>
                            <button
                                type="button"
                                class="shrink-0 rounded px-1.5 text-lg leading-none opacity-70 hover:opacity-100"
                                :title="run.status === 'running' || run.status === 'queued' ? 'Minimizar panel' : 'Quitar de la lista'"
                                @click.stop="closeRun(run)"
                            >
                                ×
                            </button>
                        </div>

                        <div
                            v-if="showFullRunDetail(run)"
                            class="mt-2 border-t border-white/10 pt-2"
                        >
                            <p v-if="run.message" class="mb-2 text-xs leading-relaxed text-slate-200">
                                {{ run.message }}
                            </p>

                            <template v-if="run.status === 'running'">
                                <div class="mb-1 flex justify-between text-xs opacity-80">
                                    <span>Progreso estimado</span>
                                    <span>{{ run.progress_percent ?? 0 }}%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-black/30">
                                    <div
                                        class="h-full rounded-full bg-violet-400 transition-all duration-500"
                                        :style="{ width: `${run.progress_percent ?? 0}%` }"
                                    />
                                </div>
                            </template>

                            <ul v-if="run.steps?.length" class="mt-2.5 space-y-1 text-xs">
                                <li
                                    v-for="step in run.steps"
                                    :key="step.key"
                                    class="flex gap-2 leading-snug"
                                    :class="stepClass(step.status)"
                                >
                                    <span
                                        class="w-3 shrink-0 text-center font-mono"
                                        :class="step.status === 'running' ? 'animate-pulse' : ''"
                                    >
                                        {{ stepIcon(step.status) }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium">{{ step.label }}</span>
                                        <span v-if="step.status === 'running'" class="opacity-90">
                                            — en curso…
                                        </span>
                                        <span v-else-if="step.detail" class="block opacity-75">
                                            {{ step.detail }}
                                        </span>
                                    </span>
                                </li>
                            </ul>

                            <p
                                v-else-if="run.status === 'queued'"
                                class="mt-1 text-xs text-slate-400"
                            >
                                Se iniciará cuando termine el proceso anterior.
                            </p>
                        </div>

                        <p
                            v-else-if="expandedRun?.id === run.id && (run.status === 'completed' || run.status === 'failed')"
                            class="mt-2 border-t border-white/10 pt-2 text-xs leading-relaxed"
                        >
                            {{ run.message }}
                        </p>
                    </li>
                </ul>

                <button
                    v-if="runs.some((run) => run.status === 'completed' || run.status === 'failed')"
                    type="button"
                    class="mt-3 w-full rounded-lg border border-slate-600 px-2 py-1.5 text-xs text-slate-300 hover:bg-slate-800"
                    @click="clearFinished"
                >
                    Limpiar finalizados
                </button>
            </div>
        </div>
    </Teleport>
</template>
