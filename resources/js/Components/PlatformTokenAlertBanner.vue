<script setup>
import {
    dismissConnectionAlert,
    pruneDismissedConnectionAlerts,
    readDismissedConnectionAlerts,
} from '@/support/dismissedConnectionAlerts.js';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage();

const tokenAlerts = computed(() => page.props.platformTokenAlerts ?? []);

const connectionAlertsFromServer = computed(() => page.props.platformConnectionAlerts ?? []);

const dismissedConnection = ref(readDismissedConnectionAlerts());

watch(
    connectionAlertsFromServer,
    (alerts) => {
        dismissedConnection.value = pruneDismissedConnectionAlerts(
            alerts.map((alert) => alert.platform),
        );
    },
    { immediate: true, deep: true },
);

const visibleConnectionAlerts = computed(() =>
    connectionAlertsFromServer.value.filter(
        (alert) => !dismissedConnection.value.includes(alert.platform),
    ),
);

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString('es-CO', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function dismissDisconnected(platform) {
    dismissConnectionAlert(platform);
    dismissedConnection.value = readDismissedConnectionAlerts();
}
</script>

<template>
    <Teleport to="body">
        <div
            class="pointer-events-none fixed right-4 top-4 z-[9998] flex w-[min(100vw-2rem,22rem)] flex-col gap-3"
            aria-live="polite"
        >
            <TransitionGroup
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="translate-x-4 opacity-0"
                enter-to-class="translate-x-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-x-0 opacity-100"
                leave-to-class="translate-x-4 opacity-0"
            >
                <article
                    v-for="alert in tokenAlerts"
                    :key="`token-${alert.platform}`"
                    class="pointer-events-auto overflow-hidden rounded-2xl border border-red-500/35 bg-slate-900/95 shadow-xl shadow-black/40 ring-1 ring-red-500/20 backdrop-blur-md"
                    role="alert"
                >
                    <div class="flex gap-0">
                        <div class="w-1 shrink-0 bg-gradient-to-b from-red-400 to-red-700" />
                        <div class="min-w-0 flex-1 p-4">
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-500/15 text-lg"
                                    aria-hidden="true"
                                >
                                    🔑
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium uppercase tracking-wide text-red-300/90">
                                        Token vencido
                                    </p>
                                    <p class="mt-0.5 text-sm font-semibold text-white">
                                        {{ alert.label }}
                                    </p>
                                    <p class="mt-1.5 text-xs leading-relaxed text-slate-300">
                                        {{ alert.message }}
                                    </p>
                                    <p v-if="alert.expires_at" class="mt-1 text-xs text-red-200/70">
                                        Venció {{ formatDate(alert.expires_at) }}
                                    </p>
                                </div>
                            </div>
                            <Link
                                :href="route('settings.index')"
                                class="mt-3 inline-flex w-full items-center justify-center rounded-xl bg-red-600/90 px-3 py-2 text-xs font-medium text-white transition hover:bg-red-500"
                            >
                                Reconectar en Configuración
                            </Link>
                            <p class="mt-2 text-center text-[10px] text-slate-500">
                                Se oculta al renovar el token
                            </p>
                        </div>
                    </div>
                </article>

                <article
                    v-for="alert in visibleConnectionAlerts"
                    :key="`conn-${alert.platform}`"
                    class="pointer-events-auto overflow-hidden rounded-2xl border border-amber-500/30 bg-slate-900/95 shadow-xl shadow-black/40 ring-1 ring-amber-500/15 backdrop-blur-md"
                    role="alert"
                >
                    <div class="flex gap-0">
                        <div class="w-1 shrink-0 bg-gradient-to-b from-amber-300 to-amber-600" />
                        <div class="relative min-w-0 flex-1 p-4">
                            <button
                                type="button"
                                class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-800 hover:text-white"
                                title="Ocultar"
                                aria-label="Ocultar aviso"
                                @click="dismissDisconnected(alert.platform)"
                            >
                                ×
                            </button>
                            <div class="flex items-start gap-3 pr-6">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-lg"
                                    aria-hidden="true"
                                >
                                    🔌
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-medium uppercase tracking-wide text-amber-200/80">
                                        Sin conectar
                                    </p>
                                    <p class="mt-0.5 text-sm font-semibold text-white">
                                        {{ alert.label }}
                                    </p>
                                    <p class="mt-1.5 text-xs leading-relaxed text-slate-300">
                                        {{ alert.message }}
                                    </p>
                                </div>
                            </div>
                            <Link
                                :href="route('settings.index')"
                                class="mt-3 inline-flex w-full items-center justify-center rounded-xl border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-100 transition hover:bg-amber-500/20"
                            >
                                Ir a Configuración
                            </Link>
                        </div>
                    </div>
                </article>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
