<script setup lang="ts">
import { emojiUrl } from '@/lib/emoji';
import { computed } from 'vue';

/** Emoji als 3D-Bild (Fluent Emoji) in Textgröße; ohne Bild bleibt das Text-Emoji. */
const props = defineProps<{ glyph?: string | null }>();
const src = computed(() => emojiUrl(props.glyph));
</script>

<template>
    <img v-if="src" :src="src" alt="" aria-hidden="true" class="inline-block h-[1.2em] w-[1.2em] object-contain align-[-0.25em]" draggable="false" />
    <template v-else>{{ glyph }}</template>
</template>
