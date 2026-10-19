<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import StatsPanel from '@/components/game/StatsPanel.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Backpack, Dices, Download, Gift, Inbox, ListChecks, Sparkles, Swords, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const isParent = computed(() => page.props.auth.user?.family_role === 'parent');

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Mein Held', href: '/dashboard', icon: Swords },
    { title: 'Held & Klasse', href: '/hero', icon: Sparkles },
    { title: 'Inventar', href: '/inventory', icon: Backpack },
    { title: 'Familie', href: '/family', icon: Users },
    { title: 'Belohnungsshop', href: '/shop', icon: Gift },
    ...(isParent.value
        ? [
              { title: 'Familienaufgaben', href: '/family/tasks', icon: ListChecks },
              { title: 'Freigaben', href: '/approvals', icon: Inbox, badge: page.props.counts?.approvals ?? 0 },
          ]
        : []),
    ...(page.props.auth.user?.is_admin
        ? [
              { title: 'Items & Drops', href: '/admin/items', icon: Dices },
              { title: 'System-Updates', href: '/admin/updates', icon: Download },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <StatsPanel v-if="page.props.auth.stats" :stats="page.props.auth.stats" class="mx-2 mt-2 group-data-[collapsible=icon]:hidden" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
