<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    youtube: Object,
    facebook: Array,
});

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString('es-CO', {
        dateStyle: 'long',
        timeStyle: 'short',
    });
}

function renewalClass(renewal) {
    if (renewal.is_expired) {
        return 'text-red-400';
    }

    if (renewal.is_expiring_soon) {
        return 'text-amber-400';
    }

    return 'text-slate-400';
}
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold">Configuración</h2>
            <p class="mt-1 text-slate-400">Conecta tus cuentas de redes sociales.</p>
        </div>

        <div class="space-y-6 max-w-xl">
            <section class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">YouTube</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Canal: {{ youtube.channel_id }}
                        </p>
                        <p class="mt-2 text-sm" :class="youtube.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ youtube.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p v-if="!youtube.has_client" class="mt-2 text-sm text-red-400">
                            Falta YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET en .env
                        </p>
                        <p class="mt-3 text-xs text-slate-500">
                            Redirect URI:
                            <code class="text-slate-400">{{ youtube.redirect_uri }}</code>
                        </p>
                    </div>
                    <div class="flex flex-col gap-2">
                        <a
                            v-if="!youtube.connected && youtube.has_client"
                            :href="route('youtube.oauth.redirect')"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium hover:bg-red-500 text-center"
                        >
                            Conectar YouTube
                        </a>
                        <Link
                            v-if="youtube.connected"
                            :href="route('youtube.oauth.disconnect')"
                            method="post"
                            as="button"
                            class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Desconectar
                        </Link>
                    </div>
                </div>
            </section>

            <section
                v-for="page in facebook"
                :key="page.account"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ page.label }}</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Página: {{ page.page_id || 'PAGE_ID no configurado' }}
                        </p>
                        <p class="mt-2 text-sm" :class="page.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ page.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p v-if="page.connected && page.renewal?.connected_at" class="mt-1 text-xs text-slate-500">
                            Conectado el {{ formatDate(page.renewal.connected_at) }}
                        </p>
                        <p
                            v-if="page.connected && page.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(page.renewal)"
                        >
                            <template v-if="page.renewal.source === 'oauth'">
                                <span v-if="page.renewal.is_expired">
                                    Token vencido — vuelve a conectar antes del {{ formatDate(page.renewal.expires_at) }}
                                </span>
                                <span v-else>
                                    Renovar conexión antes del {{ formatDate(page.renewal.expires_at) }}
                                    <span v-if="page.renewal.days_remaining !== null">
                                        ({{ page.renewal.days_remaining }} día(s) restante(s))
                                    </span>
                                </span>
                            </template>
                            <template v-else>
                                {{ page.renewal.message }}
                            </template>
                        </p>
                        <p v-if="!page.has_client" class="mt-2 text-sm text-red-400">
                            Falta APP_ID / APP_SECRET en .env
                        </p>
                        <p v-else-if="!page.page_id" class="mt-2 text-sm text-red-400">
                            Falta PAGE_ID en .env
                        </p>
                        <p class="mt-3 text-xs text-slate-500">
                            Redirect URI:
                            <code class="text-slate-400">{{ page.redirect_uri }}</code>
                        </p>
                    </div>
                    <div class="flex flex-col gap-2">
                        <a
                            v-if="!page.connected && page.has_client && page.page_id"
                            :href="route('facebook.oauth.redirect', page.account)"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500 text-center"
                        >
                            Conectar
                        </a>
                        <Link
                            v-if="page.connected"
                            :href="route('facebook.oauth.disconnect', page.account)"
                            method="post"
                            as="button"
                            class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Desconectar
                        </Link>
                    </div>
                </div>
            </section>
        </div>

        <div class="mt-6 max-w-xl space-y-3 text-sm text-slate-500">
            <p>
                YouTube: Google pedirá permiso para subir videos. El refresh token se guarda encriptado en la base de datos.
            </p>
            <p>
                Facebook: el token OAuth dura ~60 días. Cuando se acerque la fecha, pulsa «Conectar» de nuevo en Configuración.
            </p>
        </div>
    </AppLayout>
</template>
