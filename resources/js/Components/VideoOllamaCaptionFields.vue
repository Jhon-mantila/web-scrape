<script setup>
import { fetchOllamaModels, pickOllamaModel } from '@/support/ollamaCaptionSettings.js';
import { onMounted, ref, watch } from 'vue';

const target = defineModel('target', { type: String, default: 'local' });
const model = defineModel('model', { type: String, default: 'qwen3:14b' });

const props = defineProps({
    ollama: {
        type: Object,
        default: () => ({}),
    },
    enabled: {
        type: Boolean,
        default: true,
    },
    nested: {
        type: Boolean,
        default: false,
    },
});

const models = ref([]);
const loading = ref(false);
const error = ref('');

const remoteConfigured = () => props.ollama?.remote_configured ?? false;
const remoteHost = () => props.ollama?.remote_host ?? 'Cloudflare';
const localHint = () => props.ollama?.local_hint ?? 'OLLAMA_URL';

async function loadModels() {
    if (!props.enabled) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const data = await fetchOllamaModels(target.value || 'local');
        models.value = data.models;
        const names = models.value.map((m) => m.name);
        model.value = pickOllamaModel(names, model.value, data.defaultModel);
    } catch (err) {
        models.value = [];
        error.value = err.response?.data?.message || 'No se pudieron cargar los modelos de Ollama.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.enabled, target.value],
    () => {
        if (props.enabled) {
            loadModels();
        }
    },
);

onMounted(() => {
    if (props.enabled) {
        loadModels();
    }
});
</script>

<template>
    <div
        v-if="enabled"
        class="space-y-3"
        :class="nested ? 'mb-2 ml-7 mt-2 border-l-2 border-slate-800 pl-4' : 'mt-3 border-t border-slate-800 pt-3'"
    >
        <p class="text-xs font-medium text-slate-400">Conexión Ollama</p>
        <div class="flex flex-wrap gap-4 text-xs">
            <label class="flex cursor-pointer items-center gap-2">
                <input v-model="target" type="radio" name="caption_ollama_target" value="local" />
                <span class="text-slate-200">Local ({{ localHint() }})</span>
            </label>
            <label
                class="flex items-center gap-2"
                :class="remoteConfigured() ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'"
            >
                <input
                    v-model="target"
                    type="radio"
                    name="caption_ollama_target"
                    value="remote"
                    :disabled="!remoteConfigured()"
                />
                <span class="text-slate-200">
                    Cloudflare
                    <span v-if="remoteHost()" class="text-slate-500">({{ remoteHost() }})</span>
                </span>
            </label>
        </div>
        <p v-if="!remoteConfigured()" class="text-xs text-slate-500">
            Remoto: define <code class="text-slate-400">OLLAMA_REMOTE_URL=https://esquina-api.cloud</code> en .env.
        </p>
        <label class="block text-xs text-slate-400">
            Modelo
            <select
                v-model="model"
                class="mt-1 w-full max-w-md rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-white disabled:opacity-50"
                :disabled="loading || models.length === 0"
            >
                <option v-if="loading" value="">Cargando modelos…</option>
                <option v-for="m in models" :key="m.name" :value="m.name">
                    {{ m.name }}
                </option>
            </select>
        </label>
        <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        <p v-else-if="!loading && models.length === 0" class="text-xs text-amber-400/90">
            No hay modelos en ese destino. Comprueba Ollama o el túnel (/api/tags).
        </p>
    </div>
</template>
