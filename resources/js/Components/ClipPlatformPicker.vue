<script setup>
import { computed } from 'vue';

const props = defineProps({
    inherit: {
        type: Boolean,
        default: true,
    },
    platforms: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Array,
        default: () => [],
    },
    parentPlatforms: {
        type: Array,
        default: () => [],
    },
    error: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:inherit', 'update:platforms']);

const optionByKey = computed(() => Object.fromEntries(props.options.map((item) => [item.key, item])));

const visibleKeys = computed(() => {
    if (props.inherit) {
        return props.parentPlatforms.filter((key) => optionByKey.value[key]);
    }

    return props.options
        .filter((item) => item.enabled && !item.coming_soon)
        .map((item) => item.key);
});

const shortLabel = {
    youtube: 'YouTube',
    facebook_esquinaweb: 'FB Esquinaweb',
    facebook_esquinagamers: 'FB Esquinagamers',
    linkedin: 'LinkedIn Jhon',
    linkedin_jessika: 'LinkedIn Jessika',
    tiktok: 'TikTok',
};

function labelFor(key) {
    return shortLabel[key] ?? optionByKey.value[key]?.label ?? key;
}

function toggleInherit(event) {
    const next = event.target.checked;
    emit('update:inherit', next);
    emit('update:platforms', next ? [...props.parentPlatforms] : [...props.platforms]);
}

function togglePlatform(key) {
    if (props.platforms.includes(key)) {
        emit('update:platforms', props.platforms.filter((item) => item !== key));
        return;
    }

    emit('update:platforms', [...props.platforms, key]);
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm text-slate-300">Plataformas</p>
            <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-400">
                <input :checked="inherit" type="checkbox" @change="toggleInherit" />
                Heredar del original
            </label>
        </div>
        <p class="text-[11px] leading-snug text-slate-500">
            {{ inherit
                ? 'Copia redes y textos del original. Desmarca las que no quieras.'
                : 'Elige redes nuevas. No se copian los captions del original.' }}
        </p>
        <p v-if="inherit && parentPlatforms.length === 0" class="text-xs text-slate-500">
            El original no tiene plataformas. Desactiva heredar para elegir a mano.
        </p>
        <div v-else class="flex flex-wrap gap-1.5">
            <button
                v-for="key in visibleKeys"
                :key="key"
                type="button"
                class="rounded-full border px-2.5 py-1 text-xs transition"
                :class="platforms.includes(key)
                    ? 'border-violet-400 bg-violet-600/25 text-violet-100'
                    : 'border-slate-700 bg-slate-950 text-slate-400 hover:border-slate-500 hover:text-slate-200'"
                @click="togglePlatform(key)"
            >
                {{ labelFor(key) }}
            </button>
        </div>
        <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
    </div>
</template>
