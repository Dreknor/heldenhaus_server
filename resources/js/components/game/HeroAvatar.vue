<script setup lang="ts">
import { type AvatarConfig, type Equipment, type Mood, avatarLayers, defaultAvatar } from '@/lib/avatar';
import { computed } from 'vue';

/** Held als LPC-Pixelfigur: Baukasten, Klassen-Kostüm und angelegte Ausrüstung als übereinanderliegende Ebenen. */
const props = withDefaults(
    defineProps<{
        avatar?: Partial<AvatarConfig> | null;
        equipment?: Equipment;
        seed?: number;
        size?: number;
        /** Nur Kopf und Schultern (für kleine Anzeigen) */
        portrait?: boolean;
        classKey?: string | null;
        mood?: Mood;
        /** Runde Hintergrundscheibe (in Szenen ausblenden) */
        backdrop?: boolean;
    }>(),
    { equipment: () => ({}), seed: 1, size: 120, portrait: false, classKey: null, mood: 'normal', backdrop: true },
);

const a = computed<AvatarConfig>(() => ({ ...defaultAvatar(props.seed), ...(props.avatar ?? {}) }));
const layers = computed(() => avatarLayers(a.value, props.classKey, props.equipment ?? {}, props.mood));
</script>

<template>
    <div
        class="hero-avatar relative shrink-0"
        :class="{ 'is-hurt': mood === 'hurt', 'overflow-hidden rounded-full': backdrop }"
        :style="{ width: `${size}px`, height: `${size}px`, background: backdrop ? a.background : undefined }"
        role="img"
        aria-label="Avatar"
    >
        <div class="absolute inset-0" :class="portrait ? 'origin-[50%_22%] scale-[2.1]' : backdrop ? 'origin-bottom scale-[1.15]' : ''">
            <img v-for="src in layers" :key="src" :src="src" alt="" class="pixelated absolute inset-0 h-full w-full" draggable="false" />
        </div>
    </div>
</template>

<style scoped>
.pixelated {
    image-rendering: pixelated;
}
.is-hurt {
    animation: hurt 0.45s ease-in-out;
}
@keyframes hurt {
    25% {
        transform: translateX(-3px);
        filter: drop-shadow(0 0 6px #ef4444);
    }
    75% {
        transform: translateX(3px);
    }
}
</style>
