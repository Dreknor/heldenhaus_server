<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';

interface NavItem {
    badge?: number;
    title: string;
    href: string;
    icon?: Component;
}

defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Heldenhaus</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="page.url.split('?')[0] === item.href">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span v-if="item.badge" class="ml-auto rounded-full bg-amber-500 px-2 text-xs font-bold text-white">{{ item.badge }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
