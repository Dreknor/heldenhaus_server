import type { LucideIcon } from 'lucide-vue-next';

export interface Stats {
    hp: number;
    max_hp: number;
    mp: number;
    max_mp: number;
    xp: number;
    xp_to_next: number;
    level: number;
    gold: number;
    stars: number;
    unspent_points: number;
    streak_shields: number;
    sleeping: boolean;
    class_key: string | null;
}

export interface Appearance {
    avatar: Partial<import('@/lib/avatar').AvatarConfig> | null;
    seed: number;
    equipment: import('@/lib/avatar').Equipment;
    class_icon: string | null;
}

export interface Auth {
    user: User;
    stats: Stats | null;
    appearance: Appearance | null;
    family: { id: number; name: string } | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: number;
}

export interface SharedData {
    name: string;
    auth: Auth;
    counts: { approvals: number };
    flash: { success: string | null; levelUp: number | null; drop: { name: string; icon: string; rarity: string } | null };
    errors: Record<string, string>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string | null;
    avatar?: string;
    avatar_color: string;
    family_role: 'parent' | 'child' | 'guest' | null;
    age_group: 'child' | 'teen' | 'adult';
    theme: string;
    is_admin: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type TaskType = 'habit' | 'daily' | 'todo' | 'reward';

export type Repeat = {
    type: 'daily' | 'weekly' | 'monthly';
    every?: number;
    days?: number[];
    day_of_month?: number;
};

export interface Task {
    id: number;
    type: TaskType;
    scope: 'personal' | 'family';
    title: string;
    notes: string | null;
    category: string;
    difficulty: number;
    up: boolean;
    down: boolean;
    repeat: Repeat | null;
    start_date: string | null;
    due_date: string | null;
    checklist: string[];
    is_private: boolean;
    approval_mode: 'none' | 'required' | 'photo';
    assignment_mode: 'assigned' | 'pool' | 'rotation' | 'team' | null;
    rotation: { members: number[]; interval: 'daily' | 'weekly' } | null;
    team_bonus_gold: number;
    star_reward: number;
    gold_cost: number;
    ha_event_key: string | null;
    assignees: number[];
}

export interface AssignedTask extends Task {
    assignment_id: number;
    value: number;
    streak: number;
    completed: boolean;
    counter_up: number;
    counter_down: number;
    due_today: boolean | null;
    pending_approval: boolean;
    on_duty: boolean | null;
    team: { done: number; total: number } | null;
}

export interface Member {
    id: number;
    name: string;
    role: 'parent' | 'child' | 'guest';
    role_label: string;
    age_group: 'child' | 'teen' | 'adult';
    birth_year?: number;
    avatar_color: string;
    has_email?: boolean;
    stats?: Stats;
    appearance?: Appearance;
}

export type BreadcrumbItemType = BreadcrumbItem;
