// Gemeinsame Spiel-Hilfen für die Oberfläche

export const difficultyLabels: Record<number, string> = {
    0.1: 'Winzig',
    1: 'Leicht',
    1.5: 'Mittel',
    2: 'Schwer',
};

export const weekdays = [
    { value: 1, label: 'Mo' },
    { value: 2, label: 'Di' },
    { value: 3, label: 'Mi' },
    { value: 4, label: 'Do' },
    { value: 5, label: 'Fr' },
    { value: 6, label: 'Sa' },
    { value: 7, label: 'So' },
];

/** Farbe wie im Original: rot = vernachlässigt, blau = sehr oft erledigt. */
export function valueColor(value: number): string {
    if (value < -20) return 'border-l-red-800 bg-red-50 dark:bg-red-950/40';
    if (value < -10) return 'border-l-red-500 bg-red-50 dark:bg-red-950/30';
    if (value < -1) return 'border-l-orange-400 bg-orange-50 dark:bg-orange-950/30';
    if (value < 1) return 'border-l-amber-300 bg-amber-50/60 dark:bg-amber-950/20';
    if (value < 5) return 'border-l-emerald-400 bg-emerald-50/60 dark:bg-emerald-950/20';
    if (value < 10) return 'border-l-sky-400 bg-sky-50/60 dark:bg-sky-950/20';
    return 'border-l-blue-600 bg-blue-50/60 dark:bg-blue-950/30';
}

export function newUuid(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });
}

export function repeatLabel(repeat: { type: string; every?: number; days?: number[]; day_of_month?: number } | null): string {
    if (!repeat || repeat.type === 'daily') {
        const every = repeat?.every ?? 1;
        return every === 1 ? 'Täglich' : `Alle ${every} Tage`;
    }
    if (repeat.type === 'weekly') {
        return (repeat.days ?? [])
            .map((d) => weekdays.find((w) => w.value === d)?.label)
            .filter(Boolean)
            .join(', ');
    }
    return `Monatlich am ${repeat.day_of_month}.`;
}
