<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Delete } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    code: string | null;
    familyName: string | null;
    members: { id: number; name: string; avatar_color: string }[];
}>();

const codeInput = ref('');
const hadCode = typeof window !== 'undefined' && window.location.search.includes('code=');
const selected = ref<number | null>(null);
const form = useForm({ code: props.code ?? '', user_id: 0, pin: '' });
const selectedMember = computed(() => props.members.find((m) => m.id === selected.value));

function findFamily() {
    router.get(route('family-login'), { code: codeInput.value.trim() });
}

function choose(id: number) {
    selected.value = id;
    form.user_id = id;
    form.pin = '';
    form.clearErrors();
}

function press(digit: string) {
    if (form.pin.length >= 6) return;
    form.pin += digit;
    if (form.pin.length >= 4) form.clearErrors();
}

function submit() {
    form.post(route('family-login'), { onError: () => (form.pin = '') });
}
</script>

<template>
    <AuthBase :title="familyName ? `Wer spielt? – ${familyName}` : 'Kinder-Login'" :description="familyName ? 'Tippe auf dein Bild und gib deine PIN ein.' : 'Gib den Familiencode ein, den dir deine Eltern gezeigt haben.'">
        <Head title="Kinder-Login" />

        <!-- Schritt 1: Familiencode -->
        <form v-if="!code" class="grid gap-3" @submit.prevent="findFamily">
            <Input v-model="codeInput" placeholder="Familiencode" class="h-12 text-center font-mono text-2xl uppercase tracking-widest" required autofocus />
            <p v-if="codeInput === '' && hadCode" class="text-sm text-rose-600">Diesen Familiencode gibt es nicht.</p>
            <Button type="submit" class="h-11">Weiter</Button>
        </form>

        <!-- Schritt 2: Profil -->
        <div v-else-if="!selectedMember" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <button
                v-for="m in members"
                :key="m.id"
                class="flex flex-col items-center gap-2 rounded-2xl border-2 border-transparent p-3 transition hover:border-primary hover:bg-muted"
                @click="choose(m.id)"
            >
                <span class="flex size-20 items-center justify-center rounded-3xl text-4xl font-extrabold text-white shadow-md" :style="{ background: m.avatar_color }">
                    {{ m.name.charAt(0) }}
                </span>
                <span class="font-bold">{{ m.name }}</span>
            </button>
            <p v-if="!members.length" class="col-span-full text-center text-sm text-muted-foreground">Noch keine Kinderprofile – Eltern legen sie unter „Familie“ an.</p>
        </div>

        <!-- Schritt 3: PIN -->
        <form v-else class="grid gap-4" @submit.prevent="submit">
            <button type="button" class="flex items-center gap-1 text-sm text-muted-foreground" @click="selected = null"><ArrowLeft class="size-4" /> Anderes Profil</button>
            <div class="flex flex-col items-center gap-2">
                <span class="flex size-16 items-center justify-center rounded-2xl text-3xl font-extrabold text-white" :style="{ background: selectedMember.avatar_color }">
                    {{ selectedMember.name.charAt(0) }}
                </span>
                <span class="text-lg font-bold">Hallo {{ selectedMember.name }}!</span>
            </div>
            <div class="flex justify-center gap-2">
                <span v-for="i in 6" :key="i" class="size-4 rounded-full border-2" :class="i <= form.pin.length ? 'border-primary bg-primary' : 'border-muted-foreground/40'" />
            </div>
            <InputError :message="form.errors.pin" class="text-center" />
            <div class="mx-auto grid w-64 grid-cols-3 gap-2">
                <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" type="button" class="h-14 rounded-xl bg-muted text-2xl font-bold hover:bg-muted/70 active:scale-95" @click="press(d)">
                    {{ d }}
                </button>
                <button type="button" class="flex h-14 items-center justify-center rounded-xl hover:bg-muted" aria-label="Löschen" @click="form.pin = form.pin.slice(0, -1)"><Delete class="size-6" /></button>
                <button type="button" class="h-14 rounded-xl bg-muted text-2xl font-bold hover:bg-muted/70 active:scale-95" @click="press('0')">0</button>
                <Button type="submit" class="h-14 text-lg" :disabled="form.pin.length < 4 || form.processing">OK</Button>
            </div>
        </form>

        <div class="mt-6 text-center text-sm text-muted-foreground">
            Eltern melden sich <TextLink :href="route('login')">hier mit E-Mail</TextLink> an.
        </div>
    </AuthBase>
</template>
