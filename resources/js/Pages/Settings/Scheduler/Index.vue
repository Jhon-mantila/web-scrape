<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsSubnav from '@/Components/SettingsSubnav.vue';

const props = defineProps({
    tasks: {
        type: Array,
        default: () => [],
    },
    daemon: {
        type: Object,
        default: () => ({ active: false }),
    },
    commands: Object,
});

const forms = {};

props.tasks.forEach((task) => {
    forms[task.task_key] = useForm({
        enabled: task.enabled,
        frequency: task.frequency,
        interval_hours: task.interval_hours,
        daily_at: task.daily_at,
        once_at: task.once_at ?? '',
        timezone: task.timezone,
    });
});

function saveTask(taskKey) {
    forms[taskKey].put(route('settings.scheduler.update', taskKey), {
        preserveScroll: true,
    });
}

function runTaskNow(taskKey) {
    router.post(route('settings.scheduler.run-now', taskKey), {}, { preserveScroll: true });
}

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
}

async function copyCommand(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        window.prompt('Copia:', text);
    }
}
</script>

<template>
    <AppLayout>
        <SettingsSubnav />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold">Planificador de tareas</h2>
                <p class="mt-1 text-sm text-slate-400">
                    Cada tarea tiene su propia frecuencia. El motor
                    <code class="text-slate-300">app:scheduler-tick</code>
                    corre cada minuto vía
                    <code class="text-slate-300">schedule:work</code>.
                </p>
            </div>
            <span
                class="rounded-full border px-3 py-1 text-xs"
                :class="daemon.active
                    ? 'border-emerald-600/50 bg-emerald-950/40 text-emerald-300'
                    : 'border-amber-600/50 bg-amber-950/40 text-amber-200'"
            >
                {{
                    daemon.active
                        ? 'Motor activo'
                        : 'Motor inactivo — inicia schedule:work'
                }}
            </span>
        </div>

        <div class="mb-6 max-w-4xl rounded-xl border border-slate-800 bg-slate-950/50 p-4 text-xs text-slate-400">
            <p class="font-medium text-slate-300">Comandos Docker (terminal aparte)</p>
            <ul class="mt-2 space-y-2">
                <li class="flex flex-wrap items-center gap-2">
                    <code class="flex-1 break-all text-slate-500">{{ commands.schedule_work }}</code>
                    <button type="button" class="text-sky-400 hover:underline" @click="copyCommand(commands.schedule_work)">
                        Copiar
                    </button>
                    <span class="text-slate-600">— dejar corriendo (recomendado)</span>
                </li>
                <li class="flex flex-wrap items-center gap-2">
                    <code class="flex-1 break-all text-slate-500">{{ commands.scheduler_tick }}</code>
                    <button type="button" class="text-sky-400 hover:underline" @click="copyCommand(commands.scheduler_tick)">
                        Copiar
                    </button>
                    <span class="text-slate-600">— un tick manual (prueba)</span>
                </li>
                <li class="flex flex-wrap items-center gap-2">
                    <code class="flex-1 break-all text-slate-500">{{ commands.schedule_run }}</code>
                    <button type="button" class="text-sky-400 hover:underline" @click="copyCommand(commands.schedule_run)">
                        Copiar
                    </button>
                    <span class="text-slate-600">— una pasada del schedule (cron puntual)</span>
                </li>
                <li class="flex flex-wrap items-center gap-2">
                    <code class="flex-1 break-all text-slate-500">{{ commands.social_publish_queued }}</code>
                    <button type="button" class="text-sky-400 hover:underline" @click="copyCommand(commands.social_publish_queued)">
                        Copiar
                    </button>
                    <span class="text-slate-600">— solo cola de videos, sin reglas de frecuencia</span>
                </li>
            </ul>
            <p v-if="daemon.heartbeat_at" class="mt-3 text-slate-600">
                Último latido: {{ formatDate(daemon.heartbeat_at) }}
            </p>
            <p class="mt-3 text-slate-600">
                La interfaz guarda la frecuencia de cada tarea en la base de datos.
                <code class="text-slate-500">schedule:work</code>
                debe estar activo para que
                <code class="text-slate-500">app:scheduler-tick</code>
                corra cada minuto y aplique tus reglas.
            </p>
        </div>

        <div class="max-w-4xl space-y-6">
            <section
                v-for="task in tasks"
                :key="task.task_key"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="mb-4">
                    <h3 class="text-lg font-medium text-slate-100">{{ task.label }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ task.description }}</p>
                    <p class="mt-2 text-xs text-slate-600">
                        Clave: <code>{{ task.task_key }}</code>
                    </p>
                </div>

                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveTask(task.task_key)">
                    <label class="flex items-center gap-2 sm:col-span-2 text-sm">
                        <input
                            v-model="forms[task.task_key].enabled"
                            type="checkbox"
                            class="rounded border-slate-600"
                        />
                        Tarea activa
                    </label>

                    <label class="block text-sm">
                        <span class="text-slate-400">Frecuencia</span>
                        <select
                            v-model="forms[task.task_key].frequency"
                            class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        >
                            <option value="every_minute">Cada minuto</option>
                            <option value="every_n_hours">Cada N horas</option>
                            <option value="daily_at">Una vez al día</option>
                            <option value="once_at">Una sola vez</option>
                        </select>
                    </label>

                    <label class="block text-sm">
                        <span class="text-slate-400">Zona horaria</span>
                        <input
                            v-model="forms[task.task_key].timezone"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <label
                        v-if="forms[task.task_key].frequency === 'every_n_hours'"
                        class="block text-sm"
                    >
                        <span class="text-slate-400">Cada cuántas horas</span>
                        <input
                            v-model.number="forms[task.task_key].interval_hours"
                            type="number"
                            min="1"
                            max="168"
                            class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <label
                        v-if="forms[task.task_key].frequency === 'daily_at'"
                        class="block text-sm"
                    >
                        <span class="text-slate-400">Hora (24 h)</span>
                        <input
                            v-model="forms[task.task_key].daily_at"
                            type="time"
                            class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <label
                        v-if="forms[task.task_key].frequency === 'once_at'"
                        class="block text-sm sm:col-span-2"
                    >
                        <span class="text-slate-400">Fecha y hora única</span>
                        <input
                            v-model="forms[task.task_key].once_at"
                            type="datetime-local"
                            class="mt-1 w-full max-w-sm rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            class="rounded-lg bg-violet-600 px-4 py-2 text-sm hover:bg-violet-500 disabled:opacity-50"
                            :disabled="forms[task.task_key].processing"
                        >
                            Guardar
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-emerald-700/60 px-4 py-2 text-sm text-emerald-200 hover:bg-emerald-950/40"
                            @click="runTaskNow(task.task_key)"
                        >
                            Ejecutar ahora
                        </button>
                    </div>
                </form>

                <dl class="mt-4 grid gap-2 border-t border-slate-800 pt-4 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-600">Regla</dt>
                        <dd class="text-slate-300">{{ task.frequency_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-600">Próxima ventana</dt>
                        <dd class="text-slate-300">
                            {{
                                task.next_run_hint?.includes('T')
                                    ? formatDate(task.next_run_hint)
                                    : (task.next_run_hint ?? '—')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-600">Última ejecución</dt>
                        <dd class="text-slate-300">{{ formatDate(task.last_run_at) }}</dd>
                    </div>
                    <div v-if="task.last_run_summary">
                        <dt class="text-slate-600">Resultado</dt>
                        <dd class="text-slate-300">
                            OK {{ task.last_run_summary.published ?? 0 }}
                            · fallidas {{ task.last_run_summary.failed ?? 0 }}
                            <span v-if="task.last_run_summary.message" class="block text-slate-500">
                                {{ task.last_run_summary.message }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </AppLayout>
</template>
