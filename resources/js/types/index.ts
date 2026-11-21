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
    class_key: string | null;
}

export interface Auth {
    user: User;
    stats: Stats | null;
    appearance: Appearance | null;
    family: { id: number; name: string; crest?: string } | null;
    feedback?: Feedback | null;
    /** Titel unter dem Namen (§14.7) */
    title?: { level: string | null; mastery: string | null } | null;
    display?: Display | null;
}

export interface Feedback {
    level: 'full' | 'subtle' | 'haptic';
    reduce_motion: boolean;
    sound: boolean;
    haptics: boolean;
}

export type ColorVision = 'normal' | 'protan' | 'deutan' | 'tritan';

/** Darstellung und Barrierefreiheit (§11.4, Phase 8), wirkt als Klassen und Variablen am <html>. */
export interface Display {
    theme: string;
    theme_dark: boolean;
    reduce_motion: boolean;
    high_contrast: boolean;
    text_scale: number;
    dyslexia_font: boolean;
    color_vision: ColorVision;
    focus_mode_default: boolean;
    timer_style: 'disc' | 'digits';
}

/** Fokus- und Darstellungseinstellungen einer Person, wie SupportService::present() sie liefert. */
export interface SupportSettings {
    gentle_mode: boolean;
    gentle_mode_own: boolean | null;
    focus_mode_default: boolean;
    feedback_level: Feedback['level'];
    reduce_motion: boolean;
    sound: boolean;
    haptics: boolean;
    timer_style: 'disc' | 'digits';
    max_steps: number;
    low_energy_per_week: number;
    ai_steps: boolean;
    theme: string;
    theme_own: string | null;
    themes: { key: string; name: string; dark: boolean }[];
    high_contrast: boolean;
    text_scale: number;
    dyslexia_font: boolean;
    color_vision: ColorVision;
    can_change: string[];
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
    /** Noch gesperrt (§13): ab welchem Level, oder von den Eltern aufgeschoben */
    lock?: { level: number; deferred: boolean };
}

export interface NewAchievement {
    id: number;
    key: string;
    title: string;
    description: string;
    motif: string;
    tier: number;
    tiers: number;
    gold: number;
}

export type FeatureKey =
    | 'attributes'
    | 'shop'
    | 'pets'
    | 'quests'
    | 'classes'
    | 'home'
    | 'garden'
    | 'crafting'
    | 'building'
    | 'dungeons'
    | 'family_dungeon'
    | 'alliances';

/** Eine Funktion aus dem Freischalt-Plan (§13). */
export interface FeatureEntry {
    key: FeatureKey;
    title: string;
    emoji: string;
    teaser: string | null;
    href: string;
    level: number;
    state: 'unlocked' | 'deferred' | 'locked';
    open: boolean;
}

export interface FeatureRewards {
    gold?: number;
    xp?: number;
}

export interface FeatureTourStep {
    anchor: string;
    text: string;
}

export interface FeatureIntro {
    key: FeatureKey;
    title: string;
    text: string;
    emoji: string;
    href: string;
    tour: FeatureTourStep[];
    starter_task: { goal: string; rewards?: FeatureRewards } | null;
    collected: { key: string; name: string; icon: string; quantity: number }[] | null;
}

export interface FeatureStarter {
    key: FeatureKey;
    goal: string;
    rewards: FeatureRewards | null;
    title: string;
    emoji: string;
    href: string;
}

export interface FeatureState {
    enforced: boolean;
    show_all: boolean;
    can_show_all: boolean;
    list: FeatureEntry[];
    next: { key: FeatureKey; title: string; level: number; levels_to_go: number; emoji: string; teaser: string | null } | null;
    intro: FeatureIntro | null;
    starters: FeatureStarter[];
}

export interface FeatureTour {
    key: FeatureKey;
    title: string;
    emoji: string | null;
    steps: FeatureTourStep[];
}

export interface SharedData {
    name: string;
    auth: Auth;
    counts: { approvals: number; proposals?: number };
    newAchievements: NewAchievement[];
    features: FeatureState | null;
    tour: FeatureTour | null;
    flash: { success: string | null; levelUp: number | null; drop: { name: string; icon: string; rarity: string; key: string; type: string } | null };
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
    adult_profile?: boolean;
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
    is_core?: boolean;
    scope: 'personal' | 'family';
    title: string;
    notes: string | null;
    category: string;
    difficulty: number;
    up: boolean;
    down: boolean;
    daily_limit: number | null;
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
    // Wenn-dann-Plan und Erinnerung (§14.2), Meistern und Ehrensache (§14.3)
    anchor?: string | null;
    remind_at?: string | null;
    remind_ha?: boolean;
    paused_until?: string | null;
    mastered?: boolean;
    star_fade?: { base: number; from: string | null; step: number; steps: number; honor: boolean } | null;
    assignees: number[];
}

export interface AssignedTask extends Task {
    assignment_id: number;
    steps: { index: number; title: string; done: boolean }[];
    value: number;
    color: { key: 'worst' | 'worse' | 'bad' | 'neutral' | 'good' | 'better' | 'best'; hex: string };
    streak: number;
    completed: boolean;
    counter_up: number;
    counter_down: number;
    habit_today: { up: number; down: number; limit: number; next_factor: number } | null;
    due_today: boolean | null;
    pending_approval: boolean;
    on_duty: boolean | null;
    team: { done: number; total: number } | null;
    reminder_snoozed_until?: string | null;
    reminder_question?: boolean;
}

/** Eltern-Einstellung der Freischaltungen eines Mitglieds (§13). */
export interface MemberFeatureSettings {
    fast_start: boolean;
    show_all: boolean;
    features: {
        key: FeatureKey;
        title: string;
        level: number;
        state: 'unlocked' | 'available' | 'deferred' | 'locked';
        override: 'early' | 'defer' | null;
        can_defer: boolean;
    }[];
}

export interface Member {
    id: number;
    name: string;
    role: 'parent' | 'child' | 'guest';
    role_label: string;
    chat_allowed?: boolean;
    age_group: 'child' | 'teen' | 'adult';
    birth_year?: number;
    avatar_color: string;
    has_email?: boolean;
    adult_profile?: boolean;
    stats?: Stats;
    appearance?: Appearance;
    featureSettings?: MemberFeatureSettings;
    /** Nur für Eltern; can_change aus Sicht der Eltern (SupportService::editableBy) */
    supportSettings?: SupportSettings;
}

export type BreadcrumbItemType = BreadcrumbItem;
