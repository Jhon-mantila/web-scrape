<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const page = usePage();
const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
const visible = computed(() => success.value || error.value);

watch(visible, (v) => {
    if (v) {
        setTimeout(() => {
            page.props.flash.success = null;
            page.props.flash.error = null;
        }, 5000);
    }
});
</script>

<template>
    <div v-if="visible" class="mb-6 rounded-xl border px-4 py-3 text-sm"
        :class="error ? 'border-red-500/40 bg-red-500/10 text-red-200' : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200'">
        {{ error || success }}
    </div>
</template>
