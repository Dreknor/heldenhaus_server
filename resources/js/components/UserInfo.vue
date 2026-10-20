<script setup lang="ts">
import HeroAvatar from '@/components/game/HeroAvatar.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { SharedData, User } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    user: User;
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();

// Eigener Held als Bild, sonst Initialen
const page = usePage<SharedData>();
const appearance = computed(() => (page.props.auth.user?.id === props.user.id ? page.props.auth.appearance : null));
</script>

<template>
    <HeroAvatar
        v-if="appearance"
        :avatar="appearance.avatar"
        :seed="appearance.seed"
        :equipment="appearance.equipment"
        :class-key="appearance.class_key"
        :size="32"
        portrait
    />
    <Avatar v-else class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="showEmail" class="truncate text-xs text-muted-foreground">{{ user.email }}</span>
    </div>
</template>
