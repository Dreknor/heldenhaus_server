// 3D-Bilder zu den Emoji-Icons der Spielinhalte (Microsoft Fluent Emoji, MIT, Credits in CREDITS.md).
// Erzeugt von tools/emoji/build_emoji.py; für Emojis ohne Bild bleibt das Text-Emoji.
import available from '../../../public/images/emoji/index.json';

const codes = new Set<string>(available);

export function emojiCode(glyph: string): string {
    return Array.from(glyph)
        .filter((c) => c !== '️')
        .map((c) => c.codePointAt(0)!.toString(16))
        .join('-');
}

/** Bild-URL zu einem Emoji oder null, wenn es keines gibt. */
export function emojiUrl(glyph?: string | null): string | null {
    if (!glyph) return null;
    const code = emojiCode(glyph);
    return codes.has(code) ? `/images/emoji/${code}.webp` : null;
}
