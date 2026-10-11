<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsSubnav from '@/Components/SettingsSubnav.vue';

const props = defineProps({
    tab: { type: String, default: 'pending' },
    queueConnection: { type: String, default: 'database' },
    stats: {
        type: Object,
        default: () => ({ pending: 0, failed: 0, batches: 0 }),
    },
    pendingJobs: { type: Array, default: () => [] },
    failedJobs: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    failedDetail: { type: Object, default: null },
    commands: Object,
});

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
}

function switchTab(name) {
    router.get(route('settings.queue.index'), { tab: name }, { preserveState: true, preserveScroll: true });
}

function openFailed(uuid) {
    router.get(
        route('settings.queue.index'),
        { tab: 'failed', failed: uuid },
        { preserveState: true, preserveScroll: true },
    );
}

function closeFailedDetail() {
    router.get(route('settings.queue.index'), { tab: 'failed' }, { preserveState: true, preserveScroll: true });
}

function retryFailed(uuid) {
    router.post(route('settings.queue.failed.retry', uuid), {}, { preserveScroll: true });
}

function deleteFailed(uuid) {
    if (!window.confirm('¿Eliminar este registro de failed_jobs?')) {
        return;
    }

    router.delete(route('settings.queue.failed.destroy', uuid), { preserveScroll: true });
}

function retryAllFailed() {
    if (!window.confirm('¿Reencolar todos los trabajos fallidos?')) {
        return;
    }

    router.post(route('settings.queue.failed.retry-all'), {}, { preserveScroll: true });
}

function flushFailed() {
    if (!window.confirm('¿Vaciar por completo failed_jobs? No se puede deshacer.')) {
        return;
    }

    router.delete(route('settings.queue.failed.flush'), { preserveScroll: true });
}

async function copyCommand(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        window.prompt('Copia:', text);
    }
}

function tabClass(active) {
    return active
        ? 'border-violet-500 bg-violet-950/40 text-violet-200'
        : 'border-slate-700 text-slate-400 hover:border-slate-600 hover:text-slate-200';
}
</script>

