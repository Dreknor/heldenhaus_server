#!/usr/bin/env python3
"""Emoji-Bilder aus Microsoft Fluent Emoji (3D, MIT) für alle Spielinhalte erzeugen.

    git clone --depth 1 --filter=blob:none --sparse https://github.com/microsoft/fluentui-emoji.git
    python3 tools/emoji/build_emoji.py <fluentui-emoji-klon>

Sammelt jedes "icon" aus database/content/*.json (Items, Quests, Events, Bauprojekte, Garten …) und schreibt
public/images/emoji/<codepoints>.webp (128 px, transparent), z. B. 1f9ea.webp für 🧪. Variantenselektoren (FE0F)
zählen nicht mit. public/images/emoji/index.json listet alle vorhandenen Codes, damit Web und App ohne
Fehlanfragen wissen, wofür es ein Bild gibt; für alles andere bleibt das Text-Emoji.
"""
import json
import subprocess
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'public/images/emoji'
SIZE = 128


def code(glyph: str) -> str:
    return '-'.join(f'{ord(c):x}' for c in glyph if ord(c) != 0xFE0F)


def icons(node, found: set) -> None:
    if isinstance(node, dict):
        for k, v in node.items():
            if k == 'icon' and isinstance(v, str) and v and not v.isascii():
                found.add(v)
            else:
                icons(v, found)
    elif isinstance(node, list):
        for v in node:
            icons(v, found)


def main(clone: Path) -> None:
    found: set[str] = set()
    for f in sorted((ROOT / 'database/content').glob('*.json')):
        icons(json.loads(f.read_text()), found)

    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', '--no-cone', '/assets/*/metadata.json'], check=True)
    folders = {}
    for meta in (clone / 'assets').glob('*/metadata.json'):
        m = json.loads(meta.read_text())
        folders[code(m['glyph'])] = meta.parent.name

    wanted = {code(g): g for g in found}
    missing = sorted(g for c, g in wanted.items() if c not in folders)
    if missing:
        print('Ohne Fluent-Bild (bleibt Text-Emoji):', ' '.join(missing))
    have = sorted(c for c in wanted if c in folders)
    patterns = [f'/assets/{folders[c]}/3D/*' for c in have] + [f'/assets/{folders[c]}/Default/3D/*' for c in have]
    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', '--no-cone', '/assets/*/metadata.json', *patterns], check=True)

    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob('*.webp'):
        old.unlink()
    written = []
    for c in have:
        base = clone / 'assets' / folders[c]
        pngs = sorted((base / '3D').glob('*.png')) or sorted((base / 'Default' / '3D').glob('*.png'))
        if not pngs:
            print(f'Kein 3D-Bild für {wanted[c]} ({folders[c]})')
            continue
        img = Image.open(pngs[0]).convert('RGBA')
        img.thumbnail((SIZE, SIZE), Image.LANCZOS)
        img.save(OUT / f'{c}.webp', 'WEBP', quality=86, method=6)
        written.append(c)
    (OUT / 'index.json').write_text(json.dumps(written) + '\n')
    print(f'{len(written)} Emoji-Bilder nach {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
