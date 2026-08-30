<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: File,
        default: null,
    },
    accept: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    chooseLabel: {
        type: String,
        default: 'Elegir archivo',
    },
    emptyLabel: {
        type: String,
        default: 'Ningún archivo seleccionado',
    },
    hint: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const inputRef = ref(null);

const fileName = computed(() => props.modelValue?.name ?? null);

function openPicker() {
    inputRef.value?.click();
}

function onChange(event) {
    const file = event.target.files?.[0] ?? null;
    emit('update:modelValue', file);
    emit('change', file);
}

watch(
    () => props.modelValue,
    (file) => {
        if (!file && inputRef.value) {
            inputRef.value.value = '';
        }
    },
);
</script>

<template>
    <div>
        <label v-if="label" class="mb-2 block text-sm font-medium text-slate-300">{{ label }}</label>

        <div
            class="rounded-xl border-2 border-dashed border-slate-600 bg-slate-900/70 p-4 transition-colors hover:border-violet-500/40 hover:bg-slate-900"
        >
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50"
                    @click="openPicker"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" />
                    </svg>
                    {{ chooseLabel }}
                </button>

                <span
                    class="min-w-0 flex-1 truncate text-sm"
                    :class="fileName ? 'font-medium text-emerald-400' : 'text-slate-500'"
                >
                    {{ fileName ?? emptyLabel }}
                </span>
            </div>

            <p v-if="hint" class="mt-2 text-xs text-slate-500">{{ hint }}</p>
        </div>

        <input
            ref="inputRef"
            type="file"
            :accept="accept"
            class="sr-only"
            @change="onChange"
        />
    </div>
</template>
