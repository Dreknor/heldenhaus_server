<script setup lang="ts">
import EMOJI_ART from '@/lib/emoji-art.json';
import { computed } from 'vue';

/**
 * Emoji als 3D-Bild (Fluent Emoji, tools/emoji/build_emoji.py) statt in der Systemschrift,
 * damit Symbole auf allen Geräten gleich und hochwertig aussehen. Unbekannte Zeichen bleiben Text.
 */
const props = withDefaults(defineProps<{ char: string | null | undefined; size?: number; label?: string }>(), { size: 24, label: '' });

const ART = EMOJI_ART as Record<string, string>;
const src = computed(() => {
    const code = props.char ? ART[props.char.replace(/️/g, '')] : undefined;
    return code ? `/images/emoji/${code}.webp` : null;
});
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="label"
        :aria-hidden="label ? undefined : 'true'"
        :width="size"
        :height="size"
        class="inline-block shrink-0 object-contain align-middle"
        :style="{ width: `${size}px`, height: `${size}px` }"
        draggable="false"
    />
    <span v-else-if="char" :aria-hidden="label ? undefined : 'true'" :style="{ fontSize: `${size * 0.85}px`, lineHeight: 1 }">{{ char }}</span>
</template>
