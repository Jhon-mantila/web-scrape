<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    youtube: Object,
});
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold">Configuración</h2>
            <p class="mt-1 text-slate-400">Conecta tus cuentas de redes sociales.</p>
        </div>

        <section class="max-w-xl rounded-2xl border border-slate-800 bg-slate-900/60 p-6">
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
                        Redirect URI registrada en Google:
                        <code class="text-slate-400">{{ youtube.redirect_uri }}</code>
                    </p>
                </div>
                <div class="flex flex-col gap-2">
                    <Link
                        v-if="!youtube.connected && youtube.has_client"
                        :href="route('youtube.oauth.redirect')"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium hover:bg-red-500 text-center"
                    >
                        Conectar YouTube
                    </Link>
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

        <p class="mt-6 max-w-xl text-sm text-slate-500">
            Al conectar, Google pedirá permiso para subir videos a tu canal.
            El refresh token se guarda en la base de datos (encriptado); no necesitas OAuth Playground.
        </p>
    </AppLayout>
</template>
