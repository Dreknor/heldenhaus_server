/** Deko eines laufenden Events (§12.7): Farben und Symbole der Deko-Möbel. */
export interface EventDecor {
    colors: string[];
    items: string[];
    icons: string[];
}

/** Kompakte Sicht auf ein laufendes Event (EventService::summary). */
export interface EventSummary {
    id: number;
    key: string;
    name: string;
    icon: string;
    status: string;
    ending_soon: boolean;
    subject_name: string | null;
    decor: EventDecor | null;
    time_progress: number;
    goal_percent: number | null;
    tiers: { percent: number; reached: boolean }[];
    ends_at: string;
}

/** Offenes Zufallsereignis (§12.5, FunEventService::present). */
export interface FunEvent {
    id: number;
    key: string;
    name: string;
    icon: string;
    action: 'tap' | 'pet' | 'wish' | 'find' | 'save_sticker' | 'save_postcard' | 'high_five' | 'pop' | 'none';
    text: string;
    line: string | null;
    postcard: string | null;
    wishes: string[] | null;
    rooms: string[] | null;
    taps: { done: number; needed: number } | null;
    family: boolean;
    first_wins: boolean;
    calm: boolean;
    status: string;
    expires_at: string;
}
