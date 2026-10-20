// Avatar-Baukasten: Auswahlmöglichkeiten, Standardwerte und Umsetzung auf DiceBear „Toon Head“
// (Grafik: Johan Melin, CC BY 4.0, siehe CREDITS.md). Muss zu app/Domain/Art/AvatarSvg.php passen.
import { Avatar, Style } from '@dicebear/core';
import definition from '../../avatars/toon-head.json';

export type AvatarConfig = {
    skin: string;
    hair: string;
    hair_color: string;
    eyes: string;
    outfit: string;
    background: string;
    /** Neu mit den Toon-Head-Grafiken, daher optional (ältere Clients senden sie nicht) */
    hair_back?: string;
    clothes?: string;
    beard?: string;
};

export type Equipment = Partial<Record<'head' | 'armor' | 'weapon' | 'shield' | 'accessory', { key: string; rarity: string } | null>>;

export type Mood = 'normal' | 'happy' | 'hurt';

export const SKINS = ['#f1c3a5', '#e8b48f', '#c68e7a', '#b98e6a', '#a36b4f', '#5c3829'];
export const HAIR_COLORS = ['#2c1b18', '#724133', '#a55728', '#b58143', '#d6b370', '#c0392b', '#9aa3ad', '#8e44ad', '#2e86c1', '#16a085'];
export const OUTFITS = ['#b11f1f', '#0b3286', '#147f3c', '#eab308', '#731ac3', '#f97316', '#545454', '#ec4899'];
export const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

export const HAIR_STYLES: Record<string, string> = {
    short: 'Seitenscheitel',
    spiky: 'Stachelig',
    undercut: 'Undercut',
    bun: 'Dutt',
    bald: 'Ohne',
};

export const HAIR_BACK: Record<string, string> = {
    none: 'Kurz',
    neck: 'Nackenlang',
    shoulder: 'Schulterlang',
    long: 'Lang',
    wavy: 'Lang & wellig',
};

export const EYES: Record<string, string> = {
    round: 'Offen',
    happy: 'Fröhlich',
    wink: 'Zwinkern',
    sleepy: 'Verschlafen',
    wide: 'Staunend',
};

export const CLOTHES: Record<string, string> = {
    tShirt: 'T-Shirt',
    shirt: 'Hemd',
    openJacket: 'Jacke',
    turtleNeck: 'Rollkragen',
    dress: 'Kleid',
};

export const BEARDS: Record<string, string> = {
    none: 'Kein Bart',
    moustacheTwirl: 'Schnurrbart',
    chin: 'Kinnbart',
    chinMoustache: 'Kinn & Schnurrbart',
    fullBeard: 'Vollbart',
    longBeard: 'Langer Bart',
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
        hair: pick(Object.keys(HAIR_STYLES).slice(0, 4), 3),
        hair_color: pick(HAIR_COLORS.slice(0, 5), 5),
        eyes: 'round',
        outfit: pick(OUTFITS, 2),
        background: pick(BACKGROUNDS, 4),
        hair_back: pick(Object.keys(HAIR_BACK), 6),
        clothes: 'tShirt',
        beard: 'none',
    };
}

// Frisuren der ersten Avatar-Version, die noch gespeichert sein können: [vorne, hinten]
const LEGACY_HAIR: Record<string, [string, string]> = {
    long: ['short', 'long'],
    curly: ['short', 'wavy'],
    ponytail: ['bun', 'neck'],
};

/** Alte Frisur-Schlüssel in Frisur + Haarlänge aufteilen (für den Baukasten). */
export function normalizeAvatar(config: AvatarConfig): AvatarConfig {
    const legacy = LEGACY_HAIR[config.hair];
    return legacy ? { ...config, hair: legacy[0], hair_back: legacy[1] } : config;
}

const FRONT: Record<string, string | null> = { short: 'sideComed', spiky: 'spiky', undercut: 'undercut', bun: 'bun', bald: null };
const BACK: Record<string, string | null> = { none: null, neck: 'neckHigh', shoulder: 'shoulderHigh', long: 'longStraight', wavy: 'longWavy' };
const EYE_VARIANTS: Record<string, string> = { round: 'happy', happy: 'bow', wink: 'wink', sleepy: 'humble', wide: 'wide' };
const MOOD_FACE: Record<Mood, { mouth: string; eyebrows: string }> = {
    normal: { mouth: 'smile', eyebrows: 'happy' },
    happy: { mouth: 'laugh', eyebrows: 'happy' },
    hurt: { mouth: 'sad', eyebrows: 'sad' },
};

/** Gespeicherte Einstellungen (auch alte oder unvollständige) auf die DiceBear-Optionen abbilden. */
export function toonHeadOptions(config: AvatarConfig, mood: Mood = 'normal'): Record<string, unknown> {
    const [front, back] = LEGACY_HAIR[config.hair] ?? [config.hair, config.hair_back ?? 'none'];
    const hair = FRONT[front] ?? null;
    const rear = BACK[back] ?? null;
    const beard = config.beard && config.beard in BEARDS && config.beard !== 'none' ? config.beard : null;
    const color = (value: string, fallback: string) => (/^#[0-9a-f]{6}$/i.test(value) ? value : fallback);

    return {
        seed: 'heldenhaus',
        skinColor: [color(config.skin, SKINS[0])],
        hairColor: [color(config.hair_color, HAIR_COLORS[0])],
        clothesColor: [color(config.outfit, OUTFITS[0])],
        eyesVariant: [EYE_VARIANTS[config.eyes] ?? 'happy'],
        mouthVariant: [MOOD_FACE[mood].mouth],
        eyebrowsVariant: [MOOD_FACE[mood].eyebrows],
        clothesVariant: [config.clothes && config.clothes in CLOTHES ? config.clothes : 'tShirt'],
        hairProbability: hair ? 100 : 0,
        ...(hair ? { hairVariant: [hair] } : {}),
        rearHairProbability: rear ? 100 : 0,
        ...(rear ? { rearHairVariant: [rear] } : {}),
        beardProbability: beard ? 100 : 0,
        ...(beard ? { beardVariant: [beard] } : {}),
    };
}

let style: Style | null = null;

/** Avatar als SVG-Text (ohne Hintergrund, den legt die Komponente selbst an). */
export function avatarSvg(config: AvatarConfig, mood: Mood = 'normal'): string {
    style ??= new Style(definition as never);
    return new Avatar(style, toonHeadOptions(config, mood) as never).toString();
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