<template>
    <AppLayout>
        <SettingsSubnav />

        <div class="mb-6">
            <h2 class="text-2xl font-semibold">Cola Laravel (jobs)</h2>
            <p class="mt-1 text-sm text-slate-400">
                Vista de las tablas
                <code class="text-slate-300">jobs</code>,
                <code class="text-slate-300">failed_jobs</code> y
                <code class="text-slate-300">job_batches</code>.
                Conexión actual:
                <code class="text-slate-300">{{ queueConnection }}</code>.
                No confundir con la cola del planificador de videos (estados en publicaciones).
            </p>
        </div>

        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Pendientes (jobs)</p>
                <p class="mt-1 text-2xl font-semibold text-cyan-300">{{ stats.pending }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Fallidos</p>
                <p class="mt-1 text-2xl font-semibold text-red-300">{{ stats.failed }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Lotes (batches)</p>
                <p class="mt-1 text-2xl font-semibold text-amber-300">{{ stats.batches }}</p>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-slate-800 bg-slate-900/40 p-4 text-sm text-slate-400">
            <p class="font-medium text-slate-300">Worker de cola</p>
            <p class="mt-1">
                Con driver <code class="text-slate-300">database</code>, los jobs en
                <code class="text-slate-300">jobs</code> solo se ejecutan si corre
                <code class="text-slate-300">queue:work</code> (no está en docker-compose por defecto).
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800"
                    @click="copyCommand(commands.queue_work)"
                >
                    Copiar queue:work
                </button>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-sm transition"
                :class="tabClass(tab === 'pending')"
                @click="switchTab('pending')"
            >
                Pendientes ({{ stats.pending }})
            </button>
            <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-sm transition"
                :class="tabClass(tab === 'failed')"
                @click="switchTab('failed')"
            >
                Fallidos ({{ stats.failed }})
            </button>
            <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-sm transition"
                :class="tabClass(tab === 'batches')"
                @click="switchTab('batches')"
            >
                Lotes ({{ stats.batches }})
            </button>
        </div>

        <div v-if="tab === 'pending'" class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-800 bg-slate-900/80 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">Job</th>
                        <th class="px-4 py-3">Cola</th>
                        <th class="px-4 py-3">Intentos</th>
                        <th class="px-4 py-3">Disponible</th>
                        <th class="px-4 py-3">Creado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="pendingJobs.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">No hay filas en jobs.</td>
                    </tr>
                    <tr
                        v-for="job in pendingJobs"
                        :key="job.id"
                        class="border-b border-slate-800/80 hover:bg-slate-900/40"
                    >
                        <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ job.id }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-200">{{ job.display_name }}</span>
                            <p v-if="job.job_class" class="mt-0.5 font-mono text-xs text-slate-500">{{ job.job_class }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-400">{{ job.queue }}</td>
                        <td class="px-4 py-3">{{ job.attempts }}</td>
                        <td class="px-4 py-3 text-slate-400">{{ formatDate(job.available_at) }}</td>
                        <td class="px-4 py-3 text-slate-400">{{ formatDate(job.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t border-slate-800 px-4 py-2 text-xs text-slate-500">Mostrando hasta 100 filas más recientes.</p>
        </div>

        <div v-else-if="tab === 'failed'" class="space-y-4">
            <div v-if="stats.failed > 0" class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-violet-700/50 bg-violet-950/30 px-3 py-1.5 text-sm text-violet-200 hover:bg-violet-950/50"
                    @click="retryAllFailed"
                >
                    Reintentar todos
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-red-800/50 bg-red-950/20 px-3 py-1.5 text-sm text-red-300 hover:bg-red-950/40"
                    @click="flushFailed"
                >
                    Vaciar failed_jobs
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-800">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-slate-800 bg-slate-900/80 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Job</th>
                            <th class="px-4 py-3">Cola</th>
                            <th class="px-4 py-3">Falló</th>
                            <th class="px-4 py-3">Error</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="failedJobs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No hay trabajos fallidos.</td>
                        </tr>
                        <tr
                            v-for="job in failedJobs"
                            :key="job.uuid"
                            class="border-b border-slate-800/80 hover:bg-slate-900/40"
                        >
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="text-left font-medium text-violet-300 hover:underline"
                                    @click="openFailed(job.uuid)"
                                >
                                    {{ job.display_name }}
                                </button>
                                <p class="mt-0.5 font-mono text-xs text-slate-500">{{ job.uuid }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-400">{{ job.queue }}</td>
                            <td class="px-4 py-3 text-slate-400">{{ formatDate(job.failed_at) }}</td>
                            <td class="max-w-md truncate px-4 py-3 text-xs text-red-300/90">{{ job.exception_preview }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="mr-2 text-xs text-violet-300 hover:underline"
                                    @click="retryFailed(job.uuid)"
                                >
                                    Reintentar
                                </button>
                                <button
                                    type="button"
                                    class="text-xs text-red-400 hover:underline"
                                    @click="deleteFailed(job.uuid)"
                                >
                                    Borrar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="failedDetail"
                class="rounded-xl border border-slate-700 bg-slate-900/60 p-4"
            >
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-200">{{ failedDetail.display_name }}</h3>
                    <button type="button" class="text-sm text-slate-400 hover:text-slate-200" @click="closeFailedDetail">
                        Cerrar detalle
                    </button>
                </div>
                <p class="text-xs text-slate-500">{{ failedDetail.job_class }}</p>
                <p class="mt-2 text-sm text-slate-400">Falló: {{ formatDate(failedDetail.failed_at) }}</p>
                <h4 class="mt-4 text-xs font-semibold uppercase text-slate-500">Excepción</h4>
                <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-black/40 p-3 text-xs text-red-200/90 whitespace-pre-wrap">{{ failedDetail.exception }}</pre>
                <h4 class="mt-4 text-xs font-semibold uppercase text-slate-500">Payload</h4>
                <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-black/40 p-3 text-xs text-slate-400 whitespace-pre-wrap">{{ failedDetail.payload }}</pre>
            </div>
        </div>

        <div v-else class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-slate-800 bg-slate-900/80 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Nombre</th>
                        <th class="px-4 py-3">Total</th>
                        <th class="px-4 py-3">Pendientes</th>
                        <th class="px-4 py-3">Fallidos</th>
                        <th class="px-4 py-3">Creado</th>
                        <th class="px-4 py-3">Finalizado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="batches.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">No hay lotes registrados.</td>
                    </tr>
                    <tr
                        v-for="batch in batches"
                        :key="batch.id"
                        class="border-b border-slate-800/80 hover:bg-slate-900/40"
                    >
                        <td class="px-4 py-3">
                            <span class="text-slate-200">{{ batch.name || '—' }}</span>
                            <p class="mt-0.5 font-mono text-xs text-slate-500">{{ batch.id }}</p>
                        </td>
                        <td class="px-4 py-3">{{ batch.total_jobs }}</td>
                        <td class="px-4 py-3">{{ batch.pending_jobs }}</td>
                        <td class="px-4 py-3">{{ batch.failed_jobs }}</td>
                        <td class="px-4 py-3 text-slate-400">{{ formatDate(batch.created_at) }}</td>
                        <td class="px-4 py-3 text-slate-400">{{ formatDate(batch.finished_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
