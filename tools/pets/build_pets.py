#!/usr/bin/env python3
"""Haustier-Bilder aus Microsoft Fluent Emoji (3D, MIT) erzeugen.

    git clone --depth 1 --filter=blob:none --sparse https://github.com/microsoft/fluentui-emoji.git
    python3 tools/pets/build_pets.py <fluentui-emoji-klon>

Für jede Tierart aus database/content/items.json (egg_<art>) wird public/images/pets/<art>.webp
(256 px, transparent) geschrieben. Die API liefert den Pfad als art_url am Haustier.
"""
import json
import subprocess
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'public/images/pets'
SIZE = 256

# Tierart → Ordner in fluentui-emoji/assets
FOLDERS = {
    'wolf': 'Wolf', 'dragon': 'Dragon', 'cat': 'Cat', 'owl': 'Owl', 'fox': 'Fox', 'bunny': 'Rabbit', 'turtle': 'Turtle',
    'hedgehog': 'Hedgehog', 'penguin': 'Penguin', 'unicorn': 'Unicorn', 'phoenix': 'Phoenix bird', 'bat': 'Bat',
    'reindeer': 'Deer', 'chick': 'Baby chick', 'dog': 'Dog', 'hamster': 'Hamster', 'mouse': 'Mouse', 'duck': 'Duck',
    'frog': 'Frog', 'pig': 'Pig', 'sheep': 'Ewe', 'cow': 'Cow', 'goat': 'Goat', 'squirrel': 'Chipmunk', 'snail': 'Snail',
    'ladybug': 'Lady beetle', 'horse': 'Horse', 'parrot': 'Parrot', 'otter': 'Otter', 'raccoon': 'Raccoon',
    'beaver': 'Beaver', 'badger': 'Badger', 'koala': 'Koala', 'panda': 'Panda', 'sloth': 'Sloth', 'butterfly': 'Butterfly',
    'bee': 'Honeybee', 'swan': 'Swan', 'flamingo': 'Flamingo', 'seal': 'Seal', 'octopus': 'Octopus', 'crab': 'Crab',
    'llama': 'Llama', 'kangaroo': 'Kangaroo', 'monkey': 'Monkey', 'bear': 'Bear', 'moose': 'Moose', 'zebra': 'Zebra',
    'poodle': 'Poodle', 'eagle': 'Eagle', 'dolphin': 'Dolphin', 'giraffe': 'Giraffe', 'elephant': 'Elephant',
    'hippo': 'Hippopotamus', 'lion': 'Lion', 'tiger': 'Tiger', 'polar_bear': 'Polar bear', 'peacock': 'Peacock',
    'crocodile': 'Crocodile', 'jellyfish': 'Jellyfish', 'whale': 'Spouting whale', 'trex': 'T-rex', 'sauropod': 'Sauropod',
    'mammoth': 'Mammoth', 'dodo': 'Dodo', 'black_cat': 'Black cat', 'turkey': 'Turkey', 'snowman': 'Snowman',
    'tropical_fish': 'Tropical fish', 'ram': 'Ram',
}


def main(clone: Path) -> None:
    items = json.loads((ROOT / 'database/content/items.json').read_text())
    species = [i['key'][4:] for i in items if i['type'] == 'egg']
    missing = [s for s in species if s not in FOLDERS]
    if missing:
        sys.exit(f'Ohne Fluent-Zuordnung: {missing}')

    subprocess.run(['git', '-C', str(clone), 'sparse-checkout', 'set', *[f'assets/{FOLDERS[s]}/3D' for s in species]], check=True)
    OUT.mkdir(parents=True, exist_ok=True)
    for s in species:
        pngs = sorted((clone / 'assets' / FOLDERS[s] / '3D').glob('*.png'))
        if not pngs:
            sys.exit(f'Kein 3D-Bild für {s} ({FOLDERS[s]})')
        img = Image.open(pngs[0]).convert('RGBA')
        img.thumbnail((SIZE, SIZE), Image.LANCZOS)
        img.save(OUT / f'{s}.webp', 'WEBP', quality=88, method=6)
    print(f'{len(species)} Haustier-Bilder nach {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(Path(sys.argv[1]).resolve())
