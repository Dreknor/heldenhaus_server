<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import StatsPanel from '@/components/game/StatsPanel.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Backpack,
    ChartColumn,
    Dices,
    Download,
    Gift,
    Handshake,
    Inbox,
    ListChecks,
    PiggyBank,
    ScrollText,
    Sparkles,
    Swords,
    Trophy,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const isParent = computed(() => page.props.auth.user?.family_role === 'parent');

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Mein Held', href: '/dashboard', icon: Swords },
    { title: 'Held & Klasse', href: '/hero', icon: Sparkles },
    { title: 'Quests', href: '/quests', icon: ScrollText },
    { title: 'Erfolge', href: '/achievements', icon: Trophy },
    { title: 'Inventar', href: '/inventory', icon: Backpack },
    { title: 'Familie', href: '/family', icon: Users },
    { title: 'Bündnisse & Challenges', href: '/social', icon: Handshake },
    { title: 'Belohnungsshop', href: '/shop', icon: Gift },
    { title: 'Taschengeld', href: '/allowance', icon: PiggyBank },
    ...(isParent.value
        ? [
              { title: 'Familienaufgaben', href: '/family/tasks', icon: ListChecks },
              { title: 'Freigaben', href: '/approvals', icon: Inbox, badge: page.props.counts?.approvals ?? 0 },
              { title: 'Wochenbericht', href: '/family/report', icon: ChartColumn },
          ]
        : []),
    ...(page.props.auth.user?.is_admin
        ? [
              { title: 'Items & Drops', href: '/admin/items', icon: Dices },
              { title: 'KI-Anfragen', href: '/admin/ai-requests', icon: Sparkles },
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
