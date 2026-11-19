<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarHero from '@/components/game/SidebarHero.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useFeatures } from '@/composables/useFeatures';
import { type FeatureKey, type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Backpack,
    BookOpen,
    CalendarDays,
    ChartColumn,
    Dices,
    Download,
    Gift,
    Hammer,
    Handshake,
    House,
    Inbox,
    ListChecks,
    PartyPopper,
    PiggyBank,
    ScrollText,
    Settings,
    Skull,
    Sparkles,
    Swords,
    Target,
    Trophy,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const isParent = computed(() => page.props.auth.user?.family_role === 'parent');
const { lockOf } = useFeatures();

// Ganz gesperrte Seiten (§13) zeigen in der Navigation ein Schloss mit Level; ein Klick öffnet die Vorschau
const locked = (keys: FeatureKey[]): Pick<NavItem, 'lock'> => {
    const f = lockOf(keys);
    return f ? { lock: { level: f.level, deferred: f.state === 'deferred' } } : {};
};

const groups = computed<{ label: string; items: NavItem[] }[]>(() => [
    {
        label: 'Abenteuer',
        items: [
            { title: 'Helden-Board', href: '/dashboard', icon: Swords },
            { title: 'Ziele', href: '/goals', icon: Target },
            { title: 'Held & Klasse', href: '/hero', icon: Sparkles },
            { title: 'Quests', href: '/quests', icon: ScrollText },
            { title: 'Dungeons', href: '/dungeons', icon: Skull, ...locked(['dungeons', 'family_dungeon']) },
            { title: 'Events', href: '/events', icon: CalendarDays },
            { title: 'Erfolge', href: '/achievements', icon: Trophy },
            { title: 'Heldenbuch', href: '/heldenbuch', icon: BookOpen },
        ],
    },
    {
        label: 'Zuhause',
        items: [
            { title: 'Inventar', href: '/inventory', icon: Backpack },
            { title: 'Haus & Garten', href: '/housing', icon: House, ...locked(['home']) },
            { title: 'Handwerk & Lager', href: '/crafting', icon: Hammer, ...locked(['crafting', 'building']) },
            { title: 'Belohnungsshop', href: '/shop', icon: Gift },
            { title: 'Taschengeld', href: '/allowance', icon: PiggyBank },
        ],
    },
    {
        label: 'Familie & Bündnisse',
        items: [
            { title: 'Familie', href: '/family', icon: Users },
            { title: 'Bündnisse & Challenges', href: '/social', icon: Handshake, ...locked(['alliances']) },
        ],
    },
    ...(isParent.value
        ? [
              {
                  label: 'Eltern-Hub',
                  items: [
                      { title: 'Freigaben', href: '/approvals', icon: Inbox, badge: page.props.counts?.approvals ?? 0 },
                      { title: 'Familienaufgaben', href: '/family/tasks', icon: ListChecks },
                      { title: 'Wochenbericht', href: '/family/report', icon: ChartColumn },
                      { title: 'Familieneinstellungen', href: '/family/settings', icon: Settings },
                  ],
              },
          ]
        : []),
    ...(page.props.auth.user?.is_admin
        ? [
              {
                  label: 'Verwaltung',
                  items: [
                      { title: 'Items & Drops', href: '/admin/items', icon: Dices },
                      { title: 'Event-Editor', href: '/admin/events', icon: PartyPopper },
                      { title: 'KI-Anfragen', href: '/admin/ai-requests', icon: Sparkles },
                      { title: 'System-Updates', href: '/admin/updates', icon: Download },
                  ],
              },
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
            <SidebarHero class="mt-1 group-data-[collapsible=icon]:hidden" />
            <NavMain v-for="group in groups" :key="group.label" :label="group.label" :items="group.items" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
