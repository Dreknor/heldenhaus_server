// Avatar-Baukasten mit LPC-Pixelfiguren (Universal LPC Spritesheet Generator, Credits in CREDITS.md):
// Körper, Frisur und Kleidung aus dem Baukasten, darüber Klassen-Kostüm und angelegte Ausrüstung.
// Gleiche Regeln wie app/Domain/Art/AvatarSvg.php, beide lesen resources/avatars/lpc*.json.
import lpcRules from '../../avatars/lpc-rules.json';
import manifest from '../../avatars/lpc.json';

export type AvatarConfig = {
    skin: string;
    hair: string;
    hair_color: string;
    eyes: string;
    outfit: string;
    background: string;
    /** Optional, ältere Clients senden sie nicht */
    body?: string;
    clothes?: string;
    beard?: string;
    hair_back?: string;
};

export type Slot = 'head' | 'armor' | 'weapon' | 'shield' | 'accessory';
export type Equipment = Partial<Record<Slot, { key: string; rarity: string } | null>>;
export type Mood = 'normal' | 'happy' | 'hurt';

type Pair = [string, string];
type Parts = Record<string, Record<string, Record<string, [number, string][]>>>;

type Rules = {
    hair: Record<string, string | null>;
    hair_legacy: Record<string, string>;
    hair_back_legacy: Record<string, string>;
    eyes: Record<string, string | null>;
    hurt_face: string;
    clothes: Record<string, Pair[]>;
    beards: Record<string, string | null>;
    base: Pair[];
    classes: Record<string, Partial<Record<Slot | 'back', Pair[]>>>;
    items: Record<string, Pair[]>;
    keywords: { slot: string; words: string[]; parts: Pair[] }[];
    rarities: string[];
    slots: Record<string, Pair[][]>;
};

const rules = lpcRules as unknown as Rules;
const palettes = manifest.palettes as Record<'body' | 'hair' | 'cloth', Record<string, string>>;
const parts = manifest.parts as unknown as Parts;

export const SKINS = Object.values(palettes.body);
export const HAIR_COLORS = Object.values(palettes.hair);
export const OUTFITS = Object.values(palettes.cloth);
export const BACKGROUNDS = ['#fde68a', '#bfdbfe', '#bbf7d0', '#fbcfe8', '#ddd6fe', '#fed7aa', '#e2e8f0', '#99f6e4'];

export const BODIES: Record<string, string> = { male: 'Breit', female: 'Schmal' };

export const HAIR_STYLES: Record<string, string> = {
    plain: 'Kurz',
    spiked: 'Stachelig',
    curly_short: 'Lockig',
    cowlick: 'Wuschelig',
    buzzcut: 'Stoppel',
    afro: 'Afro',
    bob: 'Bob',
    long_straight: 'Lang',
    wavy: 'Wellig',
    high_ponytail: 'Pferdeschwanz',
    braid: 'Zopf',
    bangs_bun: 'Dutt',
    bald: 'Ohne',
};

export const EYES: Record<string, string> = {
    round: 'Offen',
    happy: 'Fröhlich',
    sleepy: 'Verschlafen',
    wide: 'Staunend',
};

export const CLOTHES: Record<string, string> = {
    tShirt: 'T-Shirt',
    shirt: 'Hemd',
    openJacket: 'Strickjacke',
    turtleNeck: 'Pulli',
    dress: 'Rock',
};

export const BEARDS: Record<string, string> = {
    none: 'Kein Bart',
    moustacheTwirl: 'Schnurrbart',
    chin: 'Kurzer Bart',
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
        hair: pick(Object.keys(HAIR_STYLES).slice(0, 12), 3),
        hair_color: pick(HAIR_COLORS.slice(0, 8), 5),
        eyes: 'round',
        outfit: pick(OUTFITS, 2),
        background: pick(BACKGROUNDS, 4),
        body: pick(Object.keys(BODIES), 6),
        clothes: 'tShirt',
        beard: 'none',
    };
}

