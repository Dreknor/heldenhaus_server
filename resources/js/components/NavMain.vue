<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import type { Component } from 'vue';

interface NavItem {
    badge?: number;
    title: string;
    href: string;
    icon?: Component;
    lock?: { level: number; deferred: boolean };
}

defineProps<{
    items: NavItem[];
    label?: string;
}>();

const page = usePage<SharedData>();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel class="font-pixel text-[13px] uppercase tracking-wider text-sidebar-foreground/55">{{
            label ?? 'Heldenhaus'
        }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="page.url.split('?')[0] === item.href">
                    <Link
                        :href="item.href"
                        :class="item.lock && 'opacity-70'"
                        :title="
                            item.lock
                                ? item.lock.deferred
                                    ? `${item.title}: kommt etwas später`
                                    : `${item.title}: ab Level ${item.lock.level}`
                                : undefined
                        "
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.lock"
                            class="ml-auto flex items-center gap-0.5 rounded-sm border border-black/40 bg-black/25 px-1 text-[11px] font-extrabold"
                            :aria-label="item.lock.deferred ? 'gesperrt' : `gesperrt bis Level ${item.lock.level}`"
                        >
                            <Lock class="!size-3" />{{ item.lock.deferred ? '' : `Lv ${item.lock.level}` }}
                        </span>
                        <span
                            v-if="item.badge"
                            class="ml-auto rounded-sm border border-black/50 bg-[var(--gold)] px-1.5 text-xs font-extrabold text-[var(--wood-edge)]"
                            >{{ item.badge }}</span
                        >
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
