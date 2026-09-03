<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    youtube: Object,
    linkedin: Array,
    facebook: Array,
});

const linkedinJhon = computed(() => props.linkedin.find((p) => p.account === 'default'));
const linkedinJessika = computed(() => props.linkedin.find((p) => p.account === 'jessika'));
const facebookEsquinaweb = computed(() => props.facebook.find((p) => p.account === 'esquinaweb'));
const facebookEsquinagamers = computed(() => props.facebook.find((p) => p.account === 'esquinagamers'));

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

function linkedinConnectUrl(account) {
    return account === 'default'
        ? route('linkedin.oauth.redirect')
        : route('linkedin.oauth.redirect.account', account);
}

function linkedinDisconnectUrl(account) {
    return account === 'default'
        ? route('linkedin.oauth.disconnect')
        : route('linkedin.oauth.disconnect.account', account);
}
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold">Configuración</h2>
            <p class="mt-1 text-slate-400">Conecta tus cuentas de redes sociales.</p>
        </div>

        <div class="grid max-w-5xl gap-4 md:grid-cols-2">
            <!-- YouTube -->
            <section class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 md:col-span-2 lg:col-span-1">
                <div class="flex h-full flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">YouTube</h3>
                        <p class="mt-1 text-sm text-slate-400">Canal: {{ youtube.channel_id }}</p>
                        <p class="mt-2 text-sm" :class="youtube.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ youtube.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p v-if="youtube.connected && youtube.renewal?.connected_at" class="mt-1 text-xs text-slate-500">
                            Conectado el {{ formatDate(youtube.renewal.connected_at) }}
                        </p>
                        <p
                            v-if="youtube.connected && youtube.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(youtube.renewal)"
                        >
                            <template v-if="youtube.renewal.source === 'oauth' && youtube.renewal.expires_at">
                                <span v-if="youtube.renewal.is_expired">Refresh token vencido — vuelve a conectar</span>
                                <span v-else>
                                    Reconectar antes del {{ formatDate(youtube.renewal.expires_at) }}
                                    <span v-if="youtube.renewal.days_remaining !== null">
                                        ({{ youtube.renewal.days_remaining }} d)
                                    </span>
                                </span>
                            </template>
                            <template v-else>{{ youtube.renewal.message }}</template>
                        </p>
                        <p v-if="!youtube.has_client" class="mt-2 text-sm text-red-400">
                            Falta YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET en .env
                        </p>
                        <p class="mt-3 text-xs text-slate-500">
                            Redirect URI:
                            <code class="text-slate-400">{{ youtube.redirect_uri }}</code>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a
                            v-if="!youtube.connected && youtube.has_client"
                            :href="route('youtube.oauth.redirect')"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium hover:bg-red-500"
                        >
                            Conectar
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

            <div class="hidden lg:block" aria-hidden="true" />

            <!-- LinkedIn — Jhon -->
            <section
                v-if="linkedinJhon"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="flex h-full flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ linkedinJhon.label }}</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Perfil personal
                            <span v-if="linkedinJhon.renewal?.profile_name"> — {{ linkedinJhon.renewal.profile_name }}</span>
                        </p>
                        <p class="mt-2 text-sm" :class="linkedinJhon.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ linkedinJhon.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p v-if="linkedinJhon.connected && linkedinJhon.renewal?.connected_at" class="mt-1 text-xs text-slate-500">
                            Conectado el {{ formatDate(linkedinJhon.renewal.connected_at) }}
                        </p>
                        <p
                            v-if="linkedinJhon.connected && linkedinJhon.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(linkedinJhon.renewal)"
                        >
                            <template v-if="linkedinJhon.renewal.source === 'oauth'">
                                <span v-if="linkedinJhon.renewal.is_expired">Token vencido — vuelve a conectar</span>
                                <span v-else>
                                    Renovar antes del {{ formatDate(linkedinJhon.renewal.expires_at) }}
                                    <span v-if="linkedinJhon.renewal.days_remaining !== null">
                                        ({{ linkedinJhon.renewal.days_remaining }} d)
                                    </span>
                                </span>
                            </template>
                            <template v-else>{{ linkedinJhon.renewal.message }}</template>
                        </p>
                        <p v-if="!linkedinJhon.has_client" class="mt-2 text-sm text-red-400">
                            Falta LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET en .env
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a
                            v-if="!linkedinJhon.connected && linkedinJhon.has_client"
                            :href="linkedinConnectUrl('default')"
                            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium hover:bg-sky-600"
                        >
                            Conectar
                        </a>
                        <Link
                            v-if="linkedinJhon.connected"
                            :href="linkedinDisconnectUrl('default')"
                            method="post"
                            as="button"
                            class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Desconectar
                        </Link>
                    </div>
                </div>
            </section>

            <!-- LinkedIn — Jessika -->
            <section
                v-if="linkedinJessika"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="flex h-full flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ linkedinJessika.label }}</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Perfil personal
                            <span v-if="linkedinJessika.renewal?.profile_name"> — {{ linkedinJessika.renewal.profile_name }}</span>
                        </p>
                        <p class="mt-2 text-sm" :class="linkedinJessika.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ linkedinJessika.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p v-if="linkedinJessika.connected && linkedinJessika.renewal?.connected_at" class="mt-1 text-xs text-slate-500">
                            Conectado el {{ formatDate(linkedinJessika.renewal.connected_at) }}
                        </p>
                        <p
                            v-if="linkedinJessika.connected && linkedinJessika.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(linkedinJessika.renewal)"
                        >
                            <template v-if="linkedinJessika.renewal.source === 'oauth'">
                                <span v-if="linkedinJessika.renewal.is_expired">Token vencido — vuelve a conectar</span>
                                <span v-else>
                                    Renovar antes del {{ formatDate(linkedinJessika.renewal.expires_at) }}
                                    <span v-if="linkedinJessika.renewal.days_remaining !== null">
                                        ({{ linkedinJessika.renewal.days_remaining }} d)
                                    </span>
                                </span>
                            </template>
                            <template v-else>{{ linkedinJessika.renewal.message }}</template>
                        </p>
                        <p v-if="!linkedinJessika.has_client" class="mt-2 text-sm text-red-400">
                            Falta LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET en .env
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a
                            v-if="!linkedinJessika.connected && linkedinJessika.has_client"
                            :href="linkedinConnectUrl('jessika')"
                            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium hover:bg-sky-600"
                        >
                            Conectar
                        </a>
                        <Link
                            v-if="linkedinJessika.connected"
                            :href="linkedinDisconnectUrl('jessika')"
                            method="post"
                            as="button"
                            class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Desconectar
                        </Link>
                    </div>
                </div>
            </section>

            <!-- Facebook Esquinaweb -->
            <section
                v-if="facebookEsquinaweb"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="flex h-full flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ facebookEsquinaweb.label }}</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Página: {{ facebookEsquinaweb.page_id || 'PAGE_ID no configurado' }}
                        </p>
                        <p class="mt-2 text-sm" :class="facebookEsquinaweb.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ facebookEsquinaweb.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p
                            v-if="facebookEsquinaweb.connected && facebookEsquinaweb.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(facebookEsquinaweb.renewal)"
                        >
                            <template v-if="facebookEsquinaweb.renewal.source === 'oauth'">
                                Renovar antes del {{ formatDate(facebookEsquinaweb.renewal.expires_at) }}
                            </template>
                            <template v-else>{{ facebookEsquinaweb.renewal.message }}</template>
                        </p>
                        <p v-if="!facebookEsquinaweb.has_client" class="mt-2 text-sm text-red-400">
                            Falta APP_ID / APP_SECRET en .env
                        </p>
                        <p v-else-if="!facebookEsquinaweb.page_id" class="mt-2 text-sm text-red-400">
                            Falta PAGE_ID en .env
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a
                            v-if="!facebookEsquinaweb.connected && facebookEsquinaweb.has_client && facebookEsquinaweb.page_id"
                            :href="route('facebook.oauth.redirect', facebookEsquinaweb.account)"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500"
                        >
                            Conectar
                        </a>
                        <Link
                            v-if="facebookEsquinaweb.connected"
                            :href="route('facebook.oauth.disconnect', facebookEsquinaweb.account)"
                            method="post"
                            as="button"
                            class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800"
                        >
                            Desconectar
                        </Link>
                    </div>
                </div>
            </section>

            <!-- Facebook Esquinagamers -->
            <section
                v-if="facebookEsquinagamers"
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <div class="flex h-full flex-col justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ facebookEsquinagamers.label }}</h3>
                        <p class="mt-1 text-sm text-slate-400">
                            Página: {{ facebookEsquinagamers.page_id || 'PAGE_ID no configurado' }}
                        </p>
                        <p class="mt-2 text-sm" :class="facebookEsquinagamers.connected ? 'text-emerald-400' : 'text-amber-400'">
                            {{ facebookEsquinagamers.connected ? '✅ Conectado' : '⏳ Sin conectar' }}
                        </p>
                        <p
                            v-if="facebookEsquinagamers.connected && facebookEsquinagamers.renewal"
                            class="mt-2 text-sm"
                            :class="renewalClass(facebookEsquinagamers.renewal)"
                        >
                            <template v-if="facebookEsquinagamers.renewal.source === 'oauth'">
                                Renovar antes del {{ formatDate(facebookEsquinagamers.renewal.expires_at) }}
                            </template>
                            <template v-else>{{ facebookEsquinagamers.renewal.message }}</template>
                        </p>
                        <p v-if="!facebookEsquinagamers.has_client" class="mt-2 text-sm text-red-400">
                            Falta APP_ID / APP_SECRET en .env
                        </p>
                        <p v-else-if="!facebookEsquinagamers.page_id" class="mt-2 text-sm text-red-400">
                            Falta PAGE_ID en .env
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a
                            v-if="!facebookEsquinagamers.connected && facebookEsquinagamers.has_client && facebookEsquinagamers.page_id"
                            :href="route('facebook.oauth.redirect', facebookEsquinagamers.account)"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500"
                        >
                            Conectar
                        </a>
                        <Link
                            v-if="facebookEsquinagamers.connected"
                            :href="route('facebook.oauth.disconnect', facebookEsquinagamers.account)"
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

        <div class="mt-6 max-w-5xl space-y-3 text-sm text-slate-500">
            <p>
                YouTube: Google guarda un refresh token (no el access token de 1 h). Si tu app OAuth está en
                <em>Testing</em>, reconecta cada ~7 días; en <em>Production</em> no caduca salvo revocación.
                El access token se renueva solo al publicar.
            </p>
            <p>
                LinkedIn: misma app para todos los perfiles. Cada persona inicia sesión con su cuenta al conectar.
                El token dura ~60 días. Redirect URI:
                <code class="text-slate-400">{{ linkedinJhon?.redirect_uri }}</code>
            </p>
            <p>
                Facebook: el token OAuth dura ~60 días. Cuando se acerque la fecha, pulsa «Conectar» de nuevo en Configuración.
            </p>
        </div>
    </AppLayout>
</template>
