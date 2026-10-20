// Avatar-Baukasten: Auswahlmöglichkeiten und Standardwerte (selbst erstellte Grafik, siehe Konzept §2.9)

export type AvatarConfig = {
    skin: string;
    hair: string;
    hair_color: string;
    eyes: string;
    outfit: string;
    background: string;
};

export type Equipment = Partial<Record<'head' | 'armor' | 'weapon' | 'shield' | 'accessory', { key: string; rarity: string } | null>>;

export const SKINS = ['#fde0c5', '#f6c89f', '#e0a877', '#c48654', '#8d5a33', '#5c3a21'];
export const HAIR_COLORS = ['#2b1d14', '#5a3825', '#a0522d', '#d9a441', '#f0d48a', '#c0392b', '#9aa3ad', '#8e44ad', '#2e86c1', '#16a085'];
export const OUTFITS = ['#e74c3c', '#3498db', '#27ae60', '#f39c12', '#8e44ad', '#16a085', '#34495e', '#e84393'];
export const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

export const HAIR_STYLES: Record<string, string> = {
    short: 'Kurz',
    spiky: 'Stachelig',
    curly: 'Lockig',
    long: 'Lang',
    ponytail: 'Zopf',
    bun: 'Dutt',
    bald: 'Glatze',
};

export const EYES: Record<string, string> = {
    round: 'Rund',
    happy: 'Fröhlich',
    wink: 'Zwinkern',
    sleepy: 'Verschlafen',
};

export const RARITY_COLORS: Record<string, string> = {
    common: '#94a3b8',
    uncommon: '#22c55e',
    rare: '#3b82f6',
    epic: '#a855f7',
    legendary: '#f59e0b',
};

/** Fester, aber pro Person unterschiedlicher Startavatar (aus der ID abgeleitet). */
export function defaultAvatar(seed: number): AvatarConfig {
    const pick = <T>(list: T[], salt: number) => list[(seed * 7 + salt) % list.length];
    return {
        skin: pick(SKINS, 1),
        hair: pick(Object.keys(HAIR_STYLES).slice(0, 6), 3),
        hair_color: pick(HAIR_COLORS, 5),
        eyes: 'round',
        outfit: pick(OUTFITS, 2),
        background: pick(BACKGROUNDS, 4),
    };
}

/** Etwas dunklere Variante einer Farbe (für Schatten und Konturen innerhalb der Fläche). */
export function shade(hex: string, amount = -0.18): string {
    const n = parseInt(hex.replace('#', ''), 16);
    const f = (c: number) => Math.max(0, Math.min(255, Math.round(c + c * amount)));
    const r = f(n >> 16);
    const g = f((n >> 8) & 0xff);
    const b = f(n & 0xff);
    return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, '0')}`;
}
