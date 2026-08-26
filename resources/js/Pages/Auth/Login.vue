<script setup>
import { Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4">
        <div class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8">
            <h1 class="text-2xl font-semibold text-white">Esquina AI</h1>
            <p class="mt-1 text-sm text-slate-400">Inicia sesión para publicar videos.</p>

            <form class="mt-8 space-y-4" @submit.prevent="form.post(route('login'))">
                <div>
                    <label class="mb-1 block text-sm text-slate-300">Email</label>
                    <input v-model="form.email" type="email" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-400">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-300">Contraseña</label>
                    <input v-model="form.password" type="password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5" />
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input v-model="form.remember" type="checkbox" />
                    Recordarme
                </label>
                <button type="submit" class="w-full rounded-lg bg-violet-600 py-2.5 text-sm font-medium hover:bg-violet-500" :disabled="form.processing">
                    Entrar
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-500">
                ¿Sin cuenta?
                <Link :href="route('register')" class="text-violet-400 hover:underline">Registrarse</Link>
            </p>
        </div>
    </div>
</template>
