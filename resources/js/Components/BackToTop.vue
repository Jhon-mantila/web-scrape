<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const visible = ref(false);

const enabled = computed(() => {
    const current = route().current();

    if (!current) {
        return false;
    }

    return (
        current === 'dashboard'
        || current.startsWith('scraper.')
        || current.startsWith('videos.')
        || current.startsWith('articles.')
    );
});

function onScroll() {
    visible.value = window.scrollY > 400;
}

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-2 opacity-0"
    >
        <button
            v-if="enabled && visible"
            type="button"
            class="fixed bottom-6 right-6 z-40 flex items-center gap-2 rounded-full border border-violet-500/40 bg-slate-900/95 px-4 py-2.5 text-sm font-medium text-violet-200 shadow-lg shadow-black/30 backdrop-blur hover:border-violet-400 hover:bg-violet-950/90 hover:text-white"
            aria-label="Volver al inicio"
            @click="scrollToTop"
        >
            <span aria-hidden="true" class="text-base leading-none">↑</span>
            Inicio
        </button>
    </Transition>
</template>
