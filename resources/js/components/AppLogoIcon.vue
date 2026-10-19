<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Heldenhaus-Wappen: Schild mit Haus und Stern (selbst erstellt) -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 44" :class="className" v-bind="$attrs">
        <path
            fill="currentColor"
            fill-rule="evenodd"
            d="M20 1 3 7v13c0 11 7.5 19.5 17 23 9.5-3.5 17-12 17-23V7L20 1Zm0 10.5L9.5 20v13h7.5v-8h6v8h7.5V20L20 11.5Zm0 2.4 1.4 2.9 3.2.5-2.3 2.2.5 3.2-2.8-1.5-2.8 1.5.5-3.2-2.3-2.2 3.2-.5L20 13.9Z"
        />
    </svg>
</template>
