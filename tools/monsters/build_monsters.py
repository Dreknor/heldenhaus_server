#!/usr/bin/env python3
"""Monster-Bilder für die Quest-Bosse aus Microsoft Fluent Emoji (3D, MIT) erzeugen.

    git clone --depth 1 --filter=blob:none --sparse https://github.com/microsoft/fluentui-emoji.git
    python3 tools/monsters/build_monsters.py <fluentui-emoji-klon>

Schreibt public/images/monsters/<form>.webp (256 px) je Körperform aus database/content/quests.json („art.body“)
plus Krone, Staubwolke und Hydra-Kopf. MonsterArt.vue färbt die Bilder im Browser genau in der Boss-Farbe.
"""
import subprocess
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'public/images/monsters'
SIZE = 256

MONSTERS = {
    'blob': 'Alien monster', 'round': 'Alien', 'golem': 'Moai', 'ghost': 'Ghost', 'serpent': 'Snake', 'octopus': 'Octopus',
    'dragon': 'Dragon', 'troll': 'Troll', 'king': 'Ogre', 'crown': 'Crown', 'dust': 'Cloud', 'heads': 'Dragon face',
}


def main(clone: Path) -> None:
    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', '--no-cone', *[f'/assets/{f}/' for f in sorted(set(MONSTERS.values()))]], check=True)
    OUT.mkdir(parents=True, exist_ok=True)
    for key, folder in MONSTERS.items():
        base = clone / 'assets' / folder
        pngs = sorted((base / '3D').glob('*.png')) or sorted(base.glob('Default/3D/*.png'))
        if not pngs:
            sys.exit(f'Kein 3D-Bild in {folder}')
        img = Image.open(pngs[0]).convert('RGBA')
        img.thumbnail((SIZE, SIZE), Image.LANCZOS)
        img.save(OUT / f'{key}.webp', 'WEBP', quality=88, method=6)
    print(f'{len(MONSTERS)} Monster-Bilder nach {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
