// Ziele (§14.1) – gemeinsame Typen für Ziel-Seite, Karte und Dialog

export type GoalType = 'weekly' | 'milestone' | 'count';

export interface GoalProgress {
    done: number;
    target: number;
    percent: number;
    skipped?: number;
    week_start?: string;
}

export interface Goal {
    id: number;
    user_id: number;
    owner: string;
    type: GoalType;
    type_label: string;
    source: string;
    title: string;
    why: string;
    icon: string;
    category: string | null;
    target: number | null;
    unit: string | null;
    status: 'active' | 'reached' | 'expired' | 'released';
    is_private: boolean;
    ends_on: string | null;
    reward_stars: number;
    reward_wish: string | null;
    weeks_reached: number;
    reached_at: string | null;
    progress: GoalProgress;
    milestones: { id: number; title: string; done: boolean; requested: boolean; done_at: string | null }[];
    tasks: { id: number; title: string; type: string }[];
    supporters: { id: number; name: string }[];
    can_edit: boolean;
    can_support: boolean;
    is_supporter: boolean;
}

export interface GoalTemplate {
    key: string;
    type: GoalType;
    title: string;
    icon: string;
    category: string;
    ages: string[];
    why: string[];
    target?: number;
    unit?: string;
    milestones?: string[];
}

export interface LinkableTask {
    id: number;
    title: string;
    type: string;
}

export interface GoalList {
    goals: Goal[];
    private_count: number;
    active_count: number;
    max_active: number;
}

/** „3 von 5 diese Woche“, „7 von 10 Bücher“, „2 von 4 Schritten“ */
export function progressLabel(goal: Goal): string {
    const p = goal.progress;
    if (goal.type === 'weekly') return `${p.done} von ${p.target} diese Woche`;
    if (goal.type === 'count') return `${p.done} von ${p.target}${goal.unit ? ` ${goal.unit}` : ''}`;
    return `${p.done} von ${p.target} Schritten`;
}