/** Alte Werte (erste Avatar-Versionen) auf die heutigen Baukasten-Werte bringen. */
export function normalizeAvatar(config: AvatarConfig): AvatarConfig {
    return {
        ...config,
        hair: hairKey(config),
        skin: palettes.body[nearest('body', config.skin)],
        hair_color: palettes.hair[nearest('hair', config.hair_color)],
        outfit: palettes.cloth[nearest('cloth', config.outfit)],
        hair_back: undefined,
    };
}

function hairKey(config: AvatarConfig): string {
    const legacyBack = rules.hair_back_legacy[config.hair_back ?? ''];
    if (config.hair === 'short' && legacyBack) return legacyBack;
    return rules.hair_legacy[config.hair] ?? (config.hair in rules.hair ? config.hair : 'plain');
}

/** Nächste Palettenfarbe zu einem gespeicherten Hex-Wert. */
function nearest(material: 'body' | 'hair' | 'cloth', hex: string): string {
    const rgb = (h: string) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
    const target = /^#[0-9a-f]{6}$/i.test(hex) ? rgb(hex) : [128, 128, 128];
    let best = Object.keys(palettes[material])[0];
    let bestDistance = Infinity;
    for (const [name, value] of Object.entries(palettes[material])) {
        const d = rgb(value).reduce((sum, c, i) => sum + (c - target[i]) ** 2, 0);
        if (d < bestDistance) [best, bestDistance] = [name, d];
    }
    return best;
}

/** Teile für einen Ausrüstungsgegenstand: bekannter Gegenstand, Stichwort im Schlüssel oder Slot + Seltenheit. */
function itemParts(slot: Slot, item: { key: string; rarity: string }): Pair[] {
    const known = rules.items[item.key];
    if (known) return known;
    const r = Math.max(0, rules.rarities.indexOf(item.rarity));
    const key = item.key.toLowerCase();
    const hit = rules.keywords.find((k) => k.slot === slot && k.words.some((w) => key.includes(w)));
    if (hit) return [hit.parts[Math.min(r, hit.parts.length - 1)]];
    return rules.slots[slot][r];
}

/** Bildebenen (URLs, von hinten nach vorn) für Avatar, Klasse und Ausrüstung. */
export function avatarLayers(config: AvatarConfig, classKey: string | null = null, equipment: Equipment = {}, mood: Mood = 'normal'): string[] {
    const body = config.body === 'female' ? 'female' : 'male';
    const colors: Record<string, string> = {
        $skin: nearest('body', config.skin),
        $hair: nearest('hair', config.hair_color),
        $outfit: nearest('cloth', config.outfit),
    };
    const hair = rules.hair[hairKey(config)];
    const face = mood === 'hurt' ? rules.hurt_face : rules.eyes[config.eyes];
    const beard = rules.beards[config.beard ?? 'none'];

    const wanted: Pair[] = [
        ...rules.base,
        ...(rules.clothes[config.clothes ?? 'tShirt'] ?? rules.clothes.tShirt),
        ...(face ? [[face, '$skin'] as Pair] : []),
        ...(hair ? [[hair, '$hair'] as Pair] : []),
        ...(beard ? [[beard, '$hair'] as Pair] : []),
    ];

    const costume = rules.classes[classKey ?? ''] ?? {};
    wanted.push(...(costume.back ?? []));
    for (const slot of ['armor', 'head', 'weapon', 'shield', 'accessory'] as Slot[]) {
        const item = equipment[slot];
        wanted.push(...(item ? itemParts(slot, item) : (costume[slot] ?? [])));
    }

    return wanted
        .flatMap(([part, color]) => parts[part]?.[body]?.[colors[color] ?? color] ?? [])
        .map(([z, file], i) => [z, i, file] as const)
        .sort((x, y) => x[0] - y[0] || x[1] - y[1])
        .map(([, , file]) => `/avatar/lpc/${file}`);
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
