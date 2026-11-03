#!/usr/bin/env python3
"""3D-Bilder für alle Emojis erzeugen, die Heldenhaus als Symbole verwendet (Fluent Emoji, MIT).

    git clone --depth 1 --filter=blob:none --sparse https://github.com/microsoft/fluentui-emoji.git
    python3 tools/emoji/build_emoji.py <fluentui-emoji-klon>

Durchsucht Spielinhalte (database/content), Konfiguration, Server- und Frontend-Code nach Emojis und schreibt
für jedes gefundene public/images/emoji/<unicode>.webp (96 px) sowie resources/js/lib/emoji-art.json
(Zeichen ohne Variantenselektor → Dateiname). Die Komponente Emoji.vue zeigt dann das Bild statt der Systemschrift.
"""
import json
import subprocess
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'public/images/emoji'
MANIFEST = ROOT / 'resources/js/lib/emoji-art.json'
SIZE = 96
SOURCES = [('database/content', '*.json'), ('config', '*.php'), ('app', '**/*.php'), ('resources/js', '**/*.vue'), ('resources/js', '**/*.ts')]


def strip(glyph: str) -> str:
    return glyph.replace('️', '')


def main(clone: Path) -> None:
    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', '--no-cone', '/assets/*/metadata.json'], check=True)
    glyphs = {}
    for meta in (clone / 'assets').glob('*/metadata.json'):
        data = json.loads(meta.read_text(encoding='utf-8'))
        if data.get('glyph') and data.get('unicode'):
            glyphs[strip(data['glyph'])] = (meta.parent.name, data['unicode'].replace(' ', '-'))

    text = strip(''.join(p.read_text(encoding='utf-8', errors='ignore') for base, pat in SOURCES for p in (ROOT / base).glob(pat)))
    # Längere Sequenzen zuerst, damit z. B. „🧑‍🌾“ nicht nur als „🧑“ zählt
    used = [g for g in sorted(glyphs, key=len, reverse=True) if g in text]

    folders = sorted({glyphs[g][0] for g in used})
    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', '--no-cone', '/assets/*/metadata.json', *[f'/assets/{f}/' for f in folders]], check=True)

    OUT.mkdir(parents=True, exist_ok=True)
    manifest = {}
    for g in used:
        folder, code = glyphs[g]
        base = clone / 'assets' / folder
        pngs = sorted((base / '3D').glob('*.png')) or sorted(base.glob('Default/3D/*.png'))
        if not pngs:
            continue
        img = Image.open(pngs[0]).convert('RGBA')
        img.thumbnail((SIZE, SIZE), Image.LANCZOS)
        img.save(OUT / f'{code}.webp', 'WEBP', quality=86, method=6)
        manifest[g] = code
    MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, sort_keys=True) + '\n', encoding='utf-8')
    print(f'{len(manifest)} Emoji-Bilder nach {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
